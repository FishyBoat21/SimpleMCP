<?php

declare(strict_types=1);

/**
 * Account Dashboard Page (File-per-Page with PRG Pattern).
 *
 * GET: Displays account profile, passkey registration, trusted devices,
 *      email settings, password update, and logout.
 */

require_once __DIR__ . '/../layout.php';

start_session();

// Authentication required
$username = $_SESSION['username'] ?? null;
if (!is_string($username) || $username === '') {
    redirect('/account/login.php');
}

$row = App::userStore()->getByUsername($username);
if ($row === null) {
    $_SESSION = [];
    session_destroy();
    flash_set('error', 'Your account no longer exists.');
    redirect('/account/login.php');
}

$user = App::userStore()->toUserContext($row);
$userPasskeys = App::passkeyStore()->findByUsername($user->username);
$trustedDevices = App::twoFactor()->getTrustedDevices($user->username);

ob_start();
?>
<h2>Your Account Profile</h2>
<dl class="user-grid">
    <dt>Username</dt>
    <dd><strong><?= htmlspecialchars($user->username) ?></strong></dd>

    <dt>Name</dt>
    <dd><?= htmlspecialchars($user->name) ?></dd>

    <dt>Email</dt>
    <dd><?= htmlspecialchars((string) ($row['email'] ?? 'Not set')) ?></dd>

    <dt>Roles</dt>
    <dd>
        <?php foreach ($user->roles as $role): ?>
            <span class="badge <?= $role === 'admin' ? 'admin' : 'user' ?>"><?= htmlspecialchars($role) ?></span>
        <?php endforeach; ?>
    </dd>

    <dt>Permissions</dt>
    <dd>
        <?php if (empty($user->permissions)): ?>
            <span class="hint">None</span>
        <?php else: ?>
            <?php foreach ($user->permissions as $perm): ?>
                <span class="badge"><?= htmlspecialchars($perm) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>
    </dd>
</dl>

<h2>Email Address (for 2FA)</h2>
<p class="hint">Two-factor authentication codes and security notifications are sent to this address.</p>
<form method="post" action="/account/update-email.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>
        Email Address
        <input type="email" name="email" value="<?= htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES) ?>" required autocomplete="email" placeholder="you@example.com">
    </label>
    <button type="submit" class="teal" style="width: auto; padding: 0.55rem 1.25rem;">Update Email</button>
</form>

<h2>Passkeys (Biometrics / Security Keys)</h2>
<?php if (empty($userPasskeys)): ?>
    <p class="hint">No passkeys registered yet. Add a passkey to sign in without a password using Touch ID, Face ID, Windows Hello, or a hardware key.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Created</th>
                <th>Last Used</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($userPasskeys as $pk): ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $pk['name']) ?></strong></td>
                    <td><?= date('Y-m-d', (int) $pk['created_at']) ?></td>
                    <td><?= $pk['last_used_at'] ? date('Y-m-d H:i', (int) $pk['last_used_at']) : 'Never' ?></td>
                    <td style="text-align: right;">
                        <form method="post" action="/account/passkey/delete.php" style="margin: 0; display: inline;">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string) $pk['id'], ENT_QUOTES) ?>">
                            <button type="submit" class="danger" onclick="return confirm('Delete this passkey?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="card-inner">
    <h3>Register New Passkey</h3>
    <div id="reg-status" class="alert" style="display: none; margin-bottom: 0.8rem;"></div>
    <label>
        Device / Key Label
        <input type="text" id="passkey-name" value="My Passkey" placeholder="e.g. MacBook Touch ID, Windows Hello">
    </label>
    <button type="button" id="reg-passkey-btn" class="teal" style="width: auto; padding: 0.6rem 1.25rem;">🔑 Add Passkey</button>
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
            statusDiv.className = "alert";

            if (window.location.hostname === "127.0.0.1" || window.location.hostname === "::1") {
                window.location.hostname = "localhost";
                return;
            }

            if (/^(\d{1,3}\.){3}\d{1,3}$/.test(window.location.hostname)) {
                statusDiv.className = "alert alert-error";
                statusDiv.textContent = "WebAuthn passkeys cannot be registered on a raw IP address (" + window.location.hostname + "). Please access using http://localhost:" + (window.location.port || "8000") + ".";
                statusDiv.style.display = "flex";
                return;
            }

            if (!window.PublicKeyCredential) {
                statusDiv.className = "alert alert-error";
                statusDiv.textContent = "WebAuthn / Passkeys are not supported in this browser.";
                statusDiv.style.display = "flex";
                return;
            }

            btn.disabled = true;
            btn.textContent = "Registering...";

            try {
                var optRes = await fetch("/account/passkey/register/options.php", {
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
                var verifyRes = await fetch("/account/passkey/register/verify.php", {
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
                    window.location.href = "/account/index.php?msg=" + encodeURIComponent("Passkey registered successfully!");
                } else {
                    throw new Error(resData.error || "Failed to verify passkey registration");
                }
            } catch (e) {
                statusDiv.className = "alert alert-error";
                statusDiv.textContent = e.name === "NotAllowedError" ? "Passkey setup canceled or timed out." : e.message;
                statusDiv.style.display = "flex";
                btn.disabled = false;
                btn.textContent = "🔑 Add Passkey";
            }
        });
    }
})();
</script>

<h2>Trusted Devices (2FA)</h2>
<p class="hint">Devices listed here bypass email 2FA verification for 90 days.</p>
<?php if (empty($trustedDevices)): ?>
    <p class="hint">No trusted devices registered. Unrecognized browsers will prompt for email 2FA verification.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Device</th>
                <th>Last Active</th>
                <th>IP</th>
                <th style="text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($trustedDevices as $dev): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars((string) $dev['name']) ?></strong>
                        <div style="font-size:0.75rem;color:#64748b;"><?= htmlspecialchars(substr((string) ($dev['user_agent'] ?? ''), 0, 45)) ?></div>
                    </td>
                    <td><?= $dev['last_used_at'] ? date('Y-m-d H:i', (int) $dev['last_used_at']) : 'Never' ?></td>
                    <td><?= htmlspecialchars((string) ($dev['ip_address'] ?? '')) ?></td>
                    <td style="text-align: right;">
                        <form method="post" action="/account/device/revoke.php" style="margin: 0; display: inline;">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string) $dev['id'], ENT_QUOTES) ?>">
                            <button type="submit" class="danger" onclick="return confirm('Revoke this device? It will require 2FA on the next login.');">Revoke</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Change Password</h2>
<form method="post" action="/account/change-password.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>
        Current Password
        <input type="password" name="old_password" required autocomplete="current-password">
    </label>
    <label>
        New Password (min 8 characters)
        <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
    </label>
    <button type="submit" style="width: auto; padding: 0.55rem 1.25rem;">Change Password</button>
</form>

<div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-card);">
    <form method="post" action="/account/logout.php" style="margin: 0;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="submit" class="secondary" style="width: auto; padding: 0.55rem 1.25rem;">Log Out</button>
    </form>
</div>
<?php
$content = ob_get_clean();

render_html('My Account', $content, ['maxWidth' => '40rem', 'subtitle' => 'Manage your profile, credentials, and security devices']);
