<?php

declare(strict_types=1);

namespace McpServer;

use McpServer\Auth\SettingsStore;

/**
 * Client for a Stirling-PDF server's document conversion API.
 *
 * Four conversions are supported, each a Stirling-PDF operation that posts
 * a file as the multipart field `fileInput`:
 *
 *   - {@see self::convertMarkdownToPdf()} → POST /api/v1/convert/markdown/pdf,
 *     which returns the rendered PDF bytes.
 *   - {@see self::convertPdfToMarkdown()} → POST /api/v1/convert/pdf/markdown,
 *     which returns the extracted Markdown text.
 *   - {@see self::convertImageToPdf()}    → POST /api/v1/convert/img/pdf,
 *     which renders image(s) into a PDF document.
 *   - {@see self::convertPdfToImage()}    → POST /api/v1/convert/pdf/img,
 *     which renders a PDF into image file(s) or a ZIP archive.
 *
 * The server location and optional API key come from {@see self::forUser()}
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
    /** The Stirling-PDF endpoint that renders a Markdown file to a PDF. */
    private const MARKDOWN_TO_PDF_PATH = '/api/v1/convert/markdown/pdf';

    /** The Stirling-PDF endpoint that extracts Markdown text from a PDF. */
    private const PDF_TO_MARKDOWN_PATH = '/api/v1/convert/pdf/markdown';

    /** The Stirling-PDF endpoint that renders image(s) to a PDF. */
    private const IMG_TO_PDF_PATH = '/api/v1/convert/img/pdf';

    /** The Stirling-PDF endpoint that converts a PDF to image(s). */
    private const PDF_TO_IMG_PATH = '/api/v1/convert/pdf/img';

    /** Header the API key travels in (configurable on the Stirling-PDF side). */
    private const API_KEY_HEADER = 'X-API-KEY';

    /** How long to wait for Stirling-PDF before giving up (seconds). */
    private const TIMEOUT_SECONDS = 60;

    /** Largest document accepted, to bound memory use (bytes). */
    private const MAX_INPUT_BYTES = 104857600; // 100 MB

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
        if (($error = $this->configurationError()) !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if (trim($markdown) === '') {
            return ['ok' => false, 'error' => 'markdown must be a non-empty string.'];
        }

        $upload = $filename !== '' ? $filename : 'document.md';
        $response = $this->postFile(self::MARKDOWN_TO_PDF_PATH, $upload, 'text/markdown', $markdown);
        if ($response === null) {
            return ['ok' => false, 'error' => $this->unreachableError()];
        }
        if ($response['status'] >= 400) {
            return ['ok' => false, 'error' => $this->httpError($response['status'], $response['body'])];
        }

        if ($response['body'] === '' || !str_starts_with($response['body'], '%PDF')) {
            return ['ok' => false, 'error' => "Stirling-PDF did not return a PDF (HTTP {$response['status']})."];
        }

        return [
            'ok' => true,
            'pdf' => $response['body'],
            'bytes' => strlen($response['body']),
            'mimeType' => 'application/pdf',
            'filename' => self::outputName($upload, 'pdf'),
            'endpoint' => rtrim($this->endpoint, '/'),
        ];
    }

    /**
     * Convert a PDF document into Markdown text.
     *
     * @param string $pdf the raw PDF bytes
     * @param string $filename uploaded filename (extension drives Stirling's PDF detection)
     * @return array<string, mixed> { ok: true, ... } with `markdown` (text), or
     *         { ok: false, error } with a human-readable message
     */
    public function convertPdfToMarkdown(string $pdf, string $filename = 'document.pdf'): array {
        if (($error = $this->configurationError()) !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if ($pdf === '') {
            return ['ok' => false, 'error' => 'pdf must be non-empty PDF data.'];
        }
        if (strlen($pdf) > self::MAX_INPUT_BYTES) {
            $mb = (int) (self::MAX_INPUT_BYTES / 1048576);
            return ['ok' => false, 'error' => "The PDF is larger than the {$mb} MB limit."];
        }
        // The PDF spec allows leading junk before the header, but it must appear
        // early. This catches "that isn't actually a PDF" inputs (an HTML error
        // page, a decodable-but-wrong payload) before they reach the server.
        if (!str_contains(substr($pdf, 0, 1024), '%PDF')) {
            return ['ok' => false, 'error' => 'Input does not look like a PDF (no %PDF header found).'];
        }

        $upload = $filename !== '' ? $filename : 'document.pdf';
        $response = $this->postFile(self::PDF_TO_MARKDOWN_PATH, $upload, 'application/pdf', $pdf);
        if ($response === null) {
            return ['ok' => false, 'error' => $this->unreachableError()];
        }
        if ($response['status'] >= 400) {
            return ['ok' => false, 'error' => $this->httpError($response['status'], $response['body'])];
        }
        if (trim($response['body']) === '') {
            return ['ok' => false, 'error' => "Stirling-PDF returned no Markdown (HTTP {$response['status']}). The PDF may have no extractable text layer (e.g. a scan without OCR)."];
        }

        return [
            'ok' => true,
            'markdown' => $response['body'],
            'bytes' => strlen($response['body']),
            'mimeType' => 'text/markdown',
            'filename' => self::outputName($upload, 'md'),
            'endpoint' => rtrim($this->endpoint, '/'),
        ];
    }

    /**
     * Convert an image into a PDF.
     *
     * @param string $imageData the raw image binary bytes
     * @param string $filename uploaded filename (extension drives Stirling's format detection)
     * @param string $fitOption fit option ('fillPage', 'fitToPage', 'maintainAspectRatio')
     * @param string $colorType color type ('color', 'greyscale', 'black-and-white')
     * @param bool $autoRotate whether to automatically rotate the image
     * @return array<string, mixed> { ok: true, ... } with `pdf` (bytes), or
     *         { ok: false, error } with a human-readable message
     */
    public function convertImageToPdf(
        string $imageData,
        string $filename = 'image.png',
        string $fitOption = 'fillPage',
        string $colorType = 'color',
        bool $autoRotate = false,
    ): array {
        if (($error = $this->configurationError()) !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if ($imageData === '') {
            return ['ok' => false, 'error' => 'image data must be non-empty.'];
        }
        if (strlen($imageData) > self::MAX_INPUT_BYTES) {
            $mb = (int) (self::MAX_INPUT_BYTES / 1048576);
            return ['ok' => false, 'error' => "The image is larger than the {$mb} MB limit."];
        }

        $upload = $filename !== '' ? $filename : 'image.png';
        $ext = strtolower(pathinfo($upload, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        $fields = [
            'fitOption' => $fitOption !== '' ? $fitOption : 'fillPage',
            'colorType' => $colorType !== '' ? $colorType : 'color',
            'autoRotate' => $autoRotate ? 'true' : 'false',
        ];

        $response = $this->postFile(self::IMG_TO_PDF_PATH, $upload, $contentType, $imageData, $fields);
        if ($response === null) {
            return ['ok' => false, 'error' => $this->unreachableError()];
        }
        if ($response['status'] >= 400) {
            return ['ok' => false, 'error' => $this->httpError($response['status'], $response['body'])];
        }

        if ($response['body'] === '' || !str_contains(substr($response['body'], 0, 1024), '%PDF')) {
            return ['ok' => false, 'error' => "Stirling-PDF did not return a PDF (HTTP {$response['status']})."];
        }

        return [
            'ok' => true,
            'pdf' => $response['body'],
            'bytes' => strlen($response['body']),
            'mimeType' => 'application/pdf',
            'filename' => self::outputName($upload, 'pdf'),
            'endpoint' => rtrim($this->endpoint, '/'),
        ];
    }

    /**
     * Convert a PDF document into image(s).
     *
     * @param string $pdf the raw PDF bytes
     * @param string $filename uploaded filename (extension drives Stirling's PDF detection)
     * @param string $imageFormat output image format ('png', 'jpeg', 'jpg', 'gif', 'webp')
     * @param string $singleOrMultiple 'single' or 'multiple'
     * @param string $pageNumbers page numbers or ranges, e.g. 'all', '1', '1,3,5-9'
     * @param string $colorType 'color', 'greyscale', 'blackandwhite'
     * @param int $dpi resolution in DPI
     * @return array<string, mixed> { ok: true, ... } with `data` (image or zip bytes), or
     *         { ok: false, error } with a human-readable message
     */
    public function convertPdfToImage(
        string $pdf,
        string $filename = 'document.pdf',
        string $imageFormat = 'png',
        string $singleOrMultiple = 'single',
        string $pageNumbers = 'all',
        string $colorType = 'color',
        int $dpi = 300,
    ): array {
        if (($error = $this->configurationError()) !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if ($pdf === '') {
            return ['ok' => false, 'error' => 'pdf must be non-empty PDF data.'];
        }
        if (strlen($pdf) > self::MAX_INPUT_BYTES) {
            $mb = (int) (self::MAX_INPUT_BYTES / 1048576);
            return ['ok' => false, 'error' => "The PDF is larger than the {$mb} MB limit."];
        }
        if (!str_contains(substr($pdf, 0, 1024), '%PDF')) {
            return ['ok' => false, 'error' => 'Input does not look like a PDF (no %PDF header found).'];
        }

        $upload = $filename !== '' ? $filename : 'document.pdf';
        $format = strtolower(trim($imageFormat));
        if ($format === '') {
            $format = 'png';
        }

        $fields = [
            'imageFormat' => $format,
            'singleOrMultiple' => $singleOrMultiple !== '' ? $singleOrMultiple : 'single',
            'pageNumbers' => $pageNumbers !== '' ? $pageNumbers : 'all',
            'colorType' => $colorType !== '' ? $colorType : 'color',
            'dpi' => (string) ($dpi > 0 ? $dpi : 300),
        ];

        $response = $this->postFile(self::PDF_TO_IMG_PATH, $upload, 'application/pdf', $pdf, $fields);
        if ($response === null) {
            return ['ok' => false, 'error' => $this->unreachableError()];
        }
        if ($response['status'] >= 400) {
            return ['ok' => false, 'error' => $this->httpError($response['status'], $response['body'])];
        }

        $body = $response['body'];
        if ($body === '') {
            return ['ok' => false, 'error' => "Stirling-PDF returned empty content (HTTP {$response['status']})."];
        }

        $isZip = str_starts_with($body, "PK\x03\x04");
        $extension = $isZip ? 'zip' : $format;
        $mimeType = match ($extension) {
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };

        return [
            'ok' => true,
            'data' => $body,
            'bytes' => strlen($body),
            'isZip' => $isZip,
            'imageFormat' => $format,
            'mimeType' => $mimeType,
            'filename' => self::outputName($upload, $extension),
            'endpoint' => rtrim($this->endpoint, '/'),
        ];
    }

    /**
     * POST a file and optional parameters to one of Stirling-PDF's conversion endpoints.
     *
     * file_get_contents()/streams can't assemble multipart/form-data for us, so
     * the body is built by hand; a random boundary keeps it from colliding with
     * the document content.
     *
     * @param array<string, string> $extraFields key/value pairs sent as additional form fields
     * @return array{status: int, body: string}|null null when the server could not be reached
     */
    private function postFile(string $path, string $filename, string $contentType, string $content, array $extraFields = []): ?array {
        $boundary = '----SimpleMCP' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($extraFields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $valStr = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n"
                . $valStr . "\r\n";
        }

        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="fileInput"; filename="' . self::sanitizeFilename($filename) . "\"\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . "\r\n"
            . $content . "\r\n"
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
        $response = @file_get_contents(rtrim($this->endpoint, '/') . $path, false, $context);
        if ($response === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /** Validation shared by both conversions; null when the endpoint is usable. */
    private function configurationError(): ?string {
        $endpoint = rtrim($this->endpoint, '/');
        if ($endpoint === '') {
            return 'No Stirling-PDF endpoint configured. Set it on the /account page (HTTP) or in config/config.php (stdio).';
        }
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint)) {
            return "Stirling-PDF endpoint must be an http(s) URL, got \"{$this->endpoint}\".";
        }
        return null;
    }

    /** Message for a server that could not be reached at all. */
    private function unreachableError(): string {
        return 'Failed to reach Stirling-PDF at ' . rtrim($this->endpoint, '/')
            . '. Check that the URL is correct and the server is reachable.';
    }

    /** Message for a 4xx/5xx response, including an excerpt of the server's message. */
    private function httpError(int $status, string $body): string {
        $hint = $status === 401 || $status === 403
            ? ' The server rejected the API key — check it on the /account page (HTTP) or in config/config.php (stdio).'
            : '';
        $snippet = trim(substr($body, 0, 300));
        return "Stirling-PDF returned HTTP $status$hint" . ($snippet !== '' ? " — $snippet" : '');
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

    /** Derive the output filename from the uploaded document's basename. */
    private static function outputName(string $filename, string $extension): string {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        return ($base !== '' ? $base : 'document') . '.' . $extension;
    }

    /** Keep the multipart filename header-safe (no quotes or newlines). */
    private static function sanitizeFilename(string $filename): string {
        $clean = str_replace(["\r", "\n", '"'], '', $filename);
        return $clean !== '' ? $clean : 'document';
    }
}
