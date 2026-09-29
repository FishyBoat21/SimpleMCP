<?php

declare(strict_types=1);

/**
 * Passkey Registration Verification API (Standalone File-per-API).
 *
 * POST: Verifies WebAuthn attestation response and stores credential in database.
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

$expectedChallenge = $_SESSION['passkey_reg_challenge'] ?? null;
if (!is_string($expectedChallenge) || $expectedChallenge === '') {
    json_response(400, ['error' => 'Registration challenge expired. Please retry.']);
}
unset($_SESSION['passkey_reg_challenge']);

$post = [];
$raw = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $post = json_decode($raw, true) ?: [];
} else {
    parse_str($raw, $post);
}

$clientDataJson = (string) ($post['clientDataJSON'] ?? $_POST['clientDataJSON'] ?? '');
$attestationObject = (string) ($post['attestationObject'] ?? $_POST['attestationObject'] ?? '');
$passkeyName = trim((string) ($post['name'] ?? $_POST['name'] ?? 'Passkey'));

if ($clientDataJson === '' || $attestationObject === '') {
    json_response(400, ['error' => 'Missing registration payload']);
}

try {
    $regResult = WebAuthn::verifyRegistration(
        $clientDataJson,
        $attestationObject,
        $expectedChallenge,
        app_origin(),
        app_rp_id()
    );

    App::passkeyStore()->create(
        $regResult['credential_id'],
        $username,
        $regResult['public_key_pem'],
        $regResult['sign_count'],
        $regResult['aaguid'],
        $passkeyName !== '' ? $passkeyName : 'Passkey'
    );
} catch (\Throwable $e) {
    json_response(400, ['error' => 'Passkey registration failed: ' . $e->getMessage()]);
}

json_response(200, ['success' => true]);
