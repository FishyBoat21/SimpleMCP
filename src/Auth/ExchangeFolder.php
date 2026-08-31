<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * The "exchange folder" — a local drop-zone (default data/exchange) that bridges
 * files on disk into the RAG knowledge base.
 *
 * The drop-zone workflow: the user drags a file into the exchange folder (which
 * `open_exchange_folder` pops up in the OS file manager), then `ingest_document`
 * reads that file straight off disk and chunks it into the document store. The
 * exchange folder's root is therefore exactly "files not yet ingested": a
 * successful ingest moves the file into the processed/ subfolder, so the root
 * only ever holds files still waiting.
 *
 * Reads are confined to the folder: filenames containing path separators or
 * other unsafe characters are rejected, so a malicious filename can't reach
 * outside the exchange directory.
 */
readonly class ExchangeFolder {
    public const PROCESSED_SUBDIR = 'processed';

    private string $dir;

    public function __construct(?string $dir = null) {
        $dir ??= dirname(__DIR__, 2) . '/data/exchange';
        // Normalize to the OS separator so displayed paths are consistent.
        $this->dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
    }

    /** The exchange folder's absolute path, created on demand. */
    public function path(): string {
        $this->ensureDir($this->dir);
        return $this->dir;
    }

    /** The processed/ subfolder's absolute path, created on demand. */
    public function processedPath(): string {
        $processed = $this->dir . DIRECTORY_SEPARATOR . self::PROCESSED_SUBDIR;
        $this->ensureDir($processed);
        return $processed;
    }

    /**
     * Resolve a top-level filename to an absolute path, or null when the name is
     * unsafe or the file isn't in the folder. Only plain filenames pass — any
     * path separator or Windows-unsafe character is rejected, which is what
     * keeps reads inside the exchange directory.
     */
    public function resolve(string $filename): ?string {
        $filename = trim($filename);
        if (!self::isSafeName($filename)) {
            return null;
        }
        $path = $this->dir . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) ? $path : null;
    }

    /**
     * Files still sitting in the exchange root — i.e. not yet ingested.
     *
     * @return array<int, array{filename: string, size: int, modifiedAt: string}>
     */
    public function files(): array {
        return $this->scan($this->dir);
    }

    /** Files already moved to processed/ — i.e. successfully ingested. */
    public function processedFiles(): array {
        return is_dir($this->dir . DIRECTORY_SEPARATOR . self::PROCESSED_SUBDIR)
            ? $this->scan($this->processedPath())
            : [];
    }

    /**
     * Open the folder in the OS file manager (Explorer on Windows) so the user
     * can drop a file into it.
     *
     * @return array{ok: bool, message: string}
     */
    public function open(): array {
        $dir = $this->path();
        $opener = match (PHP_OS_FAMILY) {
            'Windows' => 'explorer',
            'Darwin' => 'open',
            default => 'xdg-open',
        };
        // No stderr redirect: exec() on Windows launches explorer directly via
        // CreateProcess (no shell), so a bare "2>&1" would be passed to it as a
        // path. Explorer is a GUI app whose exit code is unreliable anyway.
        $output = [];
        $exitCode = 0;
        exec($opener . ' ' . escapeshellarg($dir), $output, $exitCode);
        $ok = PHP_OS_FAMILY === 'Windows' || $exitCode === 0;
        return [
            'ok' => $ok,
            'message' => ($ok ? 'Opened' : 'Could not open') . " the exchange folder: {$dir}"
                . ($ok ? '' : ' (file manager unavailable)'),
        ];
    }

    /**
     * Move a successfully ingested file into processed/ so it won't be re-read.
     * Returns the destination path, or null when the source file is gone.
     */
    public function consume(string $filename): ?string {
        $src = $this->resolve($filename);
        if ($src === null) {
            return null;
        }
        $processed = $this->processedPath();
        $dest = $processed . DIRECTORY_SEPARATOR . $filename;
        for ($i = 1; file_exists($dest); $i++) {
            $info = pathinfo($filename);
            $dest = $processed . DIRECTORY_SEPARATOR . $info['filename'] . '-' . $i
                . (($info['extension'] ?? '') !== '' ? '.' . $info['extension'] : '');
        }
        return rename($src, $dest) ? $dest : null;
    }

    /**
     * List regular files in a directory as {filename, size, modifiedAt}.
     *
     * @return array<int, array{filename: string, size: int, modifiedAt: string}>
     */
    private function scan(string $dir): array {
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue; // skip processed/ and anything else non-file
            }
            $entries[] = [
                'filename' => $name,
                'size' => filesize($path),
                'modifiedAt' => gmdate('c', filemtime($path)),
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp($a['filename'], $b['filename']));
        return $entries;
    }

    private function ensureDir(string $dir): void {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /** A plain filename: no separators, no Windows-reserved or control chars. */
    private static function isSafeName(string $filename): bool {
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return false;
        }
        if (strpbrk($filename, '/\\:*?"<>|') !== false) {
            return false;
        }
        return preg_match('/[\x00-\x1f\x7f]/', $filename) !== 1;
    }
}
