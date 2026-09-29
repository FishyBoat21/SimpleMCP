<?php

declare(strict_types=1);

/**
 * OAuth 2.0 Protected Resource Metadata (RFC 9728) (Standalone File-per-API).
 *
 * GET: Returns JSON metadata identifying the resource origin and authorization server.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(405, ['error' => 'Method not allowed. Use GET.']);
}

$origin = app_origin();

json_response(200, [
    'resource' => $origin,
    'authorization_servers' => [$origin],
    'scopes_supported' => [],
]);
