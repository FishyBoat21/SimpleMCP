<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Minimal append-only diagnostics logger for the HTTP/OAuth layer.
 *
 * Writes non-sensitive lines to data/requests.log (gitignored): request method,
 * path, JSON-RPC method, whether a token was present and which user it resolved
 * to, and the outcome code. Never logs tokens, secrets or passwords.
 */
final class DebugLog {
    public static function write(string $message): void {
        $file = dirname(__DIR__, 2) . '/data/requests.log';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
