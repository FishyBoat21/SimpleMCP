<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\PdfStore;
use McpServer\StirlingPdfClient;
use McpServer\UserContext;
use RuntimeException;

/**
 * Document conversion in both directions, rendered by a Stirling-PDF server.
 *
 * `convert_markdown_to_pdf` posts Markdown text to the configured Stirling-PDF
 * instance (`/api/v1/convert/markdown/pdf`), saves the returned PDF inside the
 * app (data/output/<token>/<name>.pdf — see {@see PdfStore}) and reports where
 * it landed, by transport:
 *
 *   - HTTP (streamable) mode → a downloadable URL (`/download/<token>/<name>.pdf`)
 *   - stdio mode             → the filesystem path of the saved file
 *
 * `convert_pdf_to_markdown` is the inverse: it posts a PDF to
 * `/api/v1/convert/pdf/markdown` and returns the extracted Markdown **directly
 * in the tool result** — no file is written and no URL is handed back.
 *
 * Connection settings are transport-aware ({@see StirlingPdfClient::forUser()}):
 *
 *   - stdio mode → config/config.php (`stirling_pdf.endpoint` / `.api_key`)
 *   - HTTP mode  → the signed-in account's values from the /account page,
 *                  falling back to config/config.php for blank fields.
 *
 * Access: requires the `user` or `admin` role (mirrors the memory tools), so
 * only logged-in accounts can list/call it — anonymous HTTP callers see none
 * and get a -32001 on direct calls. In stdio mode the injected `local` user
 * holds the `*` role, so access still works.
 */
readonly class PdfTool {
    /** @var string[] login required, mirroring MemoryTool::REQUIRED_ROLES */
    private const REQUIRED_ROLES = ['user', 'admin'];

    #[McpFunction(
        name: 'convert_markdown_to_pdf',
        roles: self::REQUIRED_ROLES,
        description: 'Convert Markdown text into a PDF document using the configured Stirling-PDF server. The PDF is saved on the server: over HTTP the result includes a download URL, over stdio the path of the saved file. The endpoint and optional API key come from the /account page (HTTP mode) or config/config.php (stdio mode).',
        schema: [
            'type' => 'object',
            'properties' => [
                'markdown' => ['type' => 'string', 'description' => 'The Markdown content to convert to PDF.'],
                'filename' => ['type' => 'string', 'description' => 'Optional base name for the saved PDF ("" or omitted defaults to "document"). The file is written as <name>.pdf inside the server; the upload uses "<name>.md" so the server detects Markdown.'],
            ],
            'required' => ['markdown'],
        ]
    )]
    public function convertMarkdownToPdf(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $markdown = (string) ($arguments['markdown'] ?? '');
        $filenameArg = (string) ($arguments['filename'] ?? '');
        $uploadName = $filenameArg !== '' ? $filenameArg : 'document';

        if (trim($markdown) === '') {
            return [['type' => 'text', 'text' => "Error: 'markdown' must be a non-empty string."]];
        }

        $settings = StirlingPdfClient::forUser($user);
        if ($settings['endpoint'] === '') {
            return [['type' => 'text', 'text' => "Error: No Stirling-PDF endpoint configured. Set it on the /account page when running over HTTP, or in config/config.php when running via stdio."]];
        }

        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);
        $result = $client->convertMarkdownToPdf($markdown, $uploadName . '.md');

        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        // Save the PDF inside the app; the transport decides how it's handed back.
        try {
            $saved = (new PdfStore())->save((string) $result['pdf'], $uploadName);
        } catch (RuntimeException $e) {
            return [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]];
        }

        $text = 'Converted Markdown to PDF via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'File: ' . $saved['filename'] . "\n"
            . 'Size: ' . $saved['bytes'] . " bytes\n"
            . 'MIME: application/pdf';

        // HTTP gets a downloadable URL; stdio (no HTTP layer) gets the path on disk.
        if (PHP_SAPI === 'cli') {
            $text .= "\nSaved to: " . $saved['path'];
        } else {
            $text .= "\nDownload: " . PdfStore::url($saved['token'], $saved['filename'], $_SERVER);
        }

        return [['type' => 'text', 'text' => $text]];
    }

    /**
     * PDF → Markdown. The extracted Markdown is returned in the tool result
     * itself, unlike the inverse tool, which writes a file and reports a URL.
     *
     * @param array<string, mixed> $arguments
     * @return array<int, array<string, string>>
     */
    #[McpFunction(
        name: 'convert_pdf_to_markdown',
        roles: self::REQUIRED_ROLES,
        description: 'Convert a PDF document into Markdown text using the configured Stirling-PDF server. The extracted Markdown is returned directly in the tool result. Supply the PDF either as base64 (pdf_base64) or as a path on the server host (path). The endpoint and optional API key come from the /account page (HTTP mode) or config/config.php (stdio mode).',
        schema: [
            'type' => 'object',
            'properties' => [
                'pdf_base64' => ['type' => 'string', 'description' => 'Base64-encoded PDF bytes. A leading "data:application/pdf;base64," prefix and line breaks are tolerated. Provide this or path.'],
                'path' => ['type' => 'string', 'description' => 'Path of a PDF file readable by the server process (useful in stdio mode, where the client and server share a filesystem). Provide this or pdf_base64.'],
                'filename' => ['type' => 'string', 'description' => 'Optional base name for the uploaded PDF ("" or omitted defaults to "document"). The upload uses "<name>.pdf" so the server detects PDF.'],
            ],
            'required' => [],
        ]
    )]
    public function convertPdfToMarkdown(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $filenameArg = (string) ($arguments['filename'] ?? '');
        $uploadName = $filenameArg !== '' ? $filenameArg : 'document';
        if (!str_ends_with(strtolower($uploadName), '.pdf')) {
            $uploadName .= '.pdf';
        }

        // Accept either the bytes themselves (base64) or a path on the host.
        $pdf = self::readPdf($arguments['pdf_base64'] ?? null, $arguments['path'] ?? null);
        if (isset($pdf['error'])) {
            return [['type' => 'text', 'text' => 'Error: ' . $pdf['error']]];
        }

        $settings = StirlingPdfClient::forUser($user);
        if ($settings['endpoint'] === '') {
            return [['type' => 'text', 'text' => "Error: No Stirling-PDF endpoint configured. Set it on the /account page when running over HTTP, or in config/config.php when running via stdio."]];
        }

        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);
        $result = $client->convertPdfToMarkdown($pdf['data'], $uploadName);

        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        $header = 'Converted PDF to Markdown via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'File: ' . $result['filename'] . "\n"
            . 'Size: ' . $result['bytes'] . " bytes\n\n";

        return [['type' => 'text', 'text' => $header . $result['markdown']]];
    }

    /**
     * Resolve the PDF input: base64 text, or a path on the server host.
     *
     * @return array{data: string}|array{error: string}
     */
    private static function readPdf(mixed $base64, mixed $path): array {
        $base64 = is_string($base64) ? trim($base64) : '';
        $path = is_string($path) ? trim($path) : '';

        if ($base64 === '' && $path === '') {
            return ['error' => "provide 'pdf_base64' or 'path'."];
        }

        if ($base64 !== '') {
            // Tolerate the data: URL form and base64 wrapped across lines.
            if (preg_match('#^data:[^;,]*;base64,#i', $base64, $m) === 1) {
                $base64 = substr($base64, strlen($m[0]));
            }
            $decoded = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);
            if (!is_string($decoded) || $decoded === '') {
                return ['error' => "'pdf_base64' is not valid base64 data."];
            }
            return ['data' => $decoded];
        }

        if (!is_file($path) || !is_readable($path)) {
            return ['error' => "no readable file at '{$path}'."];
        }
        $data = @file_get_contents($path);
        if (!is_string($data) || $data === '') {
            return ['error' => "could not read '{$path}'."];
        }
        return ['data' => $data];
    }
}