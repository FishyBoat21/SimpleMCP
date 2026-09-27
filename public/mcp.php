<?php

declare(strict_types=1);

/**
 * MCP Streamable HTTP JSON-RPC Endpoint (Standalone File-per-API).
 *
 * POST: Handles MCP JSON-RPC 2.0 requests over Streamable HTTP transport with
 *       optional Bearer token authorization and Mcp-Session-Id tracking.
 */

require_once __DIR__ . '/bootstrap.php';

use McpServer\Auth\DebugLog;
use McpServer\UserContext;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/mcp', PHP_URL_PATH) ?? '/mcp';

// Authentication: optional token.
// - Missing token => anonymous user (only public tools)
// - Present & valid token => authenticated user context
// - Present & invalid token => 401 Unauthorized
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;

if ($token === null) {
    $user = UserContext::anonymous();
} else {
    $user = resolve_bearer_user();
    if ($user === null) {
        DebugLog::write("MCP {$method} {$path} token=present => 401 INVALID_TOKEN");
        $origin = app_origin();

        http_response_code(401);
        send_security_headers();
        header('WWW-Authenticate: Bearer resource_metadata="' . $origin . '/.well-known/oauth-protected-resource", error="invalid_token"');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => 'invalid_token',
            'error_description' => 'The access token is invalid or expired. Re-authenticate via OAuth.',
        ]);
        exit;
    }
}

if ($method !== 'POST') {
    DebugLog::write("MCP {$method} {$path} => 405 Method Not Allowed");
    http_response_code(405);
    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Method Not Allowed. Use POST for MCP JSON-RPC or GET /sse for Server-Sent Events transport.',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);
$rpcMethod = is_array($decoded) ? (string) ($decoded['method'] ?? '?') : 'invalid-json';

DebugLog::write("MCP {$method} {$path} rpc={$rpcMethod} token=" . ($token !== null ? 'present' : 'none') . " user={$user->username}");

$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
if ($sessionId === '' && $rpcMethod === 'initialize') {
    $sessionId = bin2hex(random_bytes(16));
}

$response = App::mcpServer()->handleRequest($rawBody, $user);

http_response_code(200);
send_security_headers();
header('Content-Type: application/json; charset=utf-8');
if ($sessionId !== '') {
    header('Mcp-Session-Id: ' . $sessionId);
}

if ($response !== null) {
    $rpc = json_decode($response, true);
    $outcome = is_array($rpc) && isset($rpc['error']['code']) ? (string) $rpc['error']['code'] : 'ok';
    DebugLog::write("MCP result rpc={$rpcMethod} code={$outcome}");
    echo $response;
}
