<?php

declare(strict_types=1);

namespace McpServer;

use McpServer\Auth\SettingsStore;

/**
 * Client for a Stirling-PDF server's Markdown→PDF conversion API.
 *
 * Converts Markdown text into a PDF by posting it to the server's
 * `/api/v1/convert/markdown/pdf` endpoint (multipart form field `fileInput` —
 * the single-input Markdown→PDF operation), returning the PDF bytes. The
 * server location and optional API key come from {@see self::forUser()}
 * resolution:
 *
 *   - stdio (CLI) mode → config/config.php (`stirling_pdf.endpoint` /
 *     `stirling_pdf.api_key`), the global default.
 *   - HTTP mode        → the signed-in account's values saved on the `/account`
 *     page (SettingsStore / `user_settings` table), falling back to
 *     config/config.php for any field the user left blank.
 *
 * Uses PHP streams (`file_get_contents()`) with a hand-built multipart body —
 * requires `allow_url_fopen` (on by default) and no cURL extension.
 */
final class StirlingPdfClient {
    /** The Stirling-PDF endpoint that converts a Markdown file to a PDF. */
    private const CONVERT_PATH = '/api/v1/convert/markdown/pdf';

    /** Header the API key travels in (configurable on the Stirling-PDF side). */
    private const API_KEY_HEADER = 'X-API-KEY';

    /** How long to wait for Stirling-PDF before giving up (seconds). */
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey = '',
    ) {}

    /**
     * Resolve the effective Stirling-PDF settings for the current request —
     * transport-aware, as documented on the class.
     *
     * @return array{endpoint: string, api_key: string}
     */
    public static function forUser(?UserContext $user = null): array {
        $defaults = self::globalDefaults();

        // stdio mode has no account page: use the global config as-is.
        if (PHP_SAPI === 'cli') {
            return $defaults;
        }

        // HTTP mode: signed-in account settings win; blank fields fall back.
        if ($user !== null && $user->username !== '') {
            $store = new SettingsStore();
            $endpoint = (string) $store->getSetting($user->username, 'stirling_pdf_endpoint', '');
            $apiKey = (string) $store->getSetting($user->username, 'stirling_pdf_api_key', '');
            return [
                'endpoint' => $endpoint !== '' ? $endpoint : $defaults['endpoint'],
                'api_key' => $apiKey !== '' ? $apiKey : $defaults['api_key'],
            ];
        }

        return $defaults;
    }

    /**
     * Convert Markdown text into a PDF.
     *
     * @param string $markdown the Markdown source
     * @param string $filename uploaded filename (extension drives Stirling's markdown detection)
     * @return array<string, mixed> { ok: true, ... } with `pdf` (bytes), or
     *         { ok: false, error } with a human-readable message
     */
    public function convertMarkdownToPdf(string $markdown, string $filename = 'document.md'): array {
        $endpoint = rtrim($this->endpoint, '/');
        if ($endpoint === '') {
            return ['ok' => false, 'error' => 'No Stirling-PDF endpoint configured. Set it on the /account page (HTTP) or in config/config.php (stdio).'];
        }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint)) {
            return ['ok' => false, 'error' => "Stirling-PDF endpoint must be an http(s) URL, got \"{$this->endpoint}\"."];
        }
        if (trim($markdown) === '') {
            return ['ok' => false, 'error' => 'markdown must be a non-empty string.'];
        }
        if ($filename === '') {
            $filename = 'document.md';
        }

        // file_get_contents()/streams can't assemble multipart/form-data for us,
        // so build the body by hand. A random boundary keeps it from colliding
        // with the markdown content.
        $boundary = '----SimpleMCP' . bin2hex(random_bytes(12));
        $body = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="fileInput"; filename="' . self::sanitizeFilename($filename) . "\"\r\n"
            . "Content-Type: text/markdown\r\n"
            . "\r\n"
            . $markdown . "\r\n"
            . '--' . $boundary . "--\r\n";

        $headers = [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Accept: application/octet-stream',
            'Content-Length: ' . strlen($body),
        ];
        if ($this->apiKey !== '') {
            $headers[] = self::API_KEY_HEADER . ': ' . $this->apiKey;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => self::TIMEOUT_SECONDS,
            // Read the response body even for 4xx/5xx so the status + message can
            // be parsed from $http_response_header below.
            'ignore_errors' => true,
            'follow_location' => 1,
        ]]);

        // @ suppresses the native warning on connect failures (DNS, refused, ...).
        // $http_response_header is populated by PHP streams after the call.
        $response = @file_get_contents($endpoint . self::CONVERT_PATH, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($response === false) {
            return ['ok' => false, 'error' => "Failed to reach Stirling-PDF at {$endpoint}. Check that the URL is correct and the server is reachable."];
        }

        if ($status >= 400) {
            $hint = $status === 401 || $status === 403
                ? ' The server rejected the API key — check it on the /account page (HTTP) or in config/config.php (stdio).'
                : '';
            $snippet = trim(substr((string) $response, 0, 300));
            return ['ok' => false, 'error' => "Stirling-PDF returned HTTP $status$hint" . ($snippet !== '' ? " — $snippet" : '')];
        }

        if ($response === '' || !str_starts_with((string) $response, '%PDF')) {
            return ['ok' => false, 'error' => "Stirling-PDF did not return a PDF (HTTP $status)."];
        }

        return [
            'ok' => true,
            'pdf' => (string) $response,
            'bytes' => strlen((string) $response),
            'mimeType' => 'application/pdf',
            'filename' => $this->pdfName($filename),
            'endpoint' => $endpoint,
        ];
    }

    /** @return array{endpoint: string, api_key: string} */
    private static function globalDefaults(): array {
        $config = require dirname(__DIR__) . '/config/config.php';
        $sp = is_array($config['stirling_pdf'] ?? null) ? $config['stirling_pdf'] : [];
        return [
            'endpoint' => (string) ($sp['endpoint'] ?? ''),
            'api_key' => (string) ($sp['api_key'] ?? ''),
        ];
    }

    /** Derive the output PDF filename from the uploaded markdown's basename. */
    private static function pdfName(string $markdownFilename): string {
        $base = pathinfo($markdownFilename, PATHINFO_FILENAME);
        return ($base !== '' ? $base : 'document') . '.pdf';
    }

    /** Keep the multipart filename header-safe (no quotes or newlines). */
    private static function sanitizeFilename(string $filename): string {
        $clean = str_replace(["\r", "\n", '"'], '', $filename);
        return $clean !== '' ? $clean : 'document.md';
    }
}