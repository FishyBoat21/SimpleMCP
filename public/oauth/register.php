<?php

declare(strict_types=1);

/**
 * OAuth 2.0 Dynamic Client Registration (RFC 7591) Endpoint (Standalone File-per-API).
 *
 * POST: Registers dynamic OAuth clients and returns client_id / client_secret credentials.
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_response(405, ['error' => 'invalid_request', 'error_description' => 'Client registration requires POST.']);
}

$config = App::oauthConfig();
$expectedToken = $config['registration_access_token'] ?? null;
if (is_string($expectedToken) && $expectedToken !== '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
    if ($token === null || !hash_equals($expectedToken, $token)) {
        json_response(401, [
            'error' => 'invalid_token',
            'error_description' => 'A valid initial access token is required to register clients.',
        ]);
    }
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(400, [
        'error' => 'invalid_client_metadata',
        'error_description' => 'Request body must be a valid JSON object.',
    ]);
}

try {
    $client = App::clientStore()->register($payload);
} catch (InvalidArgumentException $e) {
    json_response(400, [
        'error' => 'invalid_client_metadata',
        'error_description' => $e->getMessage(),
    ]);
}

json_response(201, $client);
