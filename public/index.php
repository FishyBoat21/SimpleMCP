<?php

declare(strict_types=1);

/**
 * SimpleMCP Web Document Root (Public HTTP Entry Point).
 *
 * Dedicated HTTP front controller for public and local deployment.
 * Keeps data/, config/, src/, and vendor/ outside the web document root.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\AccountController;
use McpServer\Auth\ClientStore;
use McpServer\Auth\Database;
use McpServer\Auth\DebugLog;
use McpServer\Auth\EmailService;
use McpServer\Auth\MountPath;
use McpServer\Auth\OAuthServer;
use McpServer\Auth\PasskeyStore;
use McpServer\Auth\TokenStore;
use McpServer\Auth\TwoFactorService;
use McpServer\Auth\UserStore;
use McpServer\McpServer;
use McpServer\UserContext;

$server = new McpServer();
$server->registerToolsFromDirectory(dirname(__DIR__) . '/src/Tools', 'McpServer\\Tools\\');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$mount = MountPath::from($_SERVER);
$path = MountPath::strip($path, $mount);

// WebAuthn / Passkeys strictly prohibit raw IP addresses (e.g. 127.0.0.1) as Relying Party IDs.
// Automatically redirect browser visits on loopback IP to 'localhost' so passkeys function seamlessly.
$rawHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
$hostName = parse_url('http://' . $rawHost, PHP_URL_HOST);
if ($hostName === '127.0.0.1' || $hostName === '::1' || str_starts_with($rawHost, '127.0.0.1') || str_starts_with($rawHost, '[::1]')) {
    if (str_starts_with($path, '/account') || str_starts_with($path, '/oauth/authorize')) {
        $port = parse_url('http://' . $rawHost, PHP_URL_PORT);
        $portStr = $port !== null ? ':' . $port : '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        header('Location: ' . $scheme . '://localhost' . $portStr . ($_SERVER['REQUEST_URI'] ?? '/'), true, 307);
        exit;
    }
}

// OAuth + user-management pages share the same SQLite-backed stores.
$oauthConfig = require dirname(__DIR__) . '/config/oauth.php';
$mailConfigFile = dirname(__DIR__) . '/config/mail.php';
$mailConfig = file_exists($mailConfigFile)
    ? require $mailConfigFile
    : (file_exists(dirname(__DIR__) . '/config/mail.example.php') ? require dirname(__DIR__) . '/config/mail.example.php' : []);
$db = new Database(dirname(__DIR__) . '/data/app.sqlite');
$userStore = new UserStore($db);
$tokenStore = new TokenStore($db);
$clientStore = new ClientStore($db, $oauthConfig['clients'] ?? []);
$passkeyStore = new PasskeyStore($db);
$emailService = new EmailService($mailConfig);
$twoFactor = new TwoFactorService($db, $userStore, $emailService);
$canonicalIssuer = $oauthConfig['issuer'] ?? null;

$oauth = new OAuthServer($userStore, $tokenStore, $clientStore, $oauthConfig, $passkeyStore, $twoFactor);
$account = new AccountController($userStore, $passkeyStore, is_string($canonicalIssuer) ? $canonicalIssuer : null, $twoFactor, $mount);
$db->seedUsersIfEmpty(require dirname(__DIR__) . '/config/users.php');

$isOAuthPath = str_starts_with($path, '/oauth') || in_array($path, [
    '/.well-known/oauth-authorization-server',
    '/.well-known/oauth-protected-resource',
], true);
$isAccountPath = str_starts_with($path, '/account');

// Secure Session Cookie Configuration
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$sessionDir = dirname(__DIR__) . '/data/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0777, true);
}
session_save_path($sessionDir);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if ($isAccountPath || str_starts_with($path, '/oauth/authorize') || str_starts_with($path, '/oauth/passkey')) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if ($isOAuthPath) {
    $rawBody = file_get_contents('php://input');
    sendResponse($oauth->handle($method, $path, $_SERVER, $_GET, $rawBody), $isHttps);
    exit;
}

if ($isAccountPath) {
    $post = [];
    $rawBody = file_get_contents('php://input');
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $post = json_decode($rawBody, true) ?: [];
    } else {
        parse_str($rawBody, $post);
    }
    sendResponse($account->handle($method, $path, $_GET, $post), $isHttps);
    exit;
}

// Everything else is the MCP JSON-RPC endpoint (streamable HTTP transport).
// Authentication is optional: a missing token means an anonymous user who can
// only see/call tools without role/permission requirements; a present but
// invalid/expired token is rejected with 401 + WWW-Authenticate.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;

if ($token === null) {
    $user = UserContext::anonymous();
} else {
    $user = $oauth->resolveUser($token);
    if ($user === null) {
        DebugLog::write("MCP {$method} {$path} token=present => 401 INVALID_TOKEN");
        $origin = is_string($canonicalIssuer) && $canonicalIssuer !== ''
            ? rtrim($canonicalIssuer, '/')
            : ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');

        http_response_code(401);
        header('WWW-Authenticate: Bearer resource_metadata="' . $origin . '/.well-known/oauth-protected-resource", error="invalid_token"');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['error' => 'invalid_token', 'error_description' => 'The access token is invalid or expired. Re-authenticate via OAuth.']);
        exit;
    }
}

if ($method !== 'POST') {
    DebugLog::write("MCP {$method} {$path} => 405");
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody, true);
$rpcMethod = is_array($decoded) ? (string) ($decoded['method'] ?? '?') : 'invalid-json';
DebugLog::write("MCP {$method} {$path} rpc={$rpcMethod} token=" . ($token !== null ? 'present' : 'none') . ' user=' . $user->username);

$sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
if ($sessionId === '' && $rpcMethod === 'initialize') {
    $sessionId = bin2hex(random_bytes(16));
}

$response = $server->handleRequest($rawBody, $user);
header('Content-Type: application/json; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
if ($sessionId !== '') {
    header('Mcp-Session-Id: ' . $sessionId);
}
if ($response !== null) {
    $rpc = json_decode($response, true);
    $outcome = $rpc['error']['code'] ?? 'ok';
    DebugLog::write("MCP result rpc={$rpcMethod} code={$outcome}");
    echo $response;
}

/**
 * Emit a `{status, headers, body}` response produced by the controllers with security headers.
 *
 * @param array{status: int, headers: array<string, string>, body: string} $response
 */
function sendResponse(array $response, bool $isHttps = false): void {
    http_response_code($response['status']);

    $headers = $response['headers'];
    $headers['X-Frame-Options'] ??= 'DENY';
    $headers['X-Content-Type-Options'] ??= 'nosniff';
    $headers['Referrer-Policy'] ??= 'strict-origin-when-cross-origin';
    if ($isHttps) {
        $headers['Strict-Transport-Security'] ??= 'max-age=31536000; includeSubDomains';
    }

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($response['body'] !== '') {
        echo $response['body'];
    }
}
