<?php

declare(strict_types=1);

/**
 * Passkey Login Verification API (Standalone File-per-API).
 *
 * POST: Verifies WebAuthn assertion signature, checks 2FA, logs user in.
 */

require_once __DIR__ . '/../../../bootstrap.php';

use McpServer\Auth\TwoFactorService;
use McpServer\Auth\WebAuthn;

start_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$expectedChallenge = $_SESSION['passkey_auth_challenge'] ?? null;
if (!is_string($expectedChallenge) || $expectedChallenge === '') {
    json_response(400, ['error' => 'Authentication challenge expired. Please retry.']);
}
unset($_SESSION['passkey_auth_challenge']);

$post = [];
$raw = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $post = json_decode($raw, true) ?: [];
} else {
    parse_str($raw, $post);
}

$credId = (string) ($post['id'] ?? $_POST['id'] ?? '');
$clientDataJson = (string) ($post['clientDataJSON'] ?? $_POST['clientDataJSON'] ?? '');
$authData = (string) ($post['authenticatorData'] ?? $_POST['authenticatorData'] ?? '');
$signature = (string) ($post['signature'] ?? $_POST['signature'] ?? '');

if ($credId === '' || $clientDataJson === '' || $authData === '' || $signature === '') {
    json_response(400, ['error' => 'Missing required WebAuthn fields.']);
}

$cred = App::passkeyStore()->findById($credId);
if ($cred === null) {
    json_response(400, ['error' => 'Passkey credential not recognized.']);
}

try {
    $newSignCount = WebAuthn::verifyAuthentication(
        $clientDataJson,
        $authData,
        $signature,
        (string) $cred['public_key'],
        (int) $cred['sign_count'],
        $expectedChallenge,
        app_origin(),
        app_rp_id()
    );
    App::passkeyStore()->updateSignCount($credId, $newSignCount);
} catch (\Throwable $e) {
    json_response(400, ['error' => 'Passkey verification failed: ' . $e->getMessage()]);
}

$user = App::userStore()->getByUsername((string) $cred['username']);
if ($user === null) {
    json_response(400, ['error' => 'User account not found.']);
}

$username = (string) $user['username'];
$email = (string) ($user['email'] ?? '');

if ($email === '') {
    $_SESSION['2fa_pending'] = ['username' => $username, 'reason' => 'missing_email'];
    json_response(200, ['success' => true, 'redirect_url' => '/account/2fa/set-email.php']);
}

$rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
if (!App::twoFactor()->isTrustedDevice($username, $rawCookie)) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    App::twoFactor()->sendOtp($username, $email, $ua, $ip);
    $_SESSION['2fa_pending'] = ['username' => $username, 'email' => $email, 'type' => 'account_login'];
    json_response(200, ['success' => true, 'requires_2fa' => true, 'redirect_url' => '/account/2fa/verify.php']);
}

$_SESSION['username'] = $username;
session_regenerate_id(true);

json_response(200, ['success' => true, 'redirect_url' => '/account/index.php']);
