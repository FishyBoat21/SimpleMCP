<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Derives the app's mount prefix from a request URL.
 *
 * The app can be hosted at the site root (e.g. `http://host/`) or under a
 * virtual directory / subfolder (e.g. IIS `http://host/SimpleMCP/`). In the
 * latter case `REQUEST_URI` carries the mount prefix but all routes, metadata
 * URLs and page links are relative to the app root. Any of this app's route
 * segments (`.well-known/`, `oauth/`, `account`) can only appear after the
 * mount prefix, so the text before the first one *is* the mount.
 */
final class MountPath {
    /** Route segments that can only originate from this app. */
    private const ROUTE_SEGMENTS = ['/.well-known/', '/oauth/', '/account'];

    /**
     * The mount prefix for the current request (e.g. `/SimpleMCP`), or `''`
     * when the app is hosted at the site root.
     *
     * @param array<string, mixed> $server  $_SERVER
     */
    public static function from(array $server): string {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/';
        foreach (self::ROUTE_SEGMENTS as $segment) {
            $pos = strpos($path, $segment);
            if ($pos !== false) {
                return rtrim(substr($path, 0, $pos), '/');
            }
        }
        // No route segment in the URL (e.g. plain MCP JSON-RPC POSTs to the
        // base URL "/SimpleMCP/"): fall back to the script path, which
        // IIS/Apache set relative to the mount (e.g. "/SimpleMCP/index.php").
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return ($base === '' || $base === '/' || $base === '.' || !str_starts_with($base, '/')) ? '' : $base;
    }

    /**
     * Remove the mount prefix from a request path, leaving the app-relative
     * route (e.g. `/oauth/token`). Also tolerates a trailing slash, which some
     * MCP clients append to the discovery URL.
     */
    public static function strip(string $path, string $mount): string {
        if ($mount !== '' && str_starts_with($path, $mount . '/')) {
            $path = substr($path, strlen($mount));
        }
        return '/' . ltrim(rtrim($path, '/'), '/');
    }
}
