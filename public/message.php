<?php

declare(strict_types=1);

/**
 * MCP SSE Message Receiver Endpoint (Standalone File-per-API).
 *
 * POST: Receives JSON-RPC messages from clients connected to the SSE transport.
 */

require_once __DIR__ . '/bootstrap.php';

use McpServer\Auth\DebugLog;
use McpServer\UserContext;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionId = (string) ($_GET['sessionId'] ?? ($_SERVER['HTTP_MCP_SESSION_ID'] ?? ''));

if ($method !== 'POST') {
    json_response(405, ['error' => 'Method Not Allowed. Use POST to send MCP messages.']);
}

// Authentication
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;

if ($token === null) {
    $user = UserContext::anonymous();
} else {
    $user = resolve_bearer_user();
    if ($user === null) {
        DebugLog::write("MCP-SSE message token=present => 401 INVALID_TOKEN");
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

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);
$rpcMethod = is_array($decoded) ? (string) ($decoded['method'] ?? '?') : 'invalid-json';

DebugLog::write("MCP-SSE message sessionId={$sessionId} rpc={$rpcMethod} user={$user->username}");

$response = App::mcpServer()->handleRequest($rawBody, $user);

http_response_code(200);
send_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
if ($sessionId !== '') {
    header('Mcp-Session-Id: ' . $sessionId);
}

if ($response !== null) {
    echo $response;
}
