<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\StirlingPdfClient;
use McpServer\UserContext;

/**
 * Document and image conversion tools, rendered by a Stirling-PDF server.
 *
 * All tools accept an input file path (`path` or `input_file_path`) and export
 * the converted file with the same base name into the same directory:
 *
 *   - `convert_markdown_to_pdf`: accepts a Markdown file path and writes
 *     the rendered PDF to `<dir>/<name>.pdf`.
 *   - `convert_pdf_to_markdown`: accepts a PDF file path, writes the extracted
 *     Markdown to `<dir>/<name>.md`, and returns the Markdown content in the result.
 *   - `convert_image_to_pdf`: accepts an image file path (PNG, JPG, WEBP, GIF, etc.)
 *     and writes the rendered PDF to `<dir>/<name>.pdf`.
 *   - `convert_pdf_to_image`: accepts a PDF file path and writes the rendered
 *     image to `<dir>/<name>.<format>` (or `<dir>/<name>.zip` for multiple pages).
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
 *
 * Availability: both tools are enabled only when a Stirling-PDF endpoint is
 * actually configured for the caller ({@see self::isAvailable()}). Without
 * one the tools are disabled entirely — hidden from `tools/list`, and a
 * direct `tools/call` is answered with tool-not-found (-32601) as if the
 * tool didn't exist.
 */
readonly class PdfTool {
    /** @var string[] login required, mirroring MemoryTool::REQUIRED_ROLES */
    private const REQUIRED_ROLES = ['user', 'admin'];

    /**
     * Registration gate consulted by the MCP server: the tools exist only when
     * an endpoint is configured for the current user (global config in stdio
     * mode, the account's setting in HTTP mode).
     */
    public static function isAvailable(?UserContext $user = null): bool {
        return StirlingPdfClient::forUser($user)['endpoint'] !== '';
    }

    #[McpFunction(
        name: 'convert_markdown_to_pdf',
        roles: self::REQUIRED_ROLES,
        description: 'Convert a Markdown file into a PDF document using the configured Stirling-PDF server. Accepts an input file path and exports the resulting PDF with the same name and into the same directory as the input file.',
        schema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path of the Markdown file to convert. The resulting PDF is exported to the same directory with the same name (<name>.pdf).'],
                'input_file_path' => ['type' => 'string', 'description' => 'Alias for path.'],
            ],
            'required' => ['path'],
        ]
    )]
    public function convertMarkdownToPdf(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $path = trim((string) ($arguments['path'] ?? $arguments['input_file_path'] ?? ''));
        if ($path === '') {
            return [['type' => 'text', 'text' => "Error: 'path' must be a non-empty string pointing to a Markdown file."]];
        }

        if (!is_file($path) || !is_readable($path)) {
            return [['type' => 'text', 'text' => "Error: no readable file at '{$path}'."]];
        }

        $markdown = @file_get_contents($path);
        if (!is_string($markdown) || trim($markdown) === '') {
            return [['type' => 'text', 'text' => "Error: file at '{$path}' is empty or could not be read."]];
        }

        $settings = StirlingPdfClient::forUser($user);
        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);

        $dir = dirname($path);
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $uploadName = ($baseName !== '' ? $baseName : 'document') . '.md';

        $result = $client->convertMarkdownToPdf($markdown, $uploadName);
        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        $outputFilename = ($baseName !== '' ? $baseName : 'document') . '.pdf';
        $sep = str_contains($path, '/') && !str_contains($path, '\\') ? '/' : DIRECTORY_SEPARATOR;
        $outputPath = ($dir === '.' ? '' : $dir . $sep) . $outputFilename;

        if (@file_put_contents($outputPath, (string) $result['pdf']) === false) {
            return [['type' => 'text', 'text' => "Error: could not write PDF to '{$outputPath}'."]];
        }

        $text = 'Converted Markdown to PDF via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'Input: ' . $path . "\n"
            . 'Output: ' . $outputPath . "\n"
            . 'Size: ' . strlen((string) $result['pdf']) . " bytes\n"
            . 'MIME: application/pdf';

        return [['type' => 'text', 'text' => $text]];
    }

    #[McpFunction(
        name: 'convert_pdf_to_markdown',
        roles: self::REQUIRED_ROLES,
        description: 'Convert a PDF document into Markdown text using the configured Stirling-PDF server. Accepts an input file path and exports the resulting Markdown file with the same name and into the same directory as the input file, while returning the extracted Markdown content.',
        schema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path of the PDF file to convert. The resulting Markdown is exported to the same directory with the same name (<name>.md).'],
                'input_file_path' => ['type' => 'string', 'description' => 'Alias for path.'],
            ],
            'required' => ['path'],
        ]
    )]
    public function convertPdfToMarkdown(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $path = trim((string) ($arguments['path'] ?? $arguments['input_file_path'] ?? ''));
        if ($path === '') {
            return [['type' => 'text', 'text' => "Error: 'path' must be a non-empty string pointing to a PDF file."]];
        }

        if (!is_file($path) || !is_readable($path)) {
            return [['type' => 'text', 'text' => "Error: no readable file at '{$path}'."]];
        }

        $size = @filesize($path);
        if ($size !== false && $size > 104857600) {
            return [['type' => 'text', 'text' => 'Error: the PDF exceeds the 100 MB limit.']];
        }

        $pdfData = @file_get_contents($path);
        if (!is_string($pdfData) || $pdfData === '') {
            return [['type' => 'text', 'text' => "Error: could not read '{$path}'."]];
        }

        if (!str_contains(substr($pdfData, 0, 1024), '%PDF')) {
            return [['type' => 'text', 'text' => "Error: '{$path}' does not look like a PDF (no %PDF header found)."]];
        }

        $settings = StirlingPdfClient::forUser($user);
        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);

        $dir = dirname($path);
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $uploadName = ($baseName !== '' ? $baseName : 'document') . '.pdf';

        $result = $client->convertPdfToMarkdown($pdfData, $uploadName);
        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        $outputFilename = ($baseName !== '' ? $baseName : 'document') . '.md';
        $sep = str_contains($path, '/') && !str_contains($path, '\\') ? '/' : DIRECTORY_SEPARATOR;
        $outputPath = ($dir === '.' ? '' : $dir . $sep) . $outputFilename;

        if (@file_put_contents($outputPath, (string) $result['markdown']) === false) {
            return [['type' => 'text', 'text' => "Error: could not write Markdown to '{$outputPath}'."]];
        }

        $header = 'Converted PDF to Markdown via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'Input: ' . $path . "\n"
            . 'Output: ' . $outputPath . "\n"
            . 'Size: ' . $result['bytes'] . " bytes\n\n";

        return [['type' => 'text', 'text' => $header . $result['markdown']]];
    }

    #[McpFunction(
        name: 'convert_image_to_pdf',
        roles: self::REQUIRED_ROLES,
        description: 'Convert an image file (PNG, JPG, JPEG, WEBP, GIF, BMP, TIFF, SVG) into a PDF document using the configured Stirling-PDF server. Accepts an input file path and exports the resulting PDF with the same name and into the same directory as the input file.',
        schema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path of the image file to convert. The resulting PDF is exported to the same directory with the same name (<name>.pdf).'],
                'input_file_path' => ['type' => 'string', 'description' => 'Alias for path.'],
                'fit_option' => [
                    'type' => 'string',
                    'description' => 'Option to determine how the image will fit onto the page: fillPage (default), fitToPage, or maintainAspectRatio.',
                    'enum' => ['fillPage', 'fitToPage', 'maintainAspectRatio'],
                ],
                'color_type' => [
                    'type' => 'string',
                    'description' => 'The color type of the output image(s): color (default), greyscale, or black-and-white.',
                    'enum' => ['color', 'greyscale', 'black-and-white'],
                ],
                'auto_rotate' => [
                    'type' => 'boolean',
                    'description' => 'Whether to automatically rotate the images to better fit the PDF page (default: false).',
                ],
            ],
            'required' => ['path'],
        ]
    )]
    public function convertImageToPdf(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $path = trim((string) ($arguments['path'] ?? $arguments['input_file_path'] ?? ''));
        if ($path === '') {
            return [['type' => 'text', 'text' => "Error: 'path' must be a non-empty string pointing to an image file."]];
        }

        if (!is_file($path) || !is_readable($path)) {
            return [['type' => 'text', 'text' => "Error: no readable file at '{$path}'."]];
        }

        $size = @filesize($path);
        if ($size !== false && $size > 104857600) {
            return [['type' => 'text', 'text' => 'Error: the image exceeds the 100 MB limit.']];
        }

        $imageData = @file_get_contents($path);
        if (!is_string($imageData) || $imageData === '') {
            return [['type' => 'text', 'text' => "Error: could not read '{$path}'."]];
        }

        $fitOption = (string) ($arguments['fit_option'] ?? 'fillPage');
        $colorType = (string) ($arguments['color_type'] ?? 'color');
        $autoRotate = (bool) ($arguments['auto_rotate'] ?? false);

        $settings = StirlingPdfClient::forUser($user);
        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);

        $dir = dirname($path);
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $uploadName = ($baseName !== '' ? $baseName : 'image') . ($ext !== '' ? '.' . $ext : '.png');

        $result = $client->convertImageToPdf($imageData, $uploadName, $fitOption, $colorType, $autoRotate);
        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        $outputFilename = ($baseName !== '' ? $baseName : 'document') . '.pdf';
        $sep = str_contains($path, '/') && !str_contains($path, '\\') ? '/' : DIRECTORY_SEPARATOR;
        $outputPath = ($dir === '.' ? '' : $dir . $sep) . $outputFilename;

        if (@file_put_contents($outputPath, (string) $result['pdf']) === false) {
            return [['type' => 'text', 'text' => "Error: could not write PDF to '{$outputPath}'."]];
        }

        $text = 'Converted Image to PDF via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'Input: ' . $path . "\n"
            . 'Output: ' . $outputPath . "\n"
            . 'Size: ' . strlen((string) $result['pdf']) . " bytes\n"
            . 'MIME: application/pdf';

        return [['type' => 'text', 'text' => $text]];
    }

    #[McpFunction(
        name: 'convert_pdf_to_image',
        roles: self::REQUIRED_ROLES,
        description: 'Convert a PDF document into image(s) using the configured Stirling-PDF server. Accepts an input file path and exports the resulting image file (or ZIP archive for multiple pages) with the same name and into the same directory as the input file.',
        schema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path of the PDF file to convert. The resulting image is exported to the same directory with the same name (<name>.<format> or <name>.zip).'],
                'input_file_path' => ['type' => 'string', 'description' => 'Alias for path.'],
                'image_format' => [
                    'type' => 'string',
                    'description' => 'Output image format: png (default), jpeg, jpg, gif, or webp.',
                    'enum' => ['png', 'jpeg', 'jpg', 'gif', 'webp'],
                ],
                'single_or_multiple' => [
                    'type' => 'string',
                    'description' => 'Choose between "single" (single image containing all pages, default) or "multiple" (separate images per page, packaged into a ZIP archive).',
                    'enum' => ['single', 'multiple'],
                ],
                'page_numbers' => [
                    'type' => 'string',
                    'description' => 'Pages to select: "all" (default) or ranges like "1", "1,3,5-9".',
                ],
                'color_type' => [
                    'type' => 'string',
                    'description' => 'The color type of the output image(s): color (default), greyscale, or blackandwhite.',
                    'enum' => ['color', 'greyscale', 'blackandwhite'],
                ],
                'dpi' => [
                    'type' => 'integer',
                    'description' => 'The DPI (dots per inch) for the output image(s) (default: 300).',
                ],
            ],
            'required' => ['path'],
        ]
    )]
    public function convertPdfToImage(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        $path = trim((string) ($arguments['path'] ?? $arguments['input_file_path'] ?? ''));
        if ($path === '') {
            return [['type' => 'text', 'text' => "Error: 'path' must be a non-empty string pointing to a PDF file."]];
        }

        if (!is_file($path) || !is_readable($path)) {
            return [['type' => 'text', 'text' => "Error: no readable file at '{$path}'."]];
        }

        $size = @filesize($path);
        if ($size !== false && $size > 104857600) {
            return [['type' => 'text', 'text' => 'Error: the PDF exceeds the 100 MB limit.']];
        }

        $pdfData = @file_get_contents($path);
        if (!is_string($pdfData) || $pdfData === '') {
            return [['type' => 'text', 'text' => "Error: could not read '{$path}'."]];
        }

        if (!str_contains(substr($pdfData, 0, 1024), '%PDF')) {
            return [['type' => 'text', 'text' => "Error: '{$path}' does not look like a PDF (no %PDF header found)."]];
        }

        $imageFormat = strtolower(trim((string) ($arguments['image_format'] ?? 'png')));
        if ($imageFormat === '') {
            $imageFormat = 'png';
        }
        $singleOrMultiple = (string) ($arguments['single_or_multiple'] ?? 'single');
        $pageNumbers = (string) ($arguments['page_numbers'] ?? 'all');
        $colorType = (string) ($arguments['color_type'] ?? 'color');
        $dpi = (int) ($arguments['dpi'] ?? 300);

        $settings = StirlingPdfClient::forUser($user);
        $client = new StirlingPdfClient($settings['endpoint'], $settings['api_key']);

        $dir = dirname($path);
        $baseName = pathinfo($path, PATHINFO_FILENAME);
        $uploadName = ($baseName !== '' ? $baseName : 'document') . '.pdf';

        $result = $client->convertPdfToImage($pdfData, $uploadName, $imageFormat, $singleOrMultiple, $pageNumbers, $colorType, $dpi);
        if (!($result['ok'] ?? false)) {
            return [['type' => 'text', 'text' => 'Error: ' . ($result['error'] ?? 'unknown conversion error')]];
        }

        $isZip = (bool) ($result['isZip'] ?? false);
        $ext = $isZip ? 'zip' : $imageFormat;
        $outputFilename = ($baseName !== '' ? $baseName : 'document') . '.' . $ext;
        $sep = str_contains($path, '/') && !str_contains($path, '\\') ? '/' : DIRECTORY_SEPARATOR;
        $outputPath = ($dir === '.' ? '' : $dir . $sep) . $outputFilename;

        if (@file_put_contents($outputPath, (string) $result['data']) === false) {
            return [['type' => 'text', 'text' => "Error: could not write image file to '{$outputPath}'."]];
        }

        // If a ZIP was returned and ZipArchive is available, extract pages alongside the zip
        $extractedNote = '';
        if ($isZip && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($outputPath) === true) {
                $zip->extractTo($dir);
                $extractedNote = "\nExtracted: " . $zip->numFiles . " image file(s) into directory '{$dir}'.";
                $zip->close();
            }
        }

        $text = 'Converted PDF to Image via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'Input: ' . $path . "\n"
            . 'Output: ' . $outputPath . "\n"
            . 'Size: ' . $result['bytes'] . " bytes\n"
            . 'MIME: ' . $result['mimeType']
            . $extractedNote;

        return [['type' => 'text', 'text' => $text]];
    }
}