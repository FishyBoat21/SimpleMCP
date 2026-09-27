<?php

declare(strict_types=1);

/**
 * Passkey Registration Options API (Standalone File-per-API).
 *
 * POST: Generates WebAuthn registration challenge and credential parameters.
 */

require_once __DIR__ . '/../../../bootstrap.php';

use McpServer\Auth\WebAuthn;

start_session();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(405, ['error' => 'Method not allowed. Use POST.']);
}

$username = $_SESSION['username'] ?? null;
if (!is_string($username) || $username === '') {
    json_response(401, ['error' => 'Authentication required']);
}

$user = App::userStore()->getByUsername($username);
if ($user === null) {
    json_response(404, ['error' => 'User not found']);
}

$challenge = random_bytes(32);
$_SESSION['passkey_reg_challenge'] = $challenge;

$existing = App::passkeyStore()->findByUsername($username);
$exclude = array_column($existing, 'id');

$options = WebAuthn::createRegistrationOptions(
    $username,
    (string) ($user['name'] ?? $username),
    app_rp_id(),
    'SimpleMCP',
    $challenge,
    $exclude
);

json_response(200, $options);
