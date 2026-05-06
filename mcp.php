<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\McpServer;
use McpServer\Auth\OAuthServer;
use McpServer\Auth\BearerTokenMiddleware;

$server = new McpServer();

// Auto-register tools from the Tools directory automatically!
$server->registerToolsFromDirectory(__DIR__ . '/src/Tools', 'McpServer\\Tools\\');

if (php_sapi_name() === 'cli') {
    // CLI mode: no auth required (used by local MCP tooling)
    $in = fopen('php://stdin', 'r');
    while (($line = fgets($in)) !== false) {
        if ($response = $server->handleRequest(trim($line))) {
            echo $response . "\n";
        }
    }
    fclose($in);
} else {
    header('Content-Type: application/json');

    // Expose OAuth2 metadata for MCP client discovery
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['PATH_INFO'] ?? '') === '/.well-known/oauth-authorization-server') {
        $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        echo json_encode([
            'issuer'                                => $base . '',
            'authorization_endpoint'                => $base . '/authorize',
            'token_endpoint'                        => $base . '/token',
            'revocation_endpoint'                   => $base . '/revoke',
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256', 'plain'],
            'scopes_supported'                      => ['mcp', 'profile', 'offline_access'],
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
        exit;
    }

    // ── Require a valid OAuth2 Bearer token for all HTTP MCP requests ──
    $middleware = new BearerTokenMiddleware(new OAuthServer());
    $middleware->authenticate(['mcp']); // exits with 401 if invalid

    if ($response = $server->handleRequest(file_get_contents('php://input'))) {
        echo $response;
    }
}
