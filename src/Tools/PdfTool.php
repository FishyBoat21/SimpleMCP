<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\PdfStore;
use McpServer\StirlingPdfClient;
use McpServer\UserContext;
use RuntimeException;

/**
 * Markdown → PDF conversion, rendered by a Stirling-PDF server.
 *
 * The tool posts the Markdown text to the configured Stirling-PDF instance
 * (`/api/v1/convert/markdown/pdf`), then saves the returned PDF inside the app
 * (data/output/<token>/<name>.pdf — see {@see PdfStore}) and reports where it
 * landed, by transport:
 *
 *   - HTTP (streamable) mode → a downloadable URL (`/download/<token>/<name>.pdf`)
 *   - stdio mode             → the filesystem path of the saved file
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
}