<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * User-management web pages: login, passkey registration & authentication,
 * public onboarding, change password, and logout.
 *
 * Uses PHP's native cookie sessions + per-session CSRF tokens.
 */
final class AccountController {
    public function __construct(
        private readonly UserStore $users,
        private readonly ?PasskeyStore $passkeys = null,
        private readonly ?string $issuer = null,
        private readonly ?TwoFactorService $twoFactor = null,
        /** App mount prefix (e.g. "/SimpleMCP") when hosted under a virtual directory; '' at the site root. */
        private readonly string $mountPath = '',
    ) {}

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, array $get, array $post): array {
        return match ($path) {
            '/account/login' => $method === 'POST' ? $this->login($post) : $this->redirect('/account'),
            '/account/2fa/verify' => $method === 'POST' ? $this->twoFactorVerify($post) : $this->twoFactorVerifyPage(),
            '/account/2fa/resend' => $method === 'POST' ? $this->twoFactorResend($post) : $this->redirect('/account/2fa/verify'),
            '/account/2fa/set-email' => $method === 'POST' ? $this->twoFactorSetEmail($post) : $this->twoFactorSetEmailPage(),
            '/account/update-email' => $method === 'POST' ? $this->updateEmail($post) : $this->redirect('/account'),
            '/account/device/revoke' => $method === 'POST' ? $this->deviceRevoke($post) : $this->redirect('/account'),
            '/account/onboard' => $method === 'GET' ? $this->onboardPage('', '', '', '') : $this->onboard($post),
            '/account/change-password' => $method === 'POST' ? $this->changePassword($post) : $this->redirect('/account'),
            '/account/logout' => $method === 'POST' ? $this->logout($post) : $this->redirect('/account'),
            '/account/passkey/register/options' => $method === 'POST' ? $this->passkeyRegisterOptions() : $this->methodNotAllowed(),
            '/account/passkey/register/verify' => $method === 'POST' ? $this->passkeyRegisterVerify($post) : $this->methodNotAllowed(),
            '/account/passkey/delete' => $method === 'POST' ? $this->passkeyDelete($post) : $this->redirect('/account'),
            '/account/passkey/login/options' => $method === 'POST' ? $this->passkeyLoginOptions($post) : $this->methodNotAllowed(),
            '/account/passkey/login/verify' => $method === 'POST' ? $this->passkeyLoginVerify($post) : $this->methodNotAllowed(),
            default => $method === 'GET' ? $this->accountPage($get) : $this->redirect('/account'),
        };
    }

    private function login(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $identifier = trim((string) ($post['username'] ?? ''));
        $password = (string) ($post['password'] ?? '');

        // Step 1: username submitted
        if ($password === '') {
            if ($identifier === '') {
                return $this->usernamePage('Username is required.');
            }
            $row = $this->users->getByUsername($identifier);
            if ($row === null) {
                return $this->usernamePage('No account found for this username.');
            }
            if (($row['password_hash'] ?? null) === null) {
                return $this->onboardPage('Set up your account to finish logging in.', (string) $row['username'], (string) $row['username'], (string) ($row['email'] ?? ''));
            }
            return $this->passwordPage(null, $identifier);
        }

        // Step 2: username + password
        $user = $this->users->authenticate($identifier, $password);
        if ($user === null) {
            return $this->passwordPage('Incorrect password.', $identifier);
        }

        $username = (string) $user['username'];
        $email = (string) ($user['email'] ?? '');

        // Every user must have an email set for 2FA
        if ($email === '') {
            $_SESSION['2fa_pending'] = ['username' => $username, 'reason' => 'missing_email'];
            return $this->redirect('/account/2fa/set-email');
        }

        // Check if device is trusted or new
        $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
        if ($this->twoFactor !== null && !$this->twoFactor->isTrustedDevice($username, $rawCookie)) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->twoFactor->sendOtp($username, $email, $ua, $ip);
            $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
            return $this->redirect('/account/2fa/verify');
        }

        $_SESSION['username'] = $username;
        $this->regenerateSession();
        return $this->redirect('/account');
    }

    private function onboard(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $existingUsername = (string) ($post['existing_username'] ?? '');
        $username = trim((string) ($post['new_username'] ?? ''));
        $email = trim((string) ($post['email'] ?? ''));
        $password = (string) ($post['new_password'] ?? '');
        $confirm = (string) ($post['confirm_password'] ?? '');
        $error = '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'A valid email address is required for two-factor security.';
        } elseif ($password === '' || $confirm === '' || $password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($existingUsername === '' && $username === '') {
            $error = 'Username is required.';
        } else {
            $user = $this->users->onboardUser($existingUsername !== '' ? $existingUsername : null, $username, $password, '', $email);
            if ($user === null) {
                $error = $existingUsername !== '' ? 'Account is not pending.' : 'Username is already taken.';
            } else {
                if ($this->twoFactor !== null) {
                    $token = $this->twoFactor->trustDevice($user->username, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
                    $this->twoFactor->setDeviceCookie($token, $this->isHttps());
                }
                $_SESSION['username'] = $user->username;
                $this->regenerateSession();
                return $this->redirect('/account');
            }
        }

        $displayUsername = $username;
        if ($existingUsername !== '') {
            $row = $this->users->getByUsername($existingUsername);
            $displayUsername = $row !== null ? (string) $row['username'] : $username;
        }
        return $this->onboardPage($error, $existingUsername, $displayUsername, $email);
    }

    private function changePassword(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null) {
            return $this->redirect('/account');
        }
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $ok = $this->users->changePassword(
            (string) $username,
            (string) ($post['old_password'] ?? ''),
            (string) ($post['new_password'] ?? ''),
        );
        return $this->redirect($ok ? '/account?msg=Password+changed.' : '/account?msg=' . rawurlencode('Current password is incorrect or the new password is too short.'));
    }

    private function logout(array $post): array {
        if ($this->csrfOk($post)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
        return $this->redirect('/account');
    }

    // ---- Passkey Handlers ---------------------------------------------------

    private function passkeyRegisterOptions(): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null || $this->passkeys === null) {
            return $this->json(401, ['error' => 'Authentication required']);
        }

        $user = $this->users->getByUsername((string) $username);
        if ($user === null) {
            return $this->json(404, ['error' => 'User not found']);
        }

        $challenge = random_bytes(32);
        $_SESSION['passkey_reg_challenge'] = $challenge;

        $existing = $this->passkeys->findByUsername((string) $username);
        $exclude = array_column($existing, 'id');

        $origin = $this->origin();
        $rpId = $this->rpId();

        $options = WebAuthn::createRegistrationOptions(
            (string) $username,
            (string) ($user['name'] ?? $username),
            $rpId,
            'SimpleMCP',
            $challenge,
            $exclude
        );

        return $this->json(200, $options);
    }

    private function passkeyRegisterVerify(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null || $this->passkeys === null) {
            return $this->json(401, ['error' => 'Authentication required']);
        }

        $expectedChallenge = $_SESSION['passkey_reg_challenge'] ?? null;
        if (!is_string($expectedChallenge) || $expectedChallenge === '') {
            return $this->json(400, ['error' => 'Registration challenge expired. Please retry.']);
        }
        unset($_SESSION['passkey_reg_challenge']);

        $clientDataJson = (string) ($post['clientDataJSON'] ?? '');
        $attestationObject = (string) ($post['attestationObject'] ?? '');
        $passkeyName = trim((string) ($post['name'] ?? 'Passkey'));

        if ($clientDataJson === '' || $attestationObject === '') {
            return $this->json(400, ['error' => 'Missing registration payload']);
        }

        $origin = $this->origin();
        $rpId = $this->rpId();

        try {
            $regResult = WebAuthn::verifyRegistration(
                $clientDataJson,
                $attestationObject,
                $expectedChallenge,
                $origin,
                $rpId
            );

            $this->passkeys->create(
                $regResult['credential_id'],
                (string) $username,
                $regResult['public_key_pem'],
                $regResult['sign_count'],
                $regResult['aaguid'],
                $passkeyName !== '' ? $passkeyName : 'Passkey'
            );
        } catch (\Throwable $e) {
            return $this->json(400, ['error' => 'Passkey registration failed: ' . $e->getMessage()]);
        }

        return $this->json(200, ['success' => true]);
    }

    private function passkeyDelete(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null || $this->passkeys === null || !$this->csrfOk($post)) {
            return $this->redirect('/account');
        }

        $id = (string) ($post['id'] ?? '');
        if ($id !== '') {
            $this->passkeys->delete($id, (string) $username);
        }
        return $this->redirect('/account?msg=' . rawurlencode('Passkey deleted.'));
    }

    private function passkeyLoginOptions(array $post): array {
        if ($this->passkeys === null) {
            return $this->json(400, ['error' => 'Passkeys are not configured.']);
        }

        $rpId = $this->rpId();
        $challenge = random_bytes(32);
        $_SESSION['passkey_auth_challenge'] = $challenge;

        $username = trim((string) ($post['username'] ?? ''));
        $allowCreds = [];
        if ($username !== '') {
            $userCreds = $this->passkeys->findByUsername($username);
            $allowCreds = array_column($userCreds, 'id');
        }

        $options = WebAuthn::createAuthenticationOptions($rpId, $challenge, $allowCreds);
        return $this->json(200, $options);
    }

    private function passkeyLoginVerify(array $post): array {
        if ($this->passkeys === null) {
            return $this->json(400, ['error' => 'Passkeys are not configured.']);
        }

        $expectedChallenge = $_SESSION['passkey_auth_challenge'] ?? null;
        if (!is_string($expectedChallenge) || $expectedChallenge === '') {
            return $this->json(400, ['error' => 'Authentication challenge expired. Please retry.']);
        }
        unset($_SESSION['passkey_auth_challenge']);

        $credId = (string) ($post['id'] ?? '');
        $clientDataJson = (string) ($post['clientDataJSON'] ?? '');
        $authData = (string) ($post['authenticatorData'] ?? '');
        $signature = (string) ($post['signature'] ?? '');

        if ($credId === '' || $clientDataJson === '' || $authData === '' || $signature === '') {
            return $this->json(400, ['error' => 'Missing required WebAuthn fields.']);
        }

        $cred = $this->passkeys->findById($credId);
        if ($cred === null) {
            return $this->json(400, ['error' => 'Passkey credential not recognized.']);
        }

        $origin = $this->origin();
        $rpId = $this->rpId();

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

        $username = (string) $user['username'];
        $email = (string) ($user['email'] ?? '');

        if ($email === '') {
            $_SESSION['2fa_pending'] = ['username' => $username, 'reason' => 'missing_email'];
            return $this->json(200, ['success' => true, 'redirect_url' => '/account/2fa/set-email']);
        }

        $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
        if ($this->twoFactor !== null && !$this->twoFactor->isTrustedDevice($username, $rawCookie)) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->twoFactor->sendOtp($username, $email, $ua, $ip);
            $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
            return $this->json(200, ['success' => true, 'requires_2fa' => true, 'redirect_url' => '/account/2fa/verify']);
        }

        $_SESSION['username'] = $username;
        $this->regenerateSession();

        return $this->json(200, ['success' => true, 'redirect_url' => '/account']);
    }

    // ---- Page Views ---------------------------------------------------------

    private function accountPage(array $get): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null) {
            return $this->usernamePage(null);
        }

        $row = $this->users->getByUsername((string) $username);
        if ($row === null) {
            $_SESSION = [];
            session_destroy();
            return $this->usernamePage('Your account no longer exists.');
        }

        $user = $this->users->toUserContext($row);
        $msg = (string) ($get['msg'] ?? '');
        $msgHtml = $msg === '' ? '' : '<div class="ok">' . htmlspecialchars($msg) . '</div>';

        // Render passkeys list
        $userPasskeys = $this->passkeys !== null ? $this->passkeys->findByUsername($user->username) : [];
        $passkeysHtml = '';
        if ($userPasskeys === []) {
            $passkeysHtml = '<p class="hint">No passkeys registered yet. Add a passkey to sign in without a password using Touch ID, Face ID, Windows Hello, or a security key.</p>';
        } else {
            $passkeysHtml = '<table class="passkeys"><thead><tr><th>Name</th><th>Created</th><th>Last Used</th><th></th></tr></thead><tbody>';
            foreach ($userPasskeys as $pk) {
                $lastUsed = $pk['last_used_at'] ? date('Y-m-d H:i', (int) $pk['last_used_at']) : 'Never';
                $created = date('Y-m-d', (int) $pk['created_at']);
                $passkeysHtml .= '<tr>
                    <td><strong>' . htmlspecialchars((string) $pk['name']) . '</strong></td>
                    <td>' . htmlspecialchars($created) . '</td>
                    <td>' . htmlspecialchars($lastUsed) . '</td>
                    <td>
                        <form method="post" action="' . $this->url('/account/passkey/delete') . '" style="margin:0;">
                            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
                            <input type="hidden" name="id" value="' . htmlspecialchars((string) $pk['id'], ENT_QUOTES) . '">
                            <button type="submit" class="danger" onclick="return confirm(\'Delete this passkey?\');">Delete</button>
                        </form>
                    </td>
                </tr>';
            }
            $passkeysHtml .= '</tbody></table>';
        }

        $passkeySection = '
        <h2>Passkeys (Biometrics / Security Keys)</h2>
        ' . $passkeysHtml . '
        <div class="card">
            <h3 style="margin-top:0;font-size:1.05rem;">Register New Passkey</h3>
            <div id="reg-status" style="display:none;margin-bottom:.8rem;"></div>
            <label>Device / Key Name
                <input type="text" id="passkey-name" value="My Passkey" placeholder="e.g. MacBook Touch ID, Windows Hello">
            </label>
            <button type="button" id="reg-passkey-btn" style="background:#0f766e;">🔑 Add Passkey</button>
        </div>
        <script>
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

            var btn = document.getElementById("reg-passkey-btn");
            var statusDiv = document.getElementById("reg-status");

            if (btn) {
                btn.addEventListener("click", async function() {
                    statusDiv.style.display = "none";
                    statusDiv.className = "";

                    if (window.location.hostname === "127.0.0.1" || window.location.hostname === "::1") {
                        window.location.hostname = "localhost";
                        return;
                    }

                    if (/^(\d{1,3}\.){3}\d{1,3}$/.test(window.location.hostname)) {
                        statusDiv.className = "error";
                        statusDiv.textContent = "WebAuthn passkeys cannot be used on an IP address (" + window.location.hostname + "). Please access the page using http://localhost:" + (window.location.port || "8000") + " or a domain name.";
                        statusDiv.style.display = "block";
                        return;
                    }

                    if (!window.PublicKeyCredential) {
                        statusDiv.className = "error";
                        statusDiv.textContent = "WebAuthn / Passkeys are not supported in this browser.";
                        statusDiv.style.display = "block";
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = "Registering...";

                    try {
                        var optRes = await fetch("' . $this->url('/account/passkey/register/options') . '", {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" }
                        });
                        var opt = await optRes.json();
                        if (!optRes.ok) throw new Error(opt.error || "Failed to initiate passkey registration");

                        opt.challenge = b64ToBuf(opt.challenge);
                        opt.user.id = b64ToBuf(opt.user.id);
                        if (opt.excludeCredentials) {
                            opt.excludeCredentials = opt.excludeCredentials.map(function(c) {
                                return Object.assign({}, c, { id: b64ToBuf(c.id) });
                            });
                        }

                        var cred = await navigator.credentials.create({ publicKey: opt });
                        if (!cred) throw new Error("Registration was canceled");

                        var nameInput = document.getElementById("passkey-name");
                        var verifyRes = await fetch("' . $this->url('/account/passkey/register/verify') . '", {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body: new URLSearchParams({
                                clientDataJSON: bufToB64(cred.response.clientDataJSON),
                                attestationObject: bufToB64(cred.response.attestationObject),
                                name: nameInput ? nameInput.value.trim() : "Passkey"
                            })
                        });

                        var resData = await verifyRes.json();
                        if (verifyRes.ok && resData.success) {
                            window.location.href = "' . $this->url('/account') . '?msg=" + encodeURIComponent("Passkey registered successfully!");
                        } else {
                            throw new Error(resData.error || "Failed to verify passkey registration");
                        }
                    } catch (e) {
                        statusDiv.className = "error";
                        statusDiv.textContent = e.name === "NotAllowedError" ? "Passkey setup canceled or timed out." : e.message;
                        statusDiv.style.display = "block";
                        btn.disabled = false;
                        btn.textContent = "🔑 Add Passkey";
                    }
                });
            }
        })();
        </script>';

        $emailSection = '
        <h2>Email Address</h2>
        <p class="hint">Two-factor authentication codes and account security alerts are sent to this address.</p>
        <form method="post" action="' . $this->url('/account/update-email') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Email address <input type="email" name="email" value="' . htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES) . '" required autocomplete="email" placeholder="you@example.com"></label>
            <button type="submit" style="background:#0f766e;">Update Email</button>
        </form>';

        $trustedDevices = $this->twoFactor !== null ? $this->twoFactor->getTrustedDevices($user->username) : [];
        $devicesHtml = '';
        if ($trustedDevices === []) {
            $devicesHtml = '<p class="hint">No trusted devices registered. Unrecognized devices will prompt for email 2FA verification.</p>';
        } else {
            $devicesHtml = '<table class="passkeys"><thead><tr><th>Device</th><th>Last Active</th><th>IP</th><th></th></tr></thead><tbody>';
            foreach ($trustedDevices as $dev) {
                $lastUsed = $dev['last_used_at'] ? date('Y-m-d H:i', (int) $dev['last_used_at']) : 'Never';
                $ip = htmlspecialchars((string) ($dev['ip_address'] ?? ''));
                $devicesHtml .= '<tr>
                    <td>
                        <strong>' . htmlspecialchars((string) $dev['name']) . '</strong>
                        <div style="font-size:0.75rem;color:#64748b;">' . htmlspecialchars(substr((string) ($dev['user_agent'] ?? ''), 0, 45)) . '</div>
                    </td>
                    <td>' . htmlspecialchars($lastUsed) . '</td>
                    <td>' . $ip . '</td>
                    <td>
                        <form method="post" action="' . $this->url('/account/device/revoke') . '" style="margin:0;">
                            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
                            <input type="hidden" name="id" value="' . htmlspecialchars((string) $dev['id'], ENT_QUOTES) . '">
                            <button type="submit" class="danger" onclick="return confirm(\'Revoke this trusted device?\');">Revoke</button>
                        </form>
                    </td>
                </tr>';
            }
            $devicesHtml .= '</tbody></table>';
        }
        $deviceSection = '
        <h2>Trusted Devices (2FA)</h2>
        <p class="hint">Devices listed here bypass email 2FA when logging in.</p>
        ' . $devicesHtml;

        $body = $msgHtml . '
        <h2>Your account</h2>
        <dl class="user">
            <dt>Username</dt><dd>' . htmlspecialchars($user->username) . '</dd>
            <dt>Name</dt><dd>' . htmlspecialchars($user->name) . '</dd>
            <dt>Email</dt><dd>' . htmlspecialchars((string) ($row['email'] ?? 'Not set')) . '</dd>
            <dt>Roles</dt><dd>' . htmlspecialchars(implode(', ', $user->roles)) . '</dd>
            <dt>Permissions</dt><dd>' . htmlspecialchars(implode(', ', $user->permissions)) . '</dd>
        </dl>
        ' . $emailSection . '
        ' . $deviceSection . '
        ' . $passkeySection . '
        <h2>Change password</h2>
        <form method="post" action="' . $this->url('/account/change-password') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Current password <input type="password" name="old_password" required autocomplete="current-password"></label>
            <label>New password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
            <button type="submit">Change password</button>
        </form>
        <form method="post" action="' . $this->url('/account/logout') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <button type="submit" class="secondary">Log out</button>
        </form>';

        return $this->page(200, 'My account', $body);
    }

    private function usernamePage(?string $error): array {
        $body = $this->errorHtml($error) . '
        <div id="passkey-error" class="error" style="display:none;"></div>
        <form method="post" action="' . $this->url('/account/login') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Username <input type="text" name="username" required autofocus autocomplete="username webauthn"></label>
            <button type="submit">Continue with Password</button>
            <button type="button" id="passkey-login-btn" style="background:#0f766e;margin-top:.6rem;">🔑 Sign in with Passkey</button>
        </form>
        <p class="hint">New user? <a href="' . $this->url('/account/onboard') . '">Set up your account</a>.</p>
        <script>
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

                    var optRes = await fetch("' . $this->url('/account/passkey/login/options') . '", {
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

                    var verifyRes = await fetch("' . $this->url('/account/passkey/login/verify') . '", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            id: cred.id,
                            clientDataJSON: bufToB64(cred.response.clientDataJSON),
                            authenticatorData: bufToB64(cred.response.authenticatorData),
                            signature: bufToB64(cred.response.signature)
                        })
                    });

                    var verifyData = await verifyRes.json();
                    if (verifyRes.ok && verifyData.success) {
                        window.location.href = verifyData.redirect_url || "' . $this->url('/account') . '";
                    } else {
                        throw new Error(verifyData.error || "Passkey authentication failed");
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

            var btn = document.getElementById("passkey-login-btn");
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
        return $this->page(200, 'Log in', $body);
    }

    private function passwordPage(?string $error, string $identifier): array {
        $body = $this->errorHtml($error) . '
        <form method="post" action="' . $this->url('/account/login') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <input type="hidden" name="username" value="' . htmlspecialchars($identifier, ENT_QUOTES) . '">
            <p class="hint">Logging in as <strong>' . htmlspecialchars($identifier) . '</strong>.</p>
            <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
            <button type="submit">Log in</button>
        </form>
        <p class="hint"><a href="' . $this->url('/account') . '">Use a different username</a>.</p>';
        return $this->page(200, 'Log in', $body);
    }

    private function onboardPage(string $error, string $existingUsername, string $username, string $email = ''): array {
        $usernameField = $existingUsername !== ''
            ? '<p class="hint">Username <strong>' . htmlspecialchars($existingUsername, ENT_QUOTES) . '</strong> — cannot be changed.</p>'
            : '<label>Username <input type="text" name="new_username" value="' . htmlspecialchars($username, ENT_QUOTES) . '" required autofocus autocomplete="username"></label>';
        $body = $this->errorHtml($error) . '
        <form method="post" action="' . $this->url('/account/onboard') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <input type="hidden" name="existing_username" value="' . htmlspecialchars($existingUsername, ENT_QUOTES) . '">
            ' . $usernameField . '
            <label>Email address <input type="email" name="email" value="' . htmlspecialchars($email, ENT_QUOTES) . '" required autocomplete="email" placeholder="you@example.com"></label>
            <p class="hint" style="margin-top:-0.5rem;margin-bottom:0.8rem;">Required for two-factor verification on new devices.</p>
            <label>Password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
            <button type="submit">Set up my account</button>
        </form>
        <p class="hint">Already have an account? <a href="' . $this->url('/account') . '">Log in</a>.</p>';
        return $this->page(200, 'Set up your account', $body);
    }

    // ---- Two-Factor Authentication (2FA) Handlers ---------------------------

    private function twoFactorVerifyPage(?string $error = null): array {
        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['username'])) {
            return $this->redirect('/account');
        }

        $username = (string) $pending['username'];
        $user = $this->users->getByUsername($username);
        $email = (string) ($user['email'] ?? $pending['email'] ?? '');
        $maskedEmail = $this->twoFactor !== null ? $this->twoFactor->maskEmail($email) : $email;

        $msg = (string) ($_GET['msg'] ?? '');
        $msgHtml = $msg !== '' ? '<div class="ok">' . htmlspecialchars($msg) . '</div>' : '';

        $body = $msgHtml . $this->errorHtml($error) . '
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:1rem;margin-bottom:1.2rem;">
            <p style="margin:0 0 0.5rem;font-weight:600;color:#1e40af;">🛡️ New Device Detected</p>
            <p style="margin:0;font-size:0.9rem;color:#1e3a8a;">A 6-digit verification code has been sent to <strong>' . htmlspecialchars($maskedEmail) . '</strong>.</p>
        </div>
        <form method="post" action="' . $this->url('/account/2fa/verify') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>6-Digit Verification Code
                <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="000000" style="letter-spacing:6px;font-size:1.5rem;text-align:center;font-weight:bold;">
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin:1rem 0;font-size:0.9rem;cursor:pointer;">
                <input type="checkbox" name="trust_device" value="1" checked style="width:auto;margin:0;"> Trust this device for 90 days (skip 2FA next time)
            </label>
            <button type="submit">Verify & Sign In</button>
        </form>
        <div style="margin-top:1.2rem;display:flex;justify-content:space-between;align-items:center;">
            <form method="post" action="' . $this->url('/account/2fa/resend') . '" style="margin:0;">
                <input type="hidden" name="csrf" value="' . $this->csrf() . '">
                <button type="submit" class="secondary" style="width:auto;padding:0.4rem 0.8rem;font-size:0.85rem;background:#475569;">Resend Code</button>
            </form>
            <a href="' . $this->url('/account') . '" style="font-size:0.9rem;">Cancel / Different User</a>
        </div>';

        return $this->page(200, 'Two-Factor Verification', $body);
    }

    private function twoFactorVerify(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['username'])) {
            return $this->redirect('/account');
        }

        $username = (string) $pending['username'];
        $otp = trim((string) ($post['otp'] ?? ''));

        if ($this->twoFactor === null) {
            $_SESSION['username'] = $username;
            unset($_SESSION['2fa_pending']);
            return $this->redirect('/account');
        }

        $res = $this->twoFactor->verifyOtp($username, $otp);
        if (!$res['success']) {
            return $this->twoFactorVerifyPage($res['error']);
        }

        // Trust device if requested
        $trustDevice = !empty($post['trust_device']);
        if ($trustDevice) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $deviceToken = $this->twoFactor->trustDevice($username, $ua, $ip);
            $this->twoFactor->setDeviceCookie($deviceToken, $this->isHttps());
        }

        $_SESSION['username'] = $username;
        unset($_SESSION['2fa_pending']);
        $this->regenerateSession();

        return $this->redirect('/account?msg=' . rawurlencode('Verified successfully!'));
    }

    private function twoFactorResend(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['username'])) {
            return $this->redirect('/account');
        }

        $username = (string) $pending['username'];
        $user = $this->users->getByUsername($username);
        $email = (string) ($user['email'] ?? $pending['email'] ?? '');

        if ($this->twoFactor !== null && $email !== '') {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->twoFactor->sendOtp($username, $email, $ua, $ip);
        }

        return $this->redirect('/account/2fa/verify?msg=' . rawurlencode('A new verification code has been sent.'));
    }

    private function twoFactorSetEmailPage(?string $error = null): array {
        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['username'])) {
            return $this->redirect('/account');
        }

        $username = (string) $pending['username'];
        $body = $this->errorHtml($error) . '
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:1rem;margin-bottom:1.2rem;">
            <p style="margin:0 0 0.4rem;font-weight:600;color:#92400e;">✉️ Email Address Required</p>
            <p style="margin:0;font-size:0.9rem;color:#78350f;">Every SimpleMCP user must have an email set for two-factor authentication on new devices. Please enter your email address to continue.</p>
        </div>
        <form method="post" action="' . $this->url('/account/2fa/set-email') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Email address
                <input type="email" name="email" required autofocus autocomplete="email" placeholder="you@example.com">
            </label>
            <button type="submit">Save Email & Send Code</button>
        </form>
        <p class="hint" style="margin-top:1.2rem;"><a href="' . $this->url('/account') . '">Cancel login</a></p>';

        return $this->page(200, 'Set Email Address', $body);
    }

    private function twoFactorSetEmail(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $pending = $_SESSION['2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['username'])) {
            return $this->redirect('/account');
        }

        $username = (string) $pending['username'];
        $email = trim((string) ($post['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->twoFactorSetEmailPage('Please enter a valid email address.');
        }

        $ok = $this->users->updateEmail($username, $email);
        if (!$ok) {
            return $this->twoFactorSetEmailPage('Failed to update email address. Please try again.');
        }

        if ($this->twoFactor !== null) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->twoFactor->sendOtp($username, $email, $ua, $ip);
        }

        $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
        return $this->redirect('/account/2fa/verify');
    }

    private function updateEmail(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null) {
            return $this->redirect('/account');
        }
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $email = trim((string) ($post['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirect('/account?msg=' . rawurlencode('Please enter a valid email address.'));
        }

        $ok = $this->users->updateEmail((string) $username, $email);
        return $this->redirect($ok ? '/account?msg=Email+address+updated.' : '/account?msg=' . rawurlencode('Failed to update email address.'));
    }

    private function deviceRevoke(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null || $this->twoFactor === null || !$this->csrfOk($post)) {
            return $this->redirect('/account');
        }

        $id = (string) ($post['id'] ?? '');
        if ($id !== '') {
            $this->twoFactor->revokeDevice($id, (string) $username);
        }
        return $this->redirect('/account?msg=' . rawurlencode('Device revoked. It will require 2FA on the next login.'));
    }

    // ---- helpers ------------------------------------------------------------

    private function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    private function regenerateSession(): void {
        if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function origin(): string {
        if (is_string($this->issuer) && $this->issuer !== '') {
            return rtrim($this->issuer, '/');
        }
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    private function rpId(): string {
        $host = parse_url($this->origin(), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'localhost';
        if ($host === '127.0.0.1' || $host === '::1') {
            return 'localhost';
        }
        return $host;
    }

    private function csrf(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    private function csrfOk(array $post): bool {
        return isset($post['csrf'], $_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], (string) $post['csrf']);
    }

    /** Prefix an app-relative path with the mount prefix (e.g. "/account/login"). */
    private function url(string $path): string {
        return $this->mountPath . $path;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function redirect(string $location): array {
        return ['status' => 303, 'headers' => ['Location' => $this->url($location)], 'body' => ''];
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
    private function methodNotAllowed(): array {
        return $this->json(405, ['error' => 'Method not allowed']);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function page(int $status, string $title, string $body): array {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($title) . '</title><style>'
            . 'body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;color:#1e293b;line-height:1.5}'
            . 'h1,h2{font-size:1.3rem;margin:1.4rem 0 .6rem;color:#0f172a}.hint{color:#64748b;font-size:.9rem}'
            . '.error{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:.6rem .85rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem}'
            . '.ok{color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;padding:.6rem .85rem;border-radius:6px;margin-bottom:1rem;font-size:.9rem}'
            . 'label{display:block;margin-bottom:.9rem;font-size:.9rem;font-weight:500}input{width:100%;box-sizing:border-box;padding:.6rem .75rem;margin-top:.3rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.95rem}'
            . 'input:focus{outline:2px solid #2563eb;outline-offset:1px;border-color:#2563eb}'
            . 'dl.user{font-size:.9rem;background:#f8fafc;padding:.8rem 1rem;border-radius:8px;border:1px solid #e2e8f0}'
            . 'dl.user dt{font-weight:600;margin-top:.4rem;color:#475569}dl.user dd{margin:0;color:#0f172a}'
            . 'button{width:100%;padding:.65rem;margin-top:.4rem;background:#2563eb;color:#fff;border:0;border-radius:6px;font-size:.95rem;font-weight:500;cursor:pointer}'
            . 'button:hover{filter:brightness(1.05)}button.secondary{background:#64748b}'
            . 'button.danger{background:#dc2626;padding:.3rem .6rem;font-size:.8rem;width:auto;margin:0}'
            . 'table.passkeys{width:100%;font-size:.85rem;border-collapse:collapse;margin:.6rem 0 1rem}'
            . 'table.passkeys td, table.passkeys th{padding:.5rem .4rem;border-bottom:1px solid #e2e8f0;text-align:left}'
            . '.card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin:1.2rem 0}'
            . 'a{color:#2563eb;text-decoration:none}a:hover{text-decoration:underline}'
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
}
