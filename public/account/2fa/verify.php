<?php

declare(strict_types=1);

/**
 * Two-Factor OTP Verification Page (File-per-Page with PRG Pattern).
 *
 * GET:  Renders 6-digit verification code entry form.
 * POST: Verifies OTP code, trusts device if checked, and redirects (PRG).
 */

require_once __DIR__ . '/../../layout.php';

start_session();

$pending = $_SESSION['2fa_pending'] ?? null;
if (!is_array($pending) || empty($pending['username'])) {
    redirect('/account/login.php');
}

$username = (string) $pending['username'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid security token. Please try again.');
        redirect('/account/2fa/verify.php');
    }

    $otp = trim((string) ($_POST['otp'] ?? ''));
    $res = App::twoFactor()->verifyOtp($username, $otp);

    if (!$res['success']) {
        flash_set('error', $res['error'] ?? 'Invalid or expired verification code.');
        redirect('/account/2fa/verify.php');
    }

    // Trust device if selected
    if (!empty($_POST['trust_device'])) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $token = App::twoFactor()->trustDevice($username, $ua, $ip);
        App::twoFactor()->setDeviceCookie($token, is_https());
    }

    $_SESSION['username'] = $username;
    unset($_SESSION['2fa_pending']);
    session_regenerate_id(true);

    flash_set('msg', 'Two-factor authentication verified successfully.');
    redirect('/account/index.php');
}

// ---- GET Request ------------------------------------------------------------

$user = App::userStore()->getByUsername($username);
$email = (string) ($user['email'] ?? $pending['email'] ?? '');
$maskedEmail = App::twoFactor()->maskEmail($email);

ob_start();
?>
<div class="alert alert-info">
    <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M10 2a8 8 0 100 16 8 8 0 000-16zm.75 4.75a.75.75 0 00-1.5 0v5.5a.75.75 0 001.5 0v-5.5zm-.75 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
    </svg>
    <div>
        <strong>New Device Detected</strong><br>
        A 6-digit verification code has been sent to <strong><?= htmlspecialchars($maskedEmail) ?></strong>.
    </div>
</div>

<form method="post" action="/account/2fa/verify.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <label style="text-align: center; margin-bottom: 1.25rem;">
        6-Digit Verification Code
        <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus required placeholder="000000" style="letter-spacing: 8px; font-size: 1.6rem; text-align: center; font-weight: bold; width: 14rem; margin: 0.5rem auto 0; display: block;">
    </label>

    <label style="display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; font-weight: normal; margin-bottom: 1.25rem;">
        <input type="checkbox" name="trust_device" value="1" checked style="width: auto; margin: 0;">
        Trust this device for 90 days (skip 2FA next time)
    </label>

    <button type="submit">Verify & Sign In</button>
</form>

<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-card);">
    <form method="post" action="/account/2fa/resend.php" style="margin: 0;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
        <button type="submit" class="secondary" style="width: auto; padding: 0.4rem 0.85rem; font-size: 0.85rem;">Resend Code</button>
    </form>
    <a href="/account/login.php" style="font-size: 0.88rem;">Cancel / Different Account</a>
</div>
<?php
$content = ob_get_clean();

render_html('Two-Factor Verification', $content, ['subtitle' => 'Confirm your identity to sign in on this device']);
