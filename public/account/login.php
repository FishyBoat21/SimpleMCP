<?php

declare(strict_types=1);

/**
 * Account Login Page (File-per-Page with PRG Pattern).
 *
 * GET:  Renders username or password form and WebAuthn passkey login.
 * POST: Validates input, sets flash error/session, and redirects (PRG).
 */

require_once __DIR__ . '/../layout.php';

use McpServer\Auth\TwoFactorService;

start_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// If already logged in, redirect to dashboard
if (isset($_SESSION['username']) && !empty($_SESSION['username'])) {
    redirect('/account/index.php');
}

// ---- POST Request (PRG Process) ---------------------------------------------
if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid security token. Please try again.');
        redirect('/account/login.php');
    }

    $identifier = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Step 1: Username submitted without password
    if ($password === '') {
        if ($identifier === '') {
            flash_set('error', 'Username is required.');
            redirect('/account/login.php');
        }

        $row = App::userStore()->getByUsername($identifier);
        if ($row === null) {
            flash_set('error', 'No account found for this username.');
            redirect('/account/login.php');
        }

        if (($row['password_hash'] ?? null) === null) {
            redirect('/account/onboard.php?username=' . urlencode((string) $row['username']));
        }

        redirect('/account/login.php?step=password&username=' . urlencode($identifier));
    }

    // Step 2: Username and password submitted
    $user = App::userStore()->authenticate($identifier, $password);
    if ($user === null) {
        flash_set('error', 'Incorrect password.');
        redirect('/account/login.php?step=password&username=' . urlencode($identifier));
    }

    $username = (string) $user['username'];
    $email = (string) ($user['email'] ?? '');

    // Require email for 2FA
    if ($email === '') {
        $_SESSION['2fa_pending'] = ['username' => $username, 'reason' => 'missing_email'];
        redirect('/account/2fa/set-email.php');
    }

    // Check trusted device
    $rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
    if (!App::twoFactor()->isTrustedDevice($username, $rawCookie)) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        App::twoFactor()->sendOtp($username, $email, $ua, $ip);
        $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
        redirect('/account/2fa/verify.php');
    }

    // Device is trusted; login immediately
    $_SESSION['username'] = $username;
    session_regenerate_id(true);
    flash_set('msg', 'Signed in successfully.');
    redirect('/account/index.php');
}

// ---- GET Request (Render View) ----------------------------------------------

$step = $_GET['step'] ?? 'username';
$username = trim((string) ($_GET['username'] ?? ''));

ob_start();
if ($step === 'password' && $username !== '') {
    ?>
    <p class="hint" style="margin-bottom: 1.25rem;">
        Signing in as <strong><?= htmlspecialchars($username) ?></strong>
    </p>
    <form method="post" action="/account/login.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>">

        <label>
            Password
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>

        <button type="submit">Sign In</button>
    </form>
    <p class="hint" style="margin-top: 1.25rem; text-align: center;">
        <a href="/account/login.php">Use a different username</a>
    </p>
    <?php
} else {
    ?>
    <div id="passkey-error" class="alert alert-error" style="display: none;"></div>

    <form method="post" action="/account/login.php">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <label>
            Username
            <input type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus autocomplete="username webauthn">
        </label>

        <button type="submit">Continue with Password</button>
        <button type="button" id="passkey-login-btn" class="teal" style="margin-top: 0.6rem;">🔑 Sign in with Passkey</button>
    </form>

    <p class="hint" style="margin-top: 1.25rem; text-align: center;">
        New user? <a href="/account/onboard.php">Set up your account</a>.
    </p>

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
                    errDiv.textContent = "WebAuthn passkeys cannot be used on a raw IP address (" + window.location.hostname + "). Please access using http://localhost:" + (window.location.port || "8000") + ".";
                    errDiv.style.display = "flex";
                }
                return;
            }

            if (activeAbort) {
                try { activeAbort.abort(); } catch (_) {}
                activeAbort = null;
            }

            var abortCtrl = new AbortController();
            activeAbort = abortCtrl;

            try {
                if (!window.PublicKeyCredential) {
                    if (!conditional && errDiv) {
                        errDiv.textContent = "Passkeys are not supported in this browser.";
                        errDiv.style.display = "flex";
                    }
                    return;
                }

                var usernameInput = document.querySelector('input[name="username"]');
                var username = usernameInput ? usernameInput.value.trim() : "";

                var optRes = await fetch("/account/passkey/login/options.php", {
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

                var req = { publicKey: opt, signal: abortCtrl.signal };
                if (conditional) req.mediation = "conditional";

                var cred = await navigator.credentials.get(req);
                activeAbort = null;
                if (!cred) return;

                var verifyRes = await fetch("/account/passkey/login/verify.php", {
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
                    window.location.href = verifyData.redirect_url || "/account/index.php";
                } else {
                    throw new Error(verifyData.error || "Passkey authentication failed");
                }
            } catch (e) {
                if (e.name === "AbortError" || conditional) return;
                if (errDiv) {
                    errDiv.textContent = e.name === "NotAllowedError" ? "Passkey sign-in was canceled." : e.message;
                    errDiv.style.display = "flex";
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
    </script>
    <?php
}
$content = ob_get_clean();

render_html('Log In', $content, ['subtitle' => 'Sign in to access your SimpleMCP account and tools']);
