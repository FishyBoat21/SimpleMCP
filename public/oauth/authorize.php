<?php

declare(strict_types=1);

/**
 * OAuth 2.1 Authorization Page (File-per-Page with PRG Pattern).
 *
 * GET:  Renders authorization interface, credentials prompt, passkey login, or 2FA.
 * POST: Handles credentials / 2FA / consent, and redirects to client redirect_uri or error (PRG).
 */

require_once __DIR__ . '/../layout.php';

use McpServer\Auth\ClientStore;
use McpServer\Auth\DebugLog;
use McpServer\Auth\TwoFactorService;
use McpServer\Auth\UserStore;

start_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$params = $method === 'POST' ? $_POST : $_GET;

$clientId = (string) ($params['client_id'] ?? '');
$redirectUri = (string) ($params['redirect_uri'] ?? '');
$challenge = (string) ($params['code_challenge'] ?? '');
$challengeMethod = (string) ($params['code_challenge_method'] ?? 'S256');
$state = (string) ($params['state'] ?? '');
$scope = (string) ($params['scope'] ?? '');

$client = App::clientStore()->find($clientId);
if ($client === null || !in_array($redirectUri, $client['redirect_uris'], true) || !ClientStore::isValidRedirectUri($redirectUri)) {
    http_response_code(400);
    render_html('Invalid Request', '<div class="alert alert-error">Invalid <code>client_id</code> or <code>redirect_uri</code>.</div>', ['subtitle' => 'OAuth Error']);
    exit;
}

$confidential = ($client['token_endpoint_auth_method'] ?? 'none') !== 'none';
if ($challenge === '') {
    if (!$confidential) {
        http_response_code(400);
        render_html('Invalid Request', '<div class="alert alert-error">Public clients must provide a <code>code_challenge</code> (PKCE).</div>', ['subtitle' => 'OAuth Error']);
        exit;
    }
    $challengeMethod = '';
} else {
    $validChallenge = $challengeMethod === 'S256'
        ? (preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) === 1)
        : ((App::oauthConfig()['allow_plain_pkce'] ?? false) === true && preg_match('/^[A-Za-z0-9_.~-]{43,128}$/', $challenge) === 1);

    if (!$validChallenge) {
        http_response_code(400);
        render_html('Invalid Request', '<div class="alert alert-error">Missing or invalid <code>code_challenge</code>. S256 required.</div>', ['subtitle' => 'OAuth Error']);
        exit;
    }
}

// Helper to build query parameters back to this authorization page
$authParams = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'code_challenge' => $challenge,
    'code_challenge_method' => $challengeMethod,
    'state' => $state,
    'scope' => $scope,
];

function issue_code_and_redirect(string $username, string $clientId, string $redirectUri, string $challenge, string $challengeMethod, string $state): never {
    DebugLog::write("AUTH code issued client={$clientId} user={$username}");
    $code = App::tokenStore()->createAuthCode(
        $username,
        $clientId,
        $redirectUri,
        $challenge,
        $challengeMethod,
        (int) (App::oauthConfig()['auth_code_ttl'] ?? 600)
    );
    $sep = str_contains($redirectUri, '?') ? '&' : '?';
    $params = ['code' => $code];
    if ($state !== '') {
        $params['state'] = $state;
    }
    redirect($redirectUri . $sep . http_build_query($params), 302);
}

// ---- POST Request (PRG Process) ---------------------------------------------
if ($method === 'POST') {
    // 1. Continue Anonymously
    if (($params['anonymous'] ?? '') === '1') {
        issue_code_and_redirect(UserStore::ANONYMOUS_USERNAME, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
    }

    // 2. 2FA Verification
    if (($params['verify_2fa'] ?? '') === '1') {
        $identifier = trim((string) ($params['username'] ?? ''));
        $otp = trim((string) ($params['otp'] ?? ''));
        $trust = !empty($params['trust_device']);

        $res = App::twoFactor()->verifyOtp($identifier, $otp);
        if (!$res['success']) {
            flash_set('error', $res['error'] ?? 'Invalid verification code.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => '2fa', 'username' => $identifier])));
        }

        if ($trust) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $token = App::twoFactor()->trustDevice($identifier, $ua, $ip);
            App::twoFactor()->setDeviceCookie($token, is_https());
        }

        issue_code_and_redirect($identifier, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
    }

    // 3. 2FA Resend
    if (($params['resend_2fa'] ?? '') === '1') {
        $identifier = trim((string) ($params['username'] ?? ''));
        $user = App::userStore()->getByUsername($identifier);
        $email = (string) ($user['email'] ?? '');
        if ($email !== '') {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            App::twoFactor()->sendOtp($identifier, $email, $ua, $ip);
            flash_set('msg', 'A new verification code has been sent.');
        }
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => '2fa', 'username' => $identifier])));
    }

    // 4. 2FA Set Email
    if (($params['set_email_2fa'] ?? '') === '1') {
        $identifier = trim((string) ($params['username'] ?? ''));
        $email = trim((string) ($params['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Please enter a valid email address.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => 'set_email', 'username' => $identifier])));
        }
        App::userStore()->updateEmail($identifier, $email);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        App::twoFactor()->sendOtp($identifier, $email, $ua, $ip);
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => '2fa', 'username' => $identifier])));
    }

    // 5. Onboarding Submission
    if (($params['onboard'] ?? '') === '1') {
        $existing = trim((string) ($params['existing_username'] ?? ''));
        $newUsername = trim((string) ($params['new_username'] ?? ''));
        $email = trim((string) ($params['email'] ?? ''));
        $newPassword = (string) ($params['new_password'] ?? '');
        $confirm = (string) ($params['confirm_password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'A valid email address is required for two-factor security.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['onboard' => '1', 'username' => $existing ?: $newUsername])));
        } elseif ($newPassword !== $confirm) {
            flash_set('error', 'Passwords do not match.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['onboard' => '1', 'username' => $existing ?: $newUsername])));
        } elseif (strlen($newPassword) < 8) {
            flash_set('error', 'Password must be at least 8 characters.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['onboard' => '1', 'username' => $existing ?: $newUsername])));
        }

        $user = App::userStore()->onboardUser($existing !== '' ? $existing : null, $newUsername, $newPassword, '', $email);
        if ($user === null) {
            flash_set('error', $existing !== '' ? 'Account is not pending.' : 'Username is already taken.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['onboard' => '1', 'username' => $existing ?: $newUsername])));
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $token = App::twoFactor()->trustDevice($user->username, $ua, $ip);
        App::twoFactor()->setDeviceCookie($token, is_https());

        issue_code_and_redirect($user->username, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
    }

    // 6. Login credentials submitted
    $identifier = trim((string) ($params['username'] ?? ''));
    $password = (string) ($params['password'] ?? '');

    // Step 1: Username only
    if ($password === '') {
        $row = App::userStore()->getByUsername($identifier);
        if ($row === null) {
            flash_set('error', 'No account found for this username.');
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['username' => $identifier])));
        }
        if (($row['password_hash'] ?? null) === null) {
            redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['onboard' => '1', 'username' => $identifier])));
        }
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => 'password', 'username' => $identifier])));
    }

    // Step 2: Username + password
    $user = App::userStore()->authenticate($identifier, $password);
    if ($user === null) {
        flash_set('error', 'Incorrect password.');
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => 'password', 'username' => $identifier])));
    }

    $username = (string) $user['username'];
    $email = (string) ($user['email'] ?? '');
    if ($email === '') {
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => 'set_email', 'username' => $username])));
    }

    $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
    if (!App::twoFactor()->isTrustedDevice($username, $rawCookie)) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        App::twoFactor()->sendOtp($username, $email, $ua, $ip);
        redirect('/oauth/authorize.php?' . http_build_query(array_merge($authParams, ['step' => '2fa', 'username' => $username])));
    }

    issue_code_and_redirect($username, $clientId, $redirectUri, $challenge, $challengeMethod, $state);
}

// ---- GET Request (Render View) ----------------------------------------------

$step = (string) ($params['step'] ?? '');
$isOnboard = ($params['onboard'] ?? '') === '1';
$username = trim((string) ($params['username'] ?? ''));

$clientName = (string) ($client['client_name'] ?? $clientId);

ob_start();
?>
<div class="card-inner" style="margin-top: 0; margin-bottom: 1.25rem;">
    <div style="font-size: 0.85rem; color: #475569; margin-bottom: 0.2rem;">Client Application</div>
    <div style="font-size: 1.05rem; font-weight: 600; color: #0f172a;"><?= htmlspecialchars($clientName) ?></div>
    <div style="font-size: 0.78rem; color: #64748b; word-break: break-all; margin-top: 0.2rem;">
        Redirect: <code><?= htmlspecialchars($redirectUri) ?></code>
    </div>
</div>

<?php if ($step === '2fa'):
    $user = App::userStore()->getByUsername($username);
    $email = (string) ($user['email'] ?? '');
    $maskedEmail = App::twoFactor()->maskEmail($email);
?>
    <div class="alert alert-info">
        <strong>New Device Detected</strong><br>
        A 6-digit verification code has been sent to <strong><?= htmlspecialchars($maskedEmail) ?></strong>.
    </div>

    <form method="post" action="/oauth/authorize.php">
        <?php foreach ($authParams as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
        <input type="hidden" name="verify_2fa" value="1">

        <label style="text-align: center; margin-bottom: 1.25rem;">
            6-Digit Verification Code
            <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="000000" style="letter-spacing: 8px; font-size: 1.6rem; text-align: center; font-weight: bold; width: 14rem; margin: 0.5rem auto 0; display: block;">
        </label>

        <label style="display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; font-weight: normal; margin-bottom: 1.25rem;">
            <input type="checkbox" name="trust_device" value="1" checked style="width: auto; margin: 0;">
            Trust this device for 90 days
        </label>

        <button type="submit">Verify & Authorize</button>
    </form>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-card);">
        <form method="post" action="/oauth/authorize.php" style="margin: 0;">
            <?php foreach ($authParams as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
            <input type="hidden" name="resend_2fa" value="1">
            <button type="submit" class="secondary" style="width: auto; padding: 0.4rem 0.85rem; font-size: 0.85rem;">Resend Code</button>
        </form>
        <a href="/oauth/authorize.php?<?= http_build_query($authParams) ?>" style="font-size: 0.88rem;">Different Account</a>
    </div>

<?php elseif ($step === 'set_email'): ?>
    <div class="alert alert-warning">
        <strong>Email Address Required</strong><br>
        Please enter an email address for two-factor authentication before completing authorization.
    </div>

    <form method="post" action="/oauth/authorize.php">
        <?php foreach ($authParams as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
        <input type="hidden" name="set_email_2fa" value="1">

        <label>
            Email Address
            <input type="email" name="email" required autofocus autocomplete="email" placeholder="you@example.com">
        </label>

        <button type="submit">Save Email & Send Code</button>
    </form>

<?php elseif ($isOnboard): ?>
    <form method="post" action="/oauth/authorize.php">
        <?php foreach ($authParams as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="onboard" value="1">
        <input type="hidden" name="existing_username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">

        <?php if ($username !== ''): ?>
            <p class="hint">Setting up account for <strong><?= htmlspecialchars($username) ?></strong>.</p>
        <?php else: ?>
            <label>
                Username
                <input type="text" name="new_username" required autofocus autocomplete="username">
            </label>
        <?php endif; ?>

        <label>
            Email Address
            <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
        </label>
        <label>
            Password (min 8 characters)
            <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
        </label>
        <label>
            Confirm Password
            <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
        </label>

        <button type="submit">Activate & Authorize</button>
    </form>

<?php elseif ($step === 'password' && $username !== ''): ?>
    <form method="post" action="/oauth/authorize.php">
        <?php foreach ($authParams as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">

        <p class="hint" style="margin-bottom: 1.25rem;">
            Authorizing as <strong><?= htmlspecialchars($username) ?></strong>.
        </p>

        <label>
            Password
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>

        <button type="submit">Sign In & Authorize</button>
    </form>
    <p class="hint" style="margin-top: 1.25rem; text-align: center;">
        <a href="/oauth/authorize.php?<?= http_build_query($authParams) ?>">Use a different username</a>
    </p>

<?php else: ?>
    <div id="passkey-error" class="alert alert-error" style="display: none;"></div>

    <form method="post" action="/oauth/authorize.php">
        <?php foreach ($authParams as $k => $v): ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
        <?php endforeach; ?>

        <label>
            Username
            <input type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus autocomplete="username webauthn">
        </label>

        <button type="submit">Continue with Password</button>
        <button type="button" id="passkey-login-btn" class="teal" style="margin-top: 0.6rem;">🔑 Sign in with Passkey</button>
    </form>

    <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border-card); text-align: center;">
        <form method="post" action="/oauth/authorize.php" style="margin: 0;">
            <?php foreach ($authParams as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="anonymous" value="1">
            <button type="submit" class="secondary" style="font-size: 0.9rem;">Continue Anonymously (Public Tools Only)</button>
        </form>
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

        var btn = document.getElementById("passkey-login-btn");
        var errDiv = document.getElementById("passkey-error");

        if (btn) {
            btn.addEventListener("click", async function() {
                if (errDiv) errDiv.style.display = "none";

                if (!window.PublicKeyCredential) {
                    if (errDiv) {
                        errDiv.textContent = "Passkeys are not supported in this browser.";
                        errDiv.style.display = "flex";
                    }
                    return;
                }

                btn.disabled = true;
                btn.textContent = "Verifying Passkey...";

                try {
                    var usernameInput = document.querySelector('input[name="username"]');
                    var username = usernameInput ? usernameInput.value.trim() : "";

                    var optRes = await fetch("/oauth/passkey/options.php", {
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

                    var cred = await navigator.credentials.get({ publicKey: opt });
                    if (!cred) throw new Error("Passkey authentication was canceled");

                    var verifyRes = await fetch("/oauth/passkey/verify.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({
                            id: cred.id,
                            clientDataJSON: bufToB64(cred.response.clientDataJSON),
                            authenticatorData: bufToB64(cred.response.authenticatorData),
                            signature: bufToB64(cred.response.signature),
                            client_id: <?= json_encode($clientId) ?>,
                            redirect_uri: <?= json_encode($redirectUri) ?>,
                            code_challenge: <?= json_encode($challenge) ?>,
                            code_challenge_method: <?= json_encode($challengeMethod) ?>,
                            state: <?= json_encode($state) ?>,
                            scope: <?= json_encode($scope) ?>
                        })
                    });

                    var verifyData = await verifyRes.json();
                    if (verifyRes.ok && verifyData.redirect_url) {
                        window.location.href = verifyData.redirect_url;
                    } else {
                        throw new Error(verifyData.error || "Passkey authentication failed");
                    }
                } catch (e) {
                    btn.disabled = false;
                    btn.textContent = "🔑 Sign in with Passkey";
                    if (errDiv) {
                        errDiv.textContent = e.name === "NotAllowedError" ? "Passkey sign-in was canceled." : e.message;
                        errDiv.style.display = "flex";
                    }
                }
            });
        }
    })();
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();

render_html('Authorize Application', $content, ['subtitle' => 'Sign in to grant client permissions']);
