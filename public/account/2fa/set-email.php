<?php

declare(strict_types=1);

/**
 * Two-Factor Set Email Page (File-per-Page with PRG Pattern).
 *
 * GET:  Renders email entry form when user has no email configured for 2FA.
 * POST: Validates email, updates account, sends OTP, and redirects (PRG).
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
        flash_set('error', 'Invalid security token.');
        redirect('/account/2fa/set-email.php');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Please enter a valid email address.');
        redirect('/account/2fa/set-email.php');
    }

    $ok = App::userStore()->updateEmail($username, $email);
    if (!$ok) {
        flash_set('error', 'Failed to update email address. Please try again.');
        redirect('/account/2fa/set-email.php');
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    App::twoFactor()->sendOtp($username, $email, $ua, $ip);

    $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
    flash_set('msg', 'Email updated. A verification code has been sent.');
    redirect('/account/2fa/verify.php');
}

// ---- GET Request ------------------------------------------------------------

ob_start();
?>
<div class="alert alert-warning">
    <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
    </svg>
    <div>
        <strong>Email Address Required</strong><br>
        Every user account must have an email configured for two-factor authentication on new devices.
    </div>
</div>

<form method="post" action="/account/2fa/set-email.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

    <label>
        Email Address
        <input type="email" name="email" required autofocus autocomplete="email" placeholder="you@example.com">
    </label>

    <button type="submit">Save Email & Send Code</button>
</form>

<p class="hint" style="margin-top: 1.25rem; text-align: center;">
    <a href="/account/login.php">Cancel sign-in</a>
</p>
<?php
$content = ob_get_clean();

render_html('Configure 2FA Email', $content, ['subtitle' => 'Provide a verified email address for account protection']);
