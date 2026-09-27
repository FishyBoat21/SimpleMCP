<?php

declare(strict_types=1);

/**
 * OAuth Passkey Login Options API (Standalone File-per-API).
 *
 * POST: Generates WebAuthn options for OAuth passkey login.
 */

require_once __DIR__ . '/../../bootstrap.php';

use McpServer\Auth\WebAuthn;

start_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$challenge = random_bytes(32);
$_SESSION['passkey_oauth_challenge'] = $challenge;

$post = [];
$raw = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $post = json_decode($raw, true) ?: [];
} else {
    parse_str($raw, $post);
}

$username = trim((string) ($post['username'] ?? $_POST['username'] ?? ''));
$allowCreds = [];
if ($username !== '') {
    $userCreds = App::passkeyStore()->findByUsername($username);
    $allowCreds = array_column($userCreds, 'id');
}

$options = WebAuthn::createAuthenticationOptions(app_rp_id(), $challenge, $allowCreds);
json_response(200, $options);
