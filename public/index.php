<?php

declare(strict_types=1);

/**
 * SimpleMCP Front Controller / Router.
 *
 * Dispatches clean HTTP URLs to standalone page files (PRG pattern)
 * and standalone API endpoints.
 */

require_once __DIR__ . '/bootstrap.php';

// In PHP built-in web server (php -S), return false for existing static/script files
if (php_sapi_name() === 'cli-server') {
    $reqFile = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    if (is_file($reqFile) && !str_ends_with($reqFile, 'index.php')) {
        return false;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Route map mapping clean URLs to standalone files
$routes = [
    '/account' => __DIR__ . '/account/index.php',
    '/account/' => __DIR__ . '/account/index.php',
    '/account/login' => __DIR__ . '/account/login.php',
    '/account/logout' => __DIR__ . '/account/logout.php',
    '/account/onboard' => __DIR__ . '/account/onboard.php',
    '/account/change-password' => __DIR__ . '/account/change-password.php',
    '/account/update-email' => __DIR__ . '/account/update-email.php',
    '/account/device/revoke' => __DIR__ . '/account/device/revoke.php',
    '/account/2fa/verify' => __DIR__ . '/account/2fa/verify.php',
    '/account/2fa/resend' => __DIR__ . '/account/2fa/resend.php',
    '/account/2fa/set-email' => __DIR__ . '/account/2fa/set-email.php',
    '/account/passkey/register/options' => __DIR__ . '/account/passkey/register/options.php',
    '/account/passkey/register/verify' => __DIR__ . '/account/passkey/register/verify.php',
    '/account/passkey/login/options' => __DIR__ . '/account/passkey/login/options.php',
    '/account/passkey/login/verify' => __DIR__ . '/account/passkey/login/verify.php',
    '/account/passkey/delete' => __DIR__ . '/account/passkey/delete.php',
    '/oauth/authorize' => __DIR__ . '/oauth/authorize.php',
    '/oauth/token' => __DIR__ . '/oauth/token.php',
    '/oauth/register' => __DIR__ . '/oauth/register.php',
    '/oauth/passkey/options' => __DIR__ . '/oauth/passkey/options.php',
    '/oauth/passkey/verify' => __DIR__ . '/oauth/passkey/verify.php',
    '/.well-known/oauth-authorization-server' => __DIR__ . '/.well-known/oauth-authorization-server.php',
    '/.well-known/oauth-protected-resource' => __DIR__ . '/.well-known/oauth-protected-resource.php',
    '/sse' => __DIR__ . '/sse.php',
    '/message' => __DIR__ . '/message.php',
    '/mcp' => __DIR__ . '/mcp.php',
];

// Root endpoint dispatch
if ($path === '/' || $path === '') {
    if ($method === 'POST') {
        require __DIR__ . '/mcp.php';
        exit;
    }
    // Browser visiting root via GET redirects to account dashboard
    redirect('/account/index.php');
}

// Check exact clean route
if (isset($routes[$path])) {
    require $routes[$path];
    exit;
}

// Check if a direct .php file exists for the path
$candidate = __DIR__ . $path . '.php';
if (file_exists($candidate) && is_file($candidate)) {
    require $candidate;
    exit;
}

// 404 Not Found
http_response_code(404);
if (str_starts_with($path, '/oauth') || str_starts_with($path, '/.well-known') || str_starts_with($path, '/api')) {
    json_response(404, ['error' => 'not_found', 'error_description' => 'The requested endpoint does not exist.']);
} else {
    require_once __DIR__ . '/layout.php';
    render_html('404 Not Found', '<div class="alert alert-error">Page not found: <code>' . htmlspecialchars($path) . '</code></div><p><a href="/account">Return to Dashboard</a></p>');
}
