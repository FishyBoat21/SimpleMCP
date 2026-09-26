<?php

declare(strict_types=1);

namespace McpServer\Auth;

use InvalidArgumentException;
use McpServer\UserContext;

/**
 * Self-hosted OAuth 2.1 Authorization Server (Authorization Code + PKCE).
 *
 * Hosts the interactive login/onboarding pages, the token endpoint, dynamic
 * client registration (RFC 7591), and the RFC 8414 / RFC 9728 discovery
 * metadata. Also resolves a bearer token back to a UserContext for the JSON-RPC
 * layer.
 *
 * Clients authenticate at the token endpoint by their registered method:
 * public clients (`none`) via PKCE, confidential clients via
 * `client_secret_basic` (HTTP Basic) or `client_secret_post` (form body).
 *
 * Returns plain `{status, headers, body}` arrays so index.php can emit them.
 */
final class OAuthServer {
    /** App mount prefix (e.g. "/SimpleMCP") when hosted under a virtual directory; '' at the site root. */
    private string $mountPath = '';

    public function __construct(
        private readonly UserStore $users,
        private readonly TokenStore $tokens,
        private readonly ClientStore $clients,
        private readonly array $config,
        private readonly ?PasskeyStore $passkeys = null,
        private readonly ?TwoFactorService $twoFactor = null,
    ) {}

    /** Resolve a Bearer access token to a user, or null when absent/invalid/expired. */
    public function resolveUser(?string $bearerToken): ?UserContext {
        if ($bearerToken === null || $bearerToken === '') {
            return null;
        }
        $row = $this->tokens->findAccessToken($bearerToken);
        if ($row === null) {
            return null;
        }
        // Tokens issued via the "continue anonymously" action on the login page
        // are bound to a reserved identity that maps to the anonymous user, who
        // only sees/calls public tools (no roles/permissions requirements).
        if ((string) $row['username'] === UserStore::ANONYMOUS_USERNAME) {
            return UserContext::anonymous();
        }
        $user = $this->users->getByUsername((string) $row['username']);
        if ($user === null) {
            return null;
        }
        return $this->users->toUserContext($user);
    }

    /**
     * @param array<string, mixed> $server  $_SERVER
     * @param array<string, mixed> $get     $_GET
     * @param string $rawBody               raw request body (form or JSON, per endpoint)
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, array $server, array $get, string $rawBody): array {
        $post = [];
        parse_str($rawBody, $post);
        $this->mountPath = MountPath::from($server);

        return match ($path) {
            '/oauth/authorize' => $method === 'GET' || $method === 'POST'
                ? $this->handleAuthorize($method, $server, $get, $post)
                : $this->methodNotAllowed(),
            '/oauth/passkey/options' => $method === 'POST'
                ? $this->handlePasskeyOptions($server, $post)
                : $this->methodNotAllowed(),
            '/oauth/passkey/verify' => $method === 'POST'
                ? $this->handlePasskeyVerify($server, $post)
                : $this->methodNotAllowed(),
            '/oauth/token' => $method === 'POST'
                ? $this->handleToken($server, $post)
                : $this->methodNotAllowed(),
            '/oauth/register' => $method === 'POST'
                ? $this->handleRegister($server, $rawBody)
                : $this->methodNotAllowed(),
            '/.well-known/oauth-authorization-server' => $method === 'GET'
                ? $this->json(200, $this->authorizationServerMetadata($server))
                : $this->methodNotAllowed(),
            '/.well-known/oauth-protected-resource' => $method === 'GET'
                ? $this->json(200, $this->protectedResourceMetadata($server))
                : $this->methodNotAllowed(),
            default => $this->json(404, ['error' => 'not_found']),
        };
    }

    // ---- authorize ----------------------------------------------------------

    private function handleAuthorize(string $method, array $server, array $get, array $post): array {
        $params = $method === 'POST' ? $post : $get;

        $clientId = (string) ($params['client_id'] ?? '');
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        $challenge = (string) ($params['code_challenge'] ?? '');
        $challengeMethod = (string) ($params['code_challenge_method'] ?? 'S256');
        $state = (string) ($params['state'] ?? '');
        $scope = (string) ($params['scope'] ?? '');

        $client = $this->clients->find($clientId);
        if ($client === null || !in_array($redirectUri, $client['redirect_uris'], true) || !ClientStore::isValidRedirectUri($redirectUri)) {
            return $this->page(400, 'Invalid request', '<p>Invalid <code>client_id</code> or <code>redirect_uri</code>.</p>');
        }

        // Confidential clients may skip PKCE; public clients must use it (S256).
        $confidential = ($client['token_endpoint_auth_method'] ?? 'none') !== 'none';
        if ($challenge === '') {
            if (!$confidential) {
                return $this->page(400, 'Invalid request', '<p>Public clients must send a <code>code_challenge</code>.</p>');
            }
            $challengeMethod = '';
        } elseif (!$this->isValidChallenge($challenge, $challengeMethod)) {
            return $this->page(400, 'Invalid request', '<p>Missing or invalid <code>code_challenge</code>.</p>');
        }

        // 2FA Verification Form Submitted
        if (($params['verify_2fa'] ?? '') === '1') {
            $identifier = (string) ($params['username'] ?? '');
            $otp = trim((string) ($params['otp'] ?? ''));
            $trust = !empty($params['trust_device']);
            $user = $this->users->getByUsername($identifier);

            if ($this->twoFactor !== null) {
                $res = $this->twoFactor->verifyOtp($identifier, $otp);
                if (!$res['success']) {
                    return $this->twoFactorPage($user ?? ['username' => $identifier], $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope, $res['error']);
                }
                if ($trust) {
                    $ua = $server['HTTP_USER_AGENT'] ?? '';
                    $ip = $server['REMOTE_ADDR'] ?? '';
                    $token = $this->twoFactor->trustDevice($identifier, $ua, $ip);
                    $this->twoFactor->setDeviceCookie($token, $this->isHttps($server));
                }
            }

            return $this->issueCodeAndRedirect($user ?? ['username' => $identifier], $clientId, $redirectUri, $challenge, $challengeMethod, $state);
        }

        // 2FA Resend Requested
        if (($params['resend_2fa'] ?? '') === '1') {
            $identifier = (string) ($params['username'] ?? '');
            $user = $this->users->getByUsername($identifier);
            $email = (string) ($user['email'] ?? '');
            if ($this->twoFactor !== null && $email !== '') {
                $ua = $server['HTTP_USER_AGENT'] ?? '';
                $ip = $server['REMOTE_ADDR'] ?? '';
                $this->twoFactor->sendOtp($identifier, $email, $ua, $ip);
            }
            return $this->twoFactorPage($user ?? ['username' => $identifier], $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope, null, 'A new verification code has been sent.');
        }

        // 2FA Set Missing Email
        if (($params['set_email_2fa'] ?? '') === '1') {
            $identifier = (string) ($params['username'] ?? '');
            $email = trim((string) ($params['email'] ?? ''));
            $user = $this->users->getByUsername($identifier);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->setEmailPage($user ?? ['username' => $identifier], $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope, 'Please enter a valid email address.');
            }
            $this->users->updateEmail($identifier, $email);
            $user = $this->users->getByUsername($identifier);
            if ($this->twoFactor !== null) {
                $ua = $server['HTTP_USER_AGENT'] ?? '';
                $ip = $server['REMOTE_ADDR'] ?? '';
                $this->twoFactor->sendOtp($identifier, $email, $ua, $ip);
            }
            return $this->twoFactorPage($user ?? ['username' => $identifier], $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        // Direct GET 2FA step (e.g. redirected from passkey)
        if ($method === 'GET' && ($get['step'] ?? '') === '2fa') {
            $identifier = (string) ($get['username'] ?? '');
            $user = $this->users->getByUsername($identifier);
            if ($user !== null) {
                $email = (string) ($user['email'] ?? '');
                if ($email === '') {
                    return $this->setEmailPage($user, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
                }
                return $this->twoFactorPage($user, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
            }
        }

        if ($method === 'GET') {
            return $this->usernamePage(null, '', $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        // "Continue anonymously": grant the authorization code without a login,
        // bound to the reserved anonymous identity. The client / redirect_uri /
        // PKCE checks above still apply; the resulting token only unlocks public
        // tools (see resolveUser()).
        if (($params['anonymous'] ?? '') === '1') {
            return $this->issueAnonymousCodeAndRedirect($clientId, $redirectUri, $challenge, $challengeMethod, $state);
        }

        // Login form submitted with an onboarding payload.
        if (($params['onboard'] ?? '') === '1') {
            return $this->completeOnboarding($server, $params, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        // Step 1 of the two-step login: only a username was submitted. Route to
        // the password step (active account) or to onboarding (pending account).
        $identifier = (string) ($params['username'] ?? '');
        $password = (string) ($params['password'] ?? '');

        if ($password === '') {
            $row = $this->users->getByUsername($identifier);
            if ($row === null) {
                return $this->usernamePage('No account found for this username.', $identifier, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
            }
            if (($row['password_hash'] ?? null) === null) {
                // Newly added user with no password -> onboard before continuing.
                return $this->onboardingPage($row, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
            }
            return $this->passwordPage(null, $identifier, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        // Step 2: username + password.
        $user = $this->users->authenticate($identifier, $password);
        if ($user === null) {
            return $this->passwordPage('Incorrect password.', $identifier, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        $email = (string) ($user['email'] ?? '');
        if ($email === '') {
            return $this->setEmailPage($user, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
        if ($this->twoFactor !== null && !$this->twoFactor->isTrustedDevice((string) $user['username'], $rawCookie)) {
            $ua = $server['HTTP_USER_AGENT'] ?? '';
            $ip = $server['REMOTE_ADDR'] ?? '';
            $this->twoFactor->sendOtp((string) $user['username'], $email, $ua, $ip);
            return $this->twoFactorPage($user, $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        }

        return $this->issueCodeAndRedirect($user, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
    }

    private function completeOnboarding(array $server, array $params, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope): array {
        $existingUsername = (string) ($params['existing_username'] ?? '');
        $newUsername = (string) ($params['new_username'] ?? '');
        $email = trim((string) ($params['email'] ?? ''));
        $newPassword = (string) ($params['new_password'] ?? '');
        $confirm = (string) ($params['confirm_password'] ?? '');
        $error = '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required for two-factor security.';
        } elseif ($newPassword !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif ($newPassword === '' || strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($existingUsername === '' && $newUsername === '') {
            // Only brand-new users pick a username; pending users keep theirs.
            $error = 'Username is required.';
        } else {
            $user = $this->users->onboardUser($existingUsername !== '' ? $existingUsername : null, $newUsername, $newPassword, '', $email);
            if ($user === null) {
                $error = $existingUsername !== '' ? 'Account is not pending.' : 'Username is already taken.';
            } else {
                if ($this->twoFactor !== null) {
                    $token = $this->twoFactor->trustDevice($user->username, $server['HTTP_USER_AGENT'] ?? '', $server['REMOTE_ADDR'] ?? '');
                    $this->twoFactor->setDeviceCookie($token, $this->isHttps($server));
                }
                $row = $this->users->getByUsername($user->username) ?? [];
                return $this->issueCodeAndRedirect($row, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
            }
        }

        // Re-render. Pending users keep their provisioned username.
        $row = $this->users->getByUsername($existingUsername);
        return $this->onboardingPage($row !== null ? $row : ['username' => $existingUsername !== '' ? $existingUsername : $newUsername, 'email' => $email], $clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope, $error);
    }

    /**
     * Issue an authorization code bound to the reserved anonymous identity, so
     * the client can complete the token exchange without an account.
     */
    private function issueAnonymousCodeAndRedirect(string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state): array {
        return $this->issueCodeAndRedirect(
            ['username' => UserStore::ANONYMOUS_USERNAME],
            $clientId, $redirectUri, $challenge, $challengeMethod, $state,
        );
    }

    /** @param array<string, mixed> $user */
    private function issueCodeAndRedirect(array $user, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state): array {
        DebugLog::write("AUTH code issued client={$clientId} user=" . (string) ($user['username'] ?? '?'));
        $code = $this->tokens->createAuthCode(
            (string) $user['username'],
            $clientId,
            $redirectUri,
            $challenge,
            $challengeMethod,
            (int) ($this->config['auth_code_ttl'] ?? 600),
        );
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . http_build_query(['code' => $code, 'state' => $state]);
        return [
            'status' => 302,
            'headers' => ['Location' => $location, 'Cache-Control' => 'no-store'],
            'body' => '',
        ];
    }

    // ---- passkey oauth ------------------------------------------------------

    private function handlePasskeyOptions(array $server, array $post): array {
        $rpId = $this->rpId($server);
        $challenge = random_bytes(32);
        $_SESSION['passkey_oauth_challenge'] = $challenge;

        $username = trim((string) ($post['username'] ?? ''));
        $allowCreds = [];
        if ($this->passkeys !== null && $username !== '') {
            $userCreds = $this->passkeys->findByUsername($username);
            $allowCreds = array_column($userCreds, 'id');
        }

        $options = WebAuthn::createAuthenticationOptions($rpId, $challenge, $allowCreds);
        return $this->json(200, $options);
    }

    private function handlePasskeyVerify(array $server, array $post): array {
        if ($this->passkeys === null) {
            return $this->json(400, ['error' => 'Passkey authentication is not enabled.']);
        }

        $expectedChallenge = $_SESSION['passkey_oauth_challenge'] ?? null;
        if (!is_string($expectedChallenge) || $expectedChallenge === '') {
            return $this->json(400, ['error' => 'Authentication challenge expired or missing. Please try again.']);
        }
        unset($_SESSION['passkey_oauth_challenge']);

        $credId = (string) ($post['id'] ?? '');
        $clientDataJson = (string) ($post['clientDataJSON'] ?? '');
        $authData = (string) ($post['authenticatorData'] ?? '');
        $signature = (string) ($post['signature'] ?? '');

        if ($credId === '' || $clientDataJson === '' || $authData === '' || $signature === '') {
            return $this->json(400, ['error' => 'Missing required WebAuthn assertion fields.']);
        }

        $cred = $this->passkeys->findById($credId);
        if ($cred === null) {
            return $this->json(400, ['error' => 'Passkey credential not recognized.']);
        }

        $origin = $this->origin($server);
        $rpId = $this->rpId($server);

        try {
            $newSignCount = WebAuthn::verifyAuthentication(
                $clientDataJson,
                $authData,
                $signature,
                (string) $cred['public_key'],
                (int) $cred['sign_count'],
                $expectedChallenge,
                $origin,
                $rpId
            );
            $this->passkeys->updateSignCount($credId, $newSignCount);
        } catch (\Throwable $e) {
            return $this->json(400, ['error' => 'Passkey verification failed: ' . $e->getMessage()]);
        }

        $user = $this->users->getByUsername((string) $cred['username']);
        if ($user === null) {
            return $this->json(400, ['error' => 'User account not found.']);
        }

        // Validate OAuth parameters
        $clientId = (string) ($post['client_id'] ?? '');
        $redirectUri = (string) ($post['redirect_uri'] ?? '');
        $challenge = (string) ($post['code_challenge'] ?? '');
        $challengeMethod = (string) ($post['code_challenge_method'] ?? 'S256');
        $state = (string) ($post['state'] ?? '');
        $scope = (string) ($post['scope'] ?? '');

        $client = $this->clients->find($clientId);
        if ($client === null || !in_array($redirectUri, $client['redirect_uris'], true) || !ClientStore::isValidRedirectUri($redirectUri)) {
            return $this->json(400, ['error' => 'Invalid client_id or redirect_uri.']);
        }

        $username = (string) $user['username'];
        $email = (string) ($user['email'] ?? '');

        $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
        if ($this->twoFactor !== null && !$this->twoFactor->isTrustedDevice($username, $rawCookie)) {
            $ua = $server['HTTP_USER_AGENT'] ?? '';
            $ip = $server['REMOTE_ADDR'] ?? '';
            if ($email !== '') {
                $this->twoFactor->sendOtp($username, $email, $ua, $ip);
            }
            $twoFactorUrl = '/oauth/authorize?' . http_build_query([
                'step' => '2fa',
                'username' => $username,
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $challenge,
                'code_challenge_method' => $challengeMethod,
                'state' => $state,
                'scope' => $scope,
            ]);
            return $this->json(200, ['redirect_url' => $twoFactorUrl]);
        }

        $code = $this->tokens->createAuthCode(
            (string) $user['username'],
            $clientId,
            $redirectUri,
            $challenge,
            $challengeMethod,
            (int) ($this->config['auth_code_ttl'] ?? 600),
        );
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $location = $redirectUri . $sep . http_build_query(['code' => $code, 'state' => $state]);

        return $this->json(200, ['redirect_url' => $location]);
    }

    // ---- token --------------------------------------------------------------

    private function handleToken(array $server, array $post): array {
        $grantType = (string) ($post['grant_type'] ?? '');
        $basic = $this->basicCredentials($server);
        $clientId = $basic !== null ? $basic[0] : (string) ($post['client_id'] ?? '?');

        $response = match ($grantType) {
            'authorization_code' => $this->grantAuthorizationCode($server, $post),
            'refresh_token' => $this->grantRefreshToken($server, $post),
            default => $this->json(400, ['error' => 'unsupported_grant_type', 'error_description' => 'Supported grant types: authorization_code, refresh_token']),
        };

        $body = json_decode($response['body'], true);
        $error = is_array($body) ? (string) ($body['error'] ?? 'ok') : '?';
        DebugLog::write("TOKEN grant={$grantType} client={$clientId} => {$response['status']} {$error}");
        return $response;
    }

    private function grantAuthorizationCode(array $server, array $post): array {
        $code = (string) ($post['code'] ?? '');
        $redirectUri = (string) ($post['redirect_uri'] ?? '');
        $verifier = (string) ($post['code_verifier'] ?? '');

        if ($code === '' || $redirectUri === '') {
            return $this->json(400, ['error' => 'invalid_request', 'error_description' => 'Missing code or redirect_uri.']);
        }

        $client = $this->authenticateClient($server, $post);
        if ($client === null) {
            return $this->json(401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']);
        }
        $clientId = $client['client_id'];

        $redeemed = $this->tokens->consumeAuthCode($code, ['client_id' => $clientId, 'redirect_uri' => $redirectUri]);
        if ($redeemed === null) {
            return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid, expired or already-used authorization code.']);
        }

        // PKCE is verified only when the code carried a challenge (confidential
        // clients that skipped it at authorize time are not subject to it).
        $expected = $redeemed['code_challenge'];
        if ($expected !== '') {
            if (!$this->isValidVerifier($verifier)) {
                return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.']);
            }
            if ($redeemed['code_challenge_method'] === 'S256') {
                $ok = hash_equals($expected, $this->base64UrlEncode(hash('sha256', $verifier, true)));
            } elseif (($this->config['allow_plain_pkce'] ?? false) === true) {
                $ok = hash_equals($expected, $verifier);
            } else {
                $ok = false;
            }
            if (!$ok) {
                return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed.']);
            }
        }

        return $this->issueTokenResponse($redeemed['username'], $clientId);
    }

    private function grantRefreshToken(array $server, array $post): array {
        $refreshToken = (string) ($post['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return $this->json(400, ['error' => 'invalid_request', 'error_description' => 'Missing refresh_token.']);
        }

        $client = $this->authenticateClient($server, $post);
        if ($client === null) {
            return $this->json(401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']);
        }

        $rotated = $this->tokens->rotateRefreshToken(
            $refreshToken,
            (int) ($this->config['access_token_ttl'] ?? 300),
            (int) ($this->config['refresh_token_ttl'] ?? 2592000),
        );
        if ($rotated === null) {
            return $this->json(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid or expired refresh token.']);
        }

        return $this->json(200, [
            'access_token' => $rotated['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) ($this->config['access_token_ttl'] ?? 300),
            'refresh_token' => $rotated['refresh_token'],
        ]);
    }

    /**
     * Authenticate the client at the token endpoint using its registered method.
     *
     * @return array<string, mixed>|null the authenticated client, or null.
     */
    private function authenticateClient(array $server, array $post): ?array {
        // client_secret_basic: HTTP Basic credentials.
        $basic = $this->basicCredentials($server);
        if ($basic !== null) {
            [$id, $secret] = $basic;
            $client = $this->clients->find($id);
            if ($client !== null
                && ($client['token_endpoint_auth_method'] ?? '') === 'client_secret_basic'
                && $this->clients->verifySecret($id, $secret)) {
                return $client;
            }
            return null;
        }

        $postId = (string) ($post['client_id'] ?? '');
        $postSecret = (string) ($post['client_secret'] ?? '');

        // client_secret_post: client_id + client_secret in the form body.
        if ($postId !== '' && $postSecret !== '') {
            $client = $this->clients->find($postId);
            if ($client !== null
                && ($client['token_endpoint_auth_method'] ?? '') === 'client_secret_post'
                && $this->clients->verifySecret($postId, $postSecret)) {
                return $client;
            }
            return null;
        }

        // Public client (auth method `none`): a bare client_id, PKCE-secured.
        if ($postId !== '') {
            $client = $this->clients->find($postId);
            if ($client !== null && ($client['token_endpoint_auth_method'] ?? 'none') === 'none') {
                return $client;
            }
        }
        return null;
    }

    private function issueTokenResponse(string $username, string $clientId): array {
        $accessTtl = (int) ($this->config['access_token_ttl'] ?? 300);
        $refreshTtl = (int) ($this->config['refresh_token_ttl'] ?? 2592000);
        return $this->json(200, [
            'access_token' => $this->tokens->createAccessToken($username, $clientId, $accessTtl),
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_token' => $this->tokens->createRefreshToken($username, $clientId, $refreshTtl),
        ]);
    }

    // ---- dynamic client registration (RFC 7591) ------------------------------

    private function handleRegister(array $server, string $rawBody): array {
        $expected = $this->config['registration_access_token'] ?? null;
        if (is_string($expected) && $expected !== '') {
            $auth = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            $token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
            if ($token === null || !hash_equals($expected, $token)) {
                return $this->json(401, ['error' => 'invalid_token', 'error_description' => 'A valid initial access token is required to register clients.']);
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Request body must be a JSON object.']);
        }

        try {
            $client = $this->clients->register($payload);
        } catch (InvalidArgumentException $e) {
            return $this->json(400, ['error' => 'invalid_client_metadata', 'error_description' => $e->getMessage()]);
        }

        return $this->json(201, $client);
    }

    // ---- discovery -----------------------------------------------------------

    /** @param array<string, mixed> $server */
    private function authorizationServerMetadata(array $server): array {
        $origin = $this->origin($server);
        return [
            'issuer' => $origin,
            'authorization_endpoint' => $origin . '/oauth/authorize',
            'token_endpoint' => $origin . '/oauth/token',
            'registration_endpoint' => $origin . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            // client_secret_basic is deliberately NOT advertised: Cherry Studio
            // (and other MCP clients) fail the OAuth handshake when it is offered.
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [],
        ];
    }

    /** @param array<string, mixed> $server */
    private function protectedResourceMetadata(array $server): array {
        $origin = $this->origin($server);
        return [
            'resource' => $origin,
            'authorization_servers' => [$origin],
            'scopes_supported' => [],
        ];
    }

    /** @param array<string, mixed> $server */
    public function origin(array $server): string {
        $canonical = $this->config['issuer'] ?? null;
        if (is_string($canonical) && $canonical !== '') {
            return rtrim($canonical, '/');
        }

        return MountPath::origin($server);
    }

    /** @param array<string, mixed> $server */
    public function rpId(array $server): string {
        $host = parse_url($this->origin($server), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'localhost';
        if ($host === '127.0.0.1' || $host === '::1') {
            return 'localhost';
        }
        return $host;
    }

    // ---- helpers ------------------------------------------------------------

    /** @return array{0: string, 1: string}|null */
    private function basicCredentials(array $server): ?array {
        $user = $server['PHP_AUTH_USER'] ?? '';
        $pass = $server['PHP_AUTH_PW'] ?? '';
        if ($user !== '' && $pass !== '') {
            return [$user, $pass];
        }
        $auth = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
            $decoded = base64_decode(trim($m[1]));
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$u, $p] = explode(':', $decoded, 2);
                return [$u, $p];
            }
        }
        return null;
    }

    private function isValidChallenge(string $challenge, string $method): bool {
        if ($challenge === '') {
            return false;
        }
        if ($method === 'S256') {
            return preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $challenge) === 1;
        }
        // RFC 7636 allows "plain", but S256 is mandatory for MCP clients.
        return $method === 'plain' && ($this->config['allow_plain_pkce'] ?? false) === true;
    }

    private function isValidVerifier(string $verifier): bool {
        return strlen($verifier) >= 43
            && strlen($verifier) <= 128
            && preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1;
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ---- rendering ----------------------------------------------------------

    /** Prefix an app-relative path with the mount prefix ("/oauth/authorize" => "/SimpleMCP/oauth/authorize"). */
    private function appPath(string $path): string {
        return $this->mountPath . $path;
    }

    private function usernamePage(?string $error, string $identifier, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope): array {
        $body = '
        <p class="hint">Log in to grant <strong>' . htmlspecialchars($clientId) . '</strong> access to this MCP server.</p>' .
        $this->errorHtml($error) . '
        <div id="passkey-error" class="error" style="display:none;"></div>
        <form method="post" action="' . $this->appPath('/oauth/authorize') . '">
            ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope) . '
            <label>Username <input type="text" name="username" value="' . htmlspecialchars($identifier, ENT_QUOTES) . '" required autofocus autocomplete="username webauthn"></label>
            <button type="submit">Continue with Password</button>
            <button type="button" id="passkey-btn" style="background:#0f766e;margin-top:.6rem;">🔑 Sign in with Passkey</button>
        </form>
        <p class="hint">New user? <a href="' . $this->appPath('/account/onboard') . '">Set up your account</a>.</p>' .
        $this->anonymousForm($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope) .
        $this->passkeyScript();
        return $this->page(200, 'Log in', $body);
    }

    private function passwordPage(?string $error, string $identifier, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope): array {
        $body = '
        <p class="hint">Log in to grant <strong>' . htmlspecialchars($clientId) . '</strong> access to this MCP server.</p>' .
        $this->errorHtml($error) . '
        <div id="passkey-error" class="error" style="display:none;"></div>
        <form method="post" action="' . $this->appPath('/oauth/authorize') . '">
            ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope)
            . $this->hidden('username', $identifier) . '
            <p class="hint">Logging in as <strong>' . htmlspecialchars($identifier) . '</strong>.</p>
            <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
            <button type="submit">Log in</button>
            <button type="button" id="passkey-btn" style="background:#0f766e;margin-top:.6rem;">🔑 Sign in with Passkey</button>
        </form>
        <p class="hint"><a href="' . $this->appPath('/oauth/authorize') . '?' . htmlspecialchars(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => $challengeMethod,
            'state' => $state,
            'scope' => $scope,
        ]), ENT_QUOTES) . '">Use a different username</a>.</p>' .
        $this->anonymousForm($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope) .
        $this->passkeyScript();
        return $this->page(200, 'Log in', $body);
    }

    private function passkeyScript(): string {
        return '<script>
        (function() {
            if (window.location.hostname === "127.0.0.1" || window.location.hostname === "::1") {
                window.location.hostname = "localhost";
                return;
            }

            function b64ToBuf(b64) {
                var pad = "=".repeat((4 - b64.length % 4) % 4);
                var base64 = (b64 + pad).replace(/-/g, "+").replace(/_/g, "/");
                var raw = atob(base64);
                var buf = new Uint8Array(raw.length);
                for (var i = 0; i < raw.length; i++) buf[i] = raw.charCodeAt(i);
                return buf.buffer;
            }
            function bufToB64(buf) {
                var bytes = new Uint8Array(buf);
                var bin = "";
                for (var i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
                return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
            }

            var activeAbort = null;

            async function loginWithPasskey(conditional) {
                var errDiv = document.getElementById("passkey-error");
                if (errDiv) errDiv.style.display = "none";

                if (window.location.hostname === "127.0.0.1" || window.location.hostname === "::1") {
                    window.location.hostname = "localhost";
                    return;
                }

                if (/^(\d{1,3}\.){3}\d{1,3}$/.test(window.location.hostname)) {
                    if (!conditional && errDiv) {
                        errDiv.textContent = "WebAuthn passkeys cannot be used on an IP address (" + window.location.hostname + "). Please access using http://localhost:" + (window.location.port || "8000") + " or a domain name.";
                        errDiv.style.display = "block";
                    }
                    return;
                }

                // If a prior WebAuthn request (e.g. conditional autofill) is pending, abort it before launching a new one!
                if (activeAbort) {
                    try {
                        activeAbort.abort();
                    } catch (_) {}
                    activeAbort = null;
                }

                var abortCtrl = new AbortController();
                activeAbort = abortCtrl;

                try {
                    if (!window.PublicKeyCredential) {
                        if (!conditional && errDiv) {
                            errDiv.textContent = "Passkeys are not supported in this browser.";
                            errDiv.style.display = "block";
                        }
                        return;
                    }

                    var usernameInput = document.querySelector(\'input[name="username"]\');
                    var username = usernameInput ? usernameInput.value.trim() : "";

                    var optRes = await fetch("' . $this->appPath('/oauth/passkey/options') . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({ username: username })
                    });
                    var opt = await optRes.json();
                    if (!optRes.ok) throw new Error(opt.error || "Failed to get passkey options");

                    opt.challenge = b64ToBuf(opt.challenge);
                    if (opt.allowCredentials) {
                        opt.allowCredentials = opt.allowCredentials.map(function(c) {
                            return Object.assign({}, c, { id: b64ToBuf(c.id) });
                        });
                    }

                    var req = {
                        publicKey: opt,
                        signal: abortCtrl.signal
                    };
                    if (conditional) req.mediation = "conditional";

                    var cred = await navigator.credentials.get(req);
                    activeAbort = null;
                    if (!cred) return;

                    var form = document.querySelector(\'form[action*="/oauth/authorize"]\');
                    var payload = {
                        id: cred.id,
                        clientDataJSON: bufToB64(cred.response.clientDataJSON),
                        authenticatorData: bufToB64(cred.response.authenticatorData),
                        signature: bufToB64(cred.response.signature),
                        client_id: form.querySelector(\'input[name="client_id"]\').value,
                        redirect_uri: form.querySelector(\'input[name="redirect_uri"]\').value,
                        code_challenge: form.querySelector(\'input[name="code_challenge"]\').value,
                        code_challenge_method: form.querySelector(\'input[name="code_challenge_method"]\').value,
                        state: form.querySelector(\'input[name="state"]\').value,
                        scope: form.querySelector(\'input[name="scope"]\').value
                    };

                    var verifyRes = await fetch("' . $this->appPath('/oauth/passkey/verify') . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams(payload)
                    });

                    var verifyData = await verifyRes.json();
                    if (verifyRes.ok && verifyData.redirect_url) {
                        window.location.href = verifyData.redirect_url;
                    } else {
                        throw new Error(verifyData.error || "Passkey verification failed");
                    }
                } catch (e) {
                    if (e.name === "AbortError" || conditional) return;
                    if (errDiv) {
                        errDiv.textContent = e.name === "NotAllowedError" ? "Passkey sign-in was canceled." : e.message;
                        errDiv.style.display = "block";
                    }
                } finally {
                    if (activeAbort === abortCtrl) {
                        activeAbort = null;
                    }
                }
            }

            var btn = document.getElementById("passkey-btn");
            if (btn) {
                btn.addEventListener("click", function() { loginWithPasskey(false); });
            }

            if (window.PublicKeyCredential && PublicKeyCredential.isConditionalMediationAvailable) {
                PublicKeyCredential.isConditionalMediationAvailable().then(function(avail) {
                    if (avail) loginWithPasskey(true);
                });
            }
        })();
        </script>';
    }

    /** Hidden OAuth parameters carried through each step of the login flow. */
    private function oauthFields(string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope): string {
        return $this->hidden('client_id', $clientId) . $this->hidden('redirect_uri', $redirectUri)
            . $this->hidden('code_challenge', $challenge) . $this->hidden('code_challenge_method', $challengeMethod)
            . $this->hidden('state', $state) . $this->hidden('scope', $scope);
    }

    /**
     * The "continue anonymously" action shown alongside each login step: posts
     * the OAuth parameters back with `anonymous=1` so handleAuthorize() issues
     * the grant without an account. The token maps to the anonymous user, who
     * only sees/calls public tools.
     */
    private function anonymousForm(string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope): string {
        return '<div class="anon">
        <p class="hint">No account? Continue <strong>anonymously</strong> — you will only be able to use public tools.</p>
        <form method="post" action="' . $this->appPath('/oauth/authorize') . '">
            ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope)
            . $this->hidden('anonymous', '1') . '
            <button type="submit">Continue anonymously</button>
        </form>
        </div>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function onboardingPage(array $row, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope, string $error = ''): array {
        $username = (string) ($row['username'] ?? '');
        $email = (string) ($row['email'] ?? '');
        // Pending users keep their provisioned username (read-only display).
        $usernameField = $username !== ''
            ? '<p class="hint">Username <strong>' . htmlspecialchars($username, ENT_QUOTES) . '</strong> — cannot be changed.</p>'
            : '<label>Username <input type="text" name="new_username" required autofocus></label>';
        $body = '
        <p class="hint">Your account has not been set up yet. Choose a password to finish creating it.</p>' .
        $this->errorHtml($error) . '
        <form method="post" action="' . $this->appPath('/oauth/authorize') . '">
            ' . $this->hidden('onboard', '1') . $this->hidden('existing_username', $username)
            . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope) . '
            ' . $usernameField . '
            <label>Email address <input type="email" name="email" value="' . htmlspecialchars($email, ENT_QUOTES) . '" required placeholder="you@example.com"></label>
            <p class="hint" style="margin-top:-0.5rem;margin-bottom:0.8rem;">Required for two-factor verification on new devices.</p>
            <label>Password <input type="password" name="new_password" minlength="8" required></label>
            <label>Confirm password <input type="password" name="confirm_password" minlength="8" required></label>
            <button type="submit">Set up my account</button>
        </form>' .
        $this->anonymousForm($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope);
        return $this->page(200, 'Set up your account', $body);
    }

    private function twoFactorPage(array $user, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope, ?string $error = null, ?string $msg = null): array {
        $username = (string) ($user['username'] ?? '');
        $email = (string) ($user['email'] ?? '');
        $maskedEmail = $this->twoFactor !== null ? $this->twoFactor->maskEmail($email) : $email;

        $msgHtml = $msg !== null && $msg !== '' ? '<div class="ok" style="color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;padding:.5rem .75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem;">' . htmlspecialchars($msg) . '</div>' : '';

        $body = $msgHtml . $this->errorHtml($error) . '
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:1rem;margin-bottom:1.2rem;">
            <p style="margin:0 0 0.5rem;font-weight:600;color:#1e40af;">🛡️ New Device Detected</p>
            <p style="margin:0;font-size:0.9rem;color:#1e3a8a;">Logging in as <strong>' . htmlspecialchars($username) . '</strong>. A 6-digit verification code has been sent to <strong>' . htmlspecialchars($maskedEmail) . '</strong>.</p>
        </div>
        <form method="post" action="/oauth/authorize">
            ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope)
            . $this->hidden('verify_2fa', '1')
            . $this->hidden('username', $username) . '
            <label>6-Digit Verification Code
                <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="000000" style="letter-spacing:6px;font-size:1.5rem;text-align:center;font-weight:bold;">
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:1rem 0;font-size:0.9rem;cursor:pointer;">
                <input type="checkbox" name="trust_device" value="1" checked style="width:auto;margin:0;"> Trust this device for 90 days
            </label>
            <button type="submit">Verify & Authorize</button>
        </form>
        <div style="margin-top:1.2rem;display:flex;justify-content:space-between;align-items:center;">
            <form method="post" action="/oauth/authorize" style="margin:0;">
                ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope)
                . $this->hidden('resend_2fa', '1')
                . $this->hidden('username', $username) . '
                <button type="submit" style="background:#475569;width:auto;padding:0.4rem 0.8rem;font-size:0.85rem;">Resend Code</button>
            </form>
            <a href="/oauth/authorize?' . htmlspecialchars(http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $challenge,
                'code_challenge_method' => $challengeMethod,
                'state' => $state,
                'scope' => $scope,
            ]), ENT_QUOTES) . '" style="font-size:0.9rem;">Cancel</a>
        </div>';

        return $this->page(200, 'Two-Factor Verification', $body);
    }

    private function setEmailPage(array $user, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state, string $scope, ?string $error = null): array {
        $username = (string) ($user['username'] ?? '');

        $body = $this->errorHtml($error) . '
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:1rem;margin-bottom:1.2rem;">
            <p style="margin:0 0 0.4rem;font-weight:600;color:#92400e;">✉️ Email Address Required</p>
            <p style="margin:0;font-size:0.9rem;color:#78350f;">Every user must have an email set for two-factor authentication on new devices. Please enter your email address to continue.</p>
        </div>
        <form method="post" action="/oauth/authorize">
            ' . $this->oauthFields($clientId, $redirectUri, $challenge, $challengeMethod, $state, $scope)
            . $this->hidden('set_email_2fa', '1')
            . $this->hidden('username', $username) . '
            <label>Email address
                <input type="email" name="email" required autofocus autocomplete="email" placeholder="you@example.com">
            </label>
            <button type="submit">Save Email & Send Code</button>
        </form>
        <p class="hint" style="margin-top:1.2rem;"><a href="/oauth/authorize?' . htmlspecialchars(http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => $challengeMethod,
            'state' => $state,
            'scope' => $scope,
        ]), ENT_QUOTES) . '">Cancel</a></p>';

        return $this->page(200, 'Set Email Address', $body);
    }

    private function isHttps(array $server): bool {
        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (isset($server['HTTP_X_FORWARDED_PROTO']) && $server['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function json(int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function page(int $status, string $title, string $body): array {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($title) . '</title><style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:26rem;margin:4rem auto;padding:0 1rem;color:#1e293b;line-height:1.5}'
            . 'h1{font-size:1.4rem;color:#0f172a}.hint{color:#64748b;font-size:.9rem}.error{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:.5rem .75rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem}'
            . 'label{display:block;margin-bottom:.9rem;font-size:.9rem;font-weight:500}input{width:100%;box-sizing:border-box;padding:.6rem .75rem;margin-top:.3rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.95rem}'
            . 'input:focus{outline:2px solid #2563eb;outline-offset:1px;border-color:#2563eb}'
            . 'button{width:100%;padding:.65rem;background:#2563eb;color:#fff;border:0;border-radius:6px;font-size:.95rem;font-weight:500;cursor:pointer}'
            . 'button:hover{filter:brightness(1.05)}a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}'
            . '.anon{margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0}.anon button{background:#fff;color:#2563eb;border:1px solid #2563eb}'
            . '</style></head><body><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'strict-origin-when-cross-origin',
            ],
            'body' => $html,
        ];
    }

    private function errorHtml(?string $error): string {
        return $error === null || $error === '' ? '' : '<div class="error">' . htmlspecialchars($error) . '</div>';
    }

    private function hidden(string $name, string $value): string {
        return '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">';
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function methodNotAllowed(): array {
        return $this->json(405, ['error' => 'method_not_allowed']);
    }
}
