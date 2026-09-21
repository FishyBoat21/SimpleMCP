<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\Auth\AccountController;
use McpServer\Auth\ClientStore;
use McpServer\Auth\Database;
use McpServer\Auth\DebugLog;
use McpServer\Auth\MountPath;
use McpServer\Auth\OAuthServer;
use McpServer\Auth\TokenStore;
use McpServer\Auth\UserStore;
use McpServer\McpServer;
use McpServer\UserContext;

$server = new McpServer();

// Auto-register tools from the Tools directory automatically!
$server->registerToolsFromDirectory(__DIR__ . '/src/Tools', 'McpServer\\Tools\\');

if (php_sapi_name() === 'cli') {
    $in = fopen('php://stdin', 'r');
    while (($line = fgets($in)) !== false) {
        // Assignment inside condition simplifies control flow
        if ($response = $server->handleRequest(trim($line))) {
            echo $response . "\n";
        }
    }
    fclose($in);
} else {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Hosted under a virtual directory (e.g. IIS "/SimpleMCP")? REQUEST_URI then
    // carries the mount prefix while the routes below are app-relative. Strip it
    // (and any trailing slash) so the route table matches wherever the app runs.
    $mount = MountPath::from($_SERVER);
    $path = MountPath::strip($path, $mount);

    // OAuth + user-management pages share the same SQLite-backed stores.
    $oauthConfig = require __DIR__ . '/config/oauth.php';
    $db = new Database();
    $userStore = new UserStore($db);
    $tokenStore = new TokenStore($db);
    $clientStore = new ClientStore($db, $oauthConfig['clients'] ?? []);
    $oauth = new OAuthServer($userStore, $tokenStore, $clientStore, $oauthConfig);
    $db->seedUsersIfEmpty(require __DIR__ . '/config/users.php');

    $isOAuthPath = in_array($path, [
        '/oauth/authorize', '/oauth/token', '/oauth/register',
        '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource',
    ], true);
    $isAccountPath = str_starts_with($path, '/account');

    if ($isOAuthPath) {
        $rawBody = file_get_contents('php://input');
        sendResponse($oauth->handle($method, $path, $_SERVER, $_GET, $rawBody));
        exit;
    }

    if ($isAccountPath) {
        $sessionDir = __DIR__ . '/data/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0777, true);
        }
        session_save_path($sessionDir);
        session_start();

        $post = [];
        parse_str(file_get_contents('php://input'), $post);
        sendResponse((new AccountController($userStore, $mount))->handle($method, $path, $_GET, $post));
        exit;
    }

    // Everything else is the MCP JSON-RPC endpoint (streamable HTTP transport).
    // Authentication is optional: a missing token means an anonymous user who can
    // only see/call tools without role/permission requirements; a present but
    // invalid/expired token is rejected with 401 + WWW-Authenticate so MCP
    // clients re-authenticate via OAuth.
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;

    if ($token === null) {
        $user = UserContext::anonymous();
    } else {
        $user = $oauth->resolveUser($token);
        if ($user === null) {
            DebugLog::write("MCP {$method} {$path} token=present => 401 INVALID_TOKEN");
            http_response_code(401);
            header('WWW-Authenticate: Bearer resource_metadata="' . $oauth->origin($_SERVER) . '/.well-known/oauth-protected-resource", error="invalid_token"');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['error' => 'invalid_token', 'error_description' => 'The access token is invalid or expired. Re-authenticate via OAuth.']);
            exit;
        }
    }

    if ($method !== 'POST') {
        DebugLog::write("MCP {$method} {$path} => 405");
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);
    $rpcMethod = is_array($decoded) ? (string) ($decoded['method'] ?? '?') : 'invalid-json';
    DebugLog::write("MCP {$method} {$path} rpc={$rpcMethod} token=" . ($token !== null ? 'present' : 'none') . ' user=' . $user->username);

    // Streamable HTTP: honor a client-provided session id; mint one on
    // initialize so clients that require Mcp-Session-Id (e.g. Cherry Studio)
    // get a session. The server itself is stateless, so we echo it back without
    // storing anything, and remain lenient if a request omits it.
    $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
    if ($sessionId === '' && $rpcMethod === 'initialize') {
        $sessionId = bin2hex(random_bytes(16));
    }

    $response = $server->handleRequest($rawBody, $user);
    header('Content-Type: application/json; charset=utf-8');
    if ($sessionId !== '') {
        header('Mcp-Session-Id: ' . $sessionId);
    }
    if ($response !== null) {
        $rpc = json_decode($response, true);
        $outcome = $rpc['error']['code'] ?? 'ok';
        DebugLog::write("MCP result rpc={$rpcMethod} code={$outcome}");
        echo $response;
    }
}

/**
 * Emit a `{status, headers, body}` response produced by the controllers.
 *
 * @param array{status: int, headers: array<string, string>, body: string} $response
 */
function sendResponse(array $response): void {
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($response['body'] !== '') {
        echo $response['body'];
    }
}
