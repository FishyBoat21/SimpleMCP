<?php

declare(strict_types=1);

/**
 * MCP Server-Sent Events (SSE) Transport Endpoint (Standalone File-per-API).
 *
 * GET: Initiates SSE stream, sends the endpoint event with sessionId, and keeps alive.
 */

require_once __DIR__ . '/bootstrap.php';

use McpServer\Auth\DebugLog;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    json_response(405, ['error' => 'Method Not Allowed. Use GET to establish an SSE stream.']);
}

// Disable output compression and buffering for real-time SSE streaming
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', '0');
ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_clean();
}

$sessionId = bin2hex(random_bytes(16));
DebugLog::write("SSE connection opened sessionId={$sessionId}");

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Mcp-Session-Id: ' . $sessionId);
send_security_headers();

// Emit the MCP SSE initial endpoint event
$endpointUri = '/message?sessionId=' . $sessionId;
echo "event: endpoint\n";
echo "data: " . $endpointUri . "\n\n";
flush();

// Keep connection open with periodic SSE comments (pings) until client disconnects
// Fast loop with connection_aborted check (up to 30 seconds per request cycle)
$start = time();
while (!connection_aborted() && (time() - $start < 30)) {
    sleep(2);
    echo ": keepalive\n\n";
    flush();
}

DebugLog::write("SSE connection closed sessionId={$sessionId}");
