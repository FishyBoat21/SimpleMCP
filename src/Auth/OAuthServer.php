<?php

declare(strict_types=1);

namespace McpServer\Auth;

use McpServer\Database\Database;
use PDO;

class OAuthServer {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // ─── Authorization Endpoint ───────────────────────────────────────────────

    /**
     * Validate an incoming authorization request.
     * Returns ['ok' => true, 'client' => [...], ...] or ['error' => '...']
     */
    public function validateAuthorizationRequest(array $params): array {
        $clientId    = $params['client_id']    ?? '';
        $redirectUri = $params['redirect_uri'] ?? '';
        $responseType = $params['response_type'] ?? '';

        if ($responseType !== 'code') {
            return ['error' => 'unsupported_response_type'];
        }

        $client = $this->db
            ->prepare("SELECT * FROM oauth_clients WHERE client_id = ?")
            ->execute([$clientId]) ? null : null;

        $stmt = $this->db->prepare("SELECT * FROM oauth_clients WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();

        if (!$client) {
            return ['error' => 'invalid_client'];
        }

        $allowedUris = json_decode($client['redirect_uris'], true) ?? [];
        if ($redirectUri && !in_array($redirectUri, $allowedUris, true)) {
            return ['error' => 'invalid_redirect_uri'];
        }

        $finalRedirect = $redirectUri ?: $allowedUris[0];

        return [
            'ok'           => true,
            'client'       => $client,
            'redirect_uri' => $finalRedirect,
            'scope'        => $params['scope'] ?? $client['scopes'],
            'state'        => $params['state'] ?? '',
            'code_challenge'        => $params['code_challenge'] ?? null,
            'code_challenge_method' => $params['code_challenge_method'] ?? null,
        ];
    }

    /**
     * Issue an authorization code after user consent.
     */
    public function issueAuthorizationCode(
        string $clientId,
        int    $userId,
        string $redirectUri,
        string $scopes,
        ?string $codeChallenge,
        ?string $codeChallengeMethod
    ): string {
        $code      = bin2hex(random_bytes(32));
        $expiresAt = time() + 600; // 10 minutes

        $this->db->prepare("
            INSERT INTO oauth_auth_codes
                (code, client_id, user_id, redirect_uri, scopes,
                 code_challenge, code_challenge_method, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $code, $clientId, $userId, $redirectUri, $scopes,
            $codeChallenge, $codeChallengeMethod, $expiresAt
        ]);

        return $code;
    }

    // ─── Token Endpoint ───────────────────────────────────────────────────────

    /**
     * Exchange an authorization code for tokens.
     * Returns ['access_token'=>..., 'refresh_token'=>..., ...] or ['error'=>...]
     */
    public function exchangeCodeForToken(array $params): array {
        $grantType    = $params['grant_type']    ?? '';
        $code         = $params['code']          ?? '';
        $redirectUri  = $params['redirect_uri']  ?? '';
        $clientId     = $params['client_id']     ?? '';
        $clientSecret = $params['client_secret'] ?? '';
        $codeVerifier = $params['code_verifier'] ?? '';

        if ($grantType !== 'authorization_code') {
            return ['error' => 'unsupported_grant_type'];
        }

        // Validate client
        $client = $this->getClient($clientId, $clientSecret);
        if (!$client) {
            return ['error' => 'invalid_client'];
        }

        // Load auth code
        $stmt = $this->db->prepare("
            SELECT * FROM oauth_auth_codes
            WHERE code = ? AND used = 0 AND expires_at > ?
        ");
        $stmt->execute([$code, time()]);
        $authCode = $stmt->fetch();

        if (!$authCode) {
            return ['error' => 'invalid_grant', 'error_description' => 'Authorization code is invalid or expired'];
        }

        if ($authCode['client_id'] !== $clientId) {
            return ['error' => 'invalid_grant', 'error_description' => 'Client mismatch'];
        }

        if ($redirectUri && $authCode['redirect_uri'] !== $redirectUri) {
            return ['error' => 'invalid_grant', 'error_description' => 'Redirect URI mismatch'];
        }

        // PKCE verification
        if ($authCode['code_challenge']) {
            if (!$codeVerifier) {
                return ['error' => 'invalid_grant', 'error_description' => 'code_verifier required'];
            }
            $method = $authCode['code_challenge_method'] ?? 'plain';
            $computed = match ($method) {
                'S256'  => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
                default => $codeVerifier,
            };
            if (!hash_equals($authCode['code_challenge'], $computed)) {
                return ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed'];
            }
        }

        // Mark code as used
        $this->db->prepare("UPDATE oauth_auth_codes SET used = 1 WHERE code = ?")
            ->execute([$code]);

        return $this->issueTokens(
            $clientId,
            (int) $authCode['user_id'],
            $authCode['scopes']
        );
    }

    /**
     * Refresh an access token.
     */
    public function refreshToken(array $params): array {
        $clientId     = $params['client_id']     ?? '';
        $clientSecret = $params['client_secret'] ?? '';
        $refreshToken = $params['refresh_token'] ?? '';

        $client = $this->getClient($clientId, $clientSecret);
        if (!$client) {
            return ['error' => 'invalid_client'];
        }

        $stmt = $this->db->prepare("
            SELECT rt.*, at.client_id, at.user_id, at.scopes
            FROM oauth_refresh_tokens rt
            JOIN oauth_access_tokens  at ON at.token = rt.access_token
            WHERE rt.token = ? AND rt.revoked = 0 AND rt.expires_at > ?
        ");
        $stmt->execute([$refreshToken, time()]);
        $rt = $stmt->fetch();

        if (!$rt || $rt['client_id'] !== $clientId) {
            return ['error' => 'invalid_grant'];
        }

        // Revoke old refresh token + access token
        $this->db->prepare("UPDATE oauth_refresh_tokens SET revoked = 1 WHERE token = ?")
            ->execute([$refreshToken]);
        $this->db->prepare("UPDATE oauth_access_tokens SET revoked = 1 WHERE token = ?")
            ->execute([$rt['access_token']]);

        return $this->issueTokens($clientId, (int) $rt['user_id'], $rt['scopes']);
    }

    // ─── Token Introspection ──────────────────────────────────────────────────

    /**
     * Validate a Bearer token. Returns user+scope data or null.
     */
    public function introspectToken(string $token): ?array {
        $stmt = $this->db->prepare("
            SELECT at.*, u.username, u.email
            FROM oauth_access_tokens at
            JOIN users u ON u.id = at.user_id
            WHERE at.token = ? AND at.revoked = 0 AND at.expires_at > ?
        ");
        $stmt->execute([$token, time()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Revoke a token (logout).
     */
    public function revokeToken(string $token): void {
        $this->db->prepare("UPDATE oauth_access_tokens SET revoked = 1 WHERE token = ?")
            ->execute([$token]);
    }

    // ─── User Auth ────────────────────────────────────────────────────────────

    public function authenticateUser(string $username, string $password): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        return null;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function getClient(string $clientId, string $clientSecret): ?array {
        $stmt = $this->db->prepare("SELECT * FROM oauth_clients WHERE client_id = ?");
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();

        if (!$client) return null;
        // Public clients (no secret) are allowed; if secret provided, validate
        if ($clientSecret && !hash_equals($client['client_secret'], $clientSecret)) {
            return null;
        }
        return $client;
    }

    private function issueTokens(string $clientId, int $userId, string $scopes): array {
        $accessToken  = bin2hex(random_bytes(48));
        $refreshToken = bin2hex(random_bytes(48));
        $expiresIn    = 3600; // 1 hour
        $now          = time();

        $this->db->prepare("
            INSERT INTO oauth_access_tokens (token, client_id, user_id, scopes, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$accessToken, $clientId, $userId, $scopes, $now + $expiresIn]);

        $this->db->prepare("
            INSERT INTO oauth_refresh_tokens (token, access_token, expires_at)
            VALUES (?, ?, ?)
        ")->execute([$refreshToken, $accessToken, $now + 86400 * 30]); // 30 days

        return [
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'refresh_token' => $refreshToken,
            'scope'         => $scopes,
        ];
    }

    // ─── Admin Helpers ────────────────────────────────────────────────────────

    public function listClients(): array {
        return $this->db->query("SELECT * FROM oauth_clients ORDER BY created_at DESC")->fetchAll();
    }

    public function listUsers(): array {
        return $this->db->query("SELECT id, username, email, created_at FROM users ORDER BY id")->fetchAll();
    }

    public function createClient(string $name, array $redirectUris, string $scopes): array {
        $clientId     = 'client_' . bin2hex(random_bytes(8));
        $clientSecret = bin2hex(random_bytes(32));
        $this->db->prepare("
            INSERT INTO oauth_clients (client_id, client_secret, name, redirect_uris, scopes)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$clientId, $clientSecret, $name, json_encode($redirectUris), $scopes]);

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }

    public function createUser(string $username, string $password, string $email = ''): bool {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $this->db->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)")
                ->execute([$username, $hash, $email]);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function getActiveTokenStats(): array {
        $now = time();
        $tokens = (int) $this->db->prepare("SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked=0 AND expires_at>?")
            ->execute([$now]) ? $this->db->query("SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked=0 AND expires_at>$now")->fetchColumn() : 0;

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked=0 AND expires_at > ?");
        $stmt->execute([$now]);
        $activeTokens = (int) $stmt->fetchColumn();

        $stmt2 = $this->db->prepare("SELECT COUNT(*) FROM oauth_access_tokens WHERE created_at > ?");
        $stmt2->execute([$now - 86400]);
        $todayTokens = (int) $stmt2->fetchColumn();

        return ['active' => $activeTokens, 'today' => $todayTokens];
    }
}
