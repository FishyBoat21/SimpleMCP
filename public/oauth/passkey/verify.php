<?php

declare(strict_types=1);

/**
 * OAuth Passkey Login Verify API (Standalone File-per-API).
 *
 * POST: Verifies WebAuthn assertion signature, checks 2FA, issues auth code or redirects.
 */

require_once __DIR__ . '/../../bootstrap.php';

use McpServer\Auth\ClientStore;
use McpServer\Auth\TwoFactorService;
use McpServer\Auth\WebAuthn;

start_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$expectedChallenge = $_SESSION['passkey_oauth_challenge'] ?? null;
if (!is_string($expectedChallenge) || $expectedChallenge === '') {
    json_response(400, ['error' => 'Authentication challenge expired or missing. Please try again.']);
}
unset($_SESSION['passkey_oauth_challenge']);

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
    json_response(400, ['error' => 'Missing required WebAuthn assertion fields.']);
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

// Validate OAuth params
$clientId = (string) ($post['client_id'] ?? $_POST['client_id'] ?? '');
$redirectUri = (string) ($post['redirect_uri'] ?? $_POST['redirect_uri'] ?? '');
$challenge = (string) ($post['code_challenge'] ?? $_POST['code_challenge'] ?? '');
$challengeMethod = (string) ($post['code_challenge_method'] ?? $_POST['code_challenge_method'] ?? 'S256');
$state = (string) ($post['state'] ?? $_POST['state'] ?? '');
$scope = (string) ($post['scope'] ?? $_POST['scope'] ?? '');

$client = App::clientStore()->find($clientId);
if ($client === null || !in_array($redirectUri, $client['redirect_uris'], true) || !ClientStore::isValidRedirectUri($redirectUri)) {
    json_response(400, ['error' => 'Invalid client_id or redirect_uri.']);
}

$username = (string) $user['username'];
$email = (string) ($user['email'] ?? '');

$rawCookie = $_COOKIE[TwoFactorService::DEVICE_COOKIE_NAME] ?? null;
if (!App::twoFactor()->isTrustedDevice($username, $rawCookie)) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($email !== '') {
        App::twoFactor()->sendOtp($username, $email, $ua, $ip);
    }
    $twoFactorUrl = '/oauth/authorize.php?' . http_build_query([
        'step' => '2fa',
        'username' => $username,
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code_challenge' => $challenge,
        'code_challenge_method' => $challengeMethod,
        'state' => $state,
        'scope' => $scope,
    ]);
    json_response(200, ['redirect_url' => $twoFactorUrl]);
}

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

json_response(200, ['redirect_url' => $redirectUri . $sep . http_build_query($params)]);
