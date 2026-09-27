<?php

declare(strict_types=1);

/**
 * SimpleMCP Application Bootstrap.
 *
 * Initializes autoloader, database, stores, services, session handling,
 * and common helper functions for standalone pages and APIs.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\ClientStore;
use McpServer\Auth\Database;
use McpServer\Auth\DebugLog;
use McpServer\Auth\EmailService;
use McpServer\Auth\PasskeyStore;
use McpServer\Auth\TokenStore;
use McpServer\Auth\TwoFactorService;
use McpServer\Auth\UserStore;
use McpServer\Auth\WebAuthn;
use McpServer\McpServer;
use McpServer\UserContext;

// 1. WebAuthn / Passkeys prohibit raw IP addresses as RP IDs.
// Redirect loopback IP addresses to 'localhost'.
$rawHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
$hostName = parse_url('http://' . $rawHost, PHP_URL_HOST);
if ($hostName === '127.0.0.1' || $hostName === '::1' || str_starts_with($rawHost, '127.0.0.1') || str_starts_with($rawHost, '[::1]')) {
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if (str_starts_with($reqPath, '/account') || str_starts_with($reqPath, '/oauth/authorize')) {
        $port = parse_url('http://' . $rawHost, PHP_URL_PORT);
        $portStr = $port !== null ? ':' . $port : '';
        $scheme = is_https() ? 'https' : 'http';
        header('Location: ' . $scheme . '://localhost' . $portStr . ($_SERVER['REQUEST_URI'] ?? '/'), true, 307);
        exit;
    }
}

// 2. Global application container
class App {
    private static ?Database $db = null;
    private static ?UserStore $userStore = null;
    private static ?TokenStore $tokenStore = null;
    private static ?ClientStore $clientStore = null;
    private static ?PasskeyStore $passkeyStore = null;
    private static ?EmailService $emailService = null;
    private static ?TwoFactorService $twoFactor = null;
    private static ?McpServer $mcpServer = null;
    private static ?array $oauthConfig = null;
    private static ?array $mailConfig = null;

    public static function rootDir(): string {
        return dirname(__DIR__);
    }

    public static function oauthConfig(): array {
        if (self::$oauthConfig === null) {
            self::$oauthConfig = require self::rootDir() . '/config/oauth.php';
        }
        return self::$oauthConfig;
    }

    public static function mailConfig(): array {
        if (self::$mailConfig === null) {
            $mailFile = self::rootDir() . '/config/mail.php';
            self::$mailConfig = file_exists($mailFile)
                ? require $mailFile
                : (file_exists(self::rootDir() . '/config/mail.example.php') ? require self::rootDir() . '/config/mail.example.php' : []);
        }
        return self::$mailConfig;
    }

    public static function db(): Database {
        if (self::$db === null) {
            self::$db = new Database(self::rootDir() . '/data/app.sqlite');
            $usersFile = self::rootDir() . '/config/users.php';
            if (file_exists($usersFile)) {
                self::$db->seedUsersIfEmpty(require $usersFile);
            }
        }
        return self::$db;
    }

    public static function userStore(): UserStore {
        if (self::$userStore === null) {
            self::$userStore = new UserStore(self::db());
        }
        return self::$userStore;
    }

    public static function tokenStore(): TokenStore {
        if (self::$tokenStore === null) {
            self::$tokenStore = new TokenStore(self::db());
        }
        return self::$tokenStore;
    }

    public static function clientStore(): ClientStore {
        if (self::$clientStore === null) {
            $cfg = self::oauthConfig();
            self::$clientStore = new ClientStore(self::db(), $cfg['clients'] ?? []);
        }
        return self::$clientStore;
    }

    public static function passkeyStore(): PasskeyStore {
        if (self::$passkeyStore === null) {
            self::$passkeyStore = new PasskeyStore(self::db());
        }
        return self::$passkeyStore;
    }

    public static function emailService(): EmailService {
        if (self::$emailService === null) {
            self::$emailService = new EmailService(self::mailConfig());
        }
        return self::$emailService;
    }

    public static function twoFactor(): TwoFactorService {
        if (self::$twoFactor === null) {
            self::$twoFactor = new TwoFactorService(self::db(), self::userStore(), self::emailService());
        }
        return self::$twoFactor;
    }

    public static function mcpServer(): McpServer {
        if (self::$mcpServer === null) {
            self::$mcpServer = new McpServer();
            self::$mcpServer->registerToolsFromDirectory(self::rootDir() . '/src/Tools', 'McpServer\\Tools\\');
        }
        return self::$mcpServer;
    }
}

// 3. Helper functions

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function canonical_issuer(): ?string {
    $cfg = App::oauthConfig();
    return is_string($cfg['issuer'] ?? null) && $cfg['issuer'] !== '' ? $cfg['issuer'] : null;
}

function app_origin(): string {
    $canonical = canonical_issuer();
    if ($canonical !== null) {
        return rtrim($canonical, '/');
    }
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function app_rp_id(): string {
    $host = parse_url(app_origin(), PHP_URL_HOST);
    $host = is_string($host) && $host !== '' ? $host : 'localhost';
    if ($host === '127.0.0.1' || $host === '::1') {
        return 'localhost';
    }
    return $host;
}

function send_security_headers(bool $isHttps = false): void {
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if ($isHttps || is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $sessionDir = App::rootDir() . '/data/sessions';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0777, true);
        }
        session_save_path($sessionDir);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function redirect(string $location, int $status = 303): never {
    send_security_headers();
    header('Location: ' . $location, true, $status);
    exit;
}

function flash_set(string $key, mixed $value): void {
    start_session();
    $_SESSION['_flash'][$key] = $value;
}

function flash_get(string $key, mixed $default = null): mixed {
    start_session();
    if (isset($_SESSION['_flash'][$key])) {
        $val = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $val;
    }
    return $default;
}

function flash_has(string $key): bool {
    start_session();
    return isset($_SESSION['_flash'][$key]);
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_verify(?string $token): bool {
    start_session();
    return isset($_SESSION['csrf']) && is_string($token) && hash_equals((string) $_SESSION['csrf'], $token);
}

function json_response(int $status, array $data, array $extraHeaders = []): never {
    http_response_code($status);
    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resolve Bearer token from authorization headers to a UserContext.
 */
function resolve_bearer_user(): ?UserContext {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = preg_match('/^Bearer\s+(.+)$/i', $auth, $m) ? trim($m[1]) : null;
    if ($token === null) {
        return null;
    }
    $row = App::tokenStore()->findAccessToken($token);
    if ($row === null) {
        return null;
    }
    if ((string) $row['username'] === UserStore::ANONYMOUS_USERNAME) {
        return UserContext::anonymous();
    }
    $user = App::userStore()->getByUsername((string) $row['username']);
    if ($user === null) {
        return null;
    }
    return App::userStore()->toUserContext($user);
}
