<?php

declare(strict_types=1);

/**
 * Two-Factor OTP Resend Action (PRG Pattern).
 *
 * POST: Sends new OTP code and redirects to verification page.
 */

require_once __DIR__ . '/../../bootstrap.php';

start_session();

$pending = $_SESSION['2fa_pending'] ?? null;
if (!is_array($pending) || empty($pending['username'])) {
    redirect('/account/login.php');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid security token.');
        redirect('/account/2fa/verify.php');
    }

    $username = (string) $pending['username'];
    $user = App::userStore()->getByUsername($username);
    $email = (string) ($user['email'] ?? $pending['email'] ?? '');

    if ($email !== '') {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        App::twoFactor()->sendOtp($username, $email, $ua, $ip);
        flash_set('msg', 'A new verification code has been sent to your email.');
    } else {
        flash_set('error', 'No email address on file.');
    }
}

redirect('/account/2fa/verify.php');
