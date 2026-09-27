<?php

declare(strict_types=1);

/**
 * OAuth 2.0 Authorization Server Metadata (RFC 8414) (Standalone File-per-API).
 *
 * GET: Returns JSON metadata describing authorization server capabilities and endpoints.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(405, ['error' => 'Method not allowed. Use GET.']);
}

$origin = app_origin();

json_response(200, [
    'issuer' => $origin,
    'authorization_endpoint' => $origin . '/oauth/authorize',
    'token_endpoint' => $origin . '/oauth/token',
    'registration_endpoint' => $origin . '/oauth/register',
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => [],
]);
