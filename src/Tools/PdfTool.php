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
 *     image(s) directly to the source directory `<dir>` (unzips multi-page archives).
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
        description: 'Convert a PDF document into image(s) using the configured Stirling-PDF server. Accepts an input file path and exports the resulting image file(s) into the source directory of the input file (automatically unzipping multi-page archives into the source directory).',
        schema: [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Path of the PDF file to convert. The resulting image(s) are exported to the source directory (<name>.<format> or unzipped page images).'],
                'input_file_path' => ['type' => 'string', 'description' => 'Alias for path.'],
                'image_format' => [
                    'type' => 'string',
                    'description' => 'Output image format: png (default), jpeg, jpg, gif, or webp.',
                    'enum' => ['png', 'jpeg', 'jpg', 'gif', 'webp'],
                ],
                'single_or_multiple' => [
                    'type' => 'string',
                    'description' => 'Choose between "single" (single image containing all pages, default) or "multiple" (separate images per page, unzipped into the source directory).',
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
                'keep_zip' => [
                    'type' => 'boolean',
                    'description' => 'Whether to retain the ZIP archive alongside the unzipped images when multiple pages are converted (default: false).',
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
        $keepZip = (bool) ($arguments['keep_zip'] ?? false);

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
        $sep = str_contains($path, '/') && !str_contains($path, '\\') ? '/' : DIRECTORY_SEPARATOR;

        if ($isZip) {
            $zipFilename = ($baseName !== '' ? $baseName : 'document') . '.zip';
            $zipPath = ($dir === '.' ? '' : $dir . $sep) . $zipFilename;

            if (@file_put_contents($zipPath, (string) $result['data']) === false) {
                return [['type' => 'text', 'text' => "Error: could not write ZIP file to '{$zipPath}'."]];
            }

            $extractedFiles = $this->extractZip($zipPath, $dir);

            if (!empty($extractedFiles)) {
                if (!$keepZip) {
                    @unlink($zipPath);
                }

                $outputPaths = array_map(fn(string $f): string => ($dir === '.' ? '' : $dir . $sep) . $f, $extractedFiles);
                $outputDisplay = count($outputPaths) === 1
                    ? $outputPaths[0]
                    : implode(', ', $outputPaths);

                $text = 'Converted PDF to Image via Stirling-PDF (' . $result['endpoint'] . ").\n"
                    . 'Input: ' . $path . "\n"
                    . 'Output: ' . $outputDisplay . "\n"
                    . 'Extracted: ' . count($extractedFiles) . " image file(s) into source directory '{$dir}'." . "\n"
                    . 'Size: ' . $result['bytes'] . " bytes\n"
                    . 'MIME: image/' . $imageFormat
                    . ($keepZip ? "\nArchive: " . $zipPath : '');

                return [['type' => 'text', 'text' => $text]];
            }

            // Fallback if extraction failed: retain the zip and report it
            $text = 'Converted PDF to Image via Stirling-PDF (' . $result['endpoint'] . ").\n"
                . 'Input: ' . $path . "\n"
                . 'Output: ' . $zipPath . "\n"
                . 'Size: ' . $result['bytes'] . " bytes\n"
                . 'MIME: application/zip' . "\n"
                . "Warning: could not extract ZIP archive into directory '{$dir}'. The ZIP file has been retained.";

            return [['type' => 'text', 'text' => $text]];
        }

        $outputFilename = ($baseName !== '' ? $baseName : 'document') . '.' . $imageFormat;
        $outputPath = ($dir === '.' ? '' : $dir . $sep) . $outputFilename;

        if (@file_put_contents($outputPath, (string) $result['data']) === false) {
            return [['type' => 'text', 'text' => "Error: could not write image file to '{$outputPath}'."]];
        }

        $text = 'Converted PDF to Image via Stirling-PDF (' . $result['endpoint'] . ").\n"
            . 'Input: ' . $path . "\n"
            . 'Output: ' . $outputPath . "\n"
            . 'Size: ' . $result['bytes'] . " bytes\n"
            . 'MIME: ' . $result['mimeType'];

        return [['type' => 'text', 'text' => $text]];
    }

    /**
     * Extract a ZIP archive into a destination directory.
     *
     * Tries PHP's ZipArchive first, then falls back to system CLI tools (`tar`, `powershell`, or `unzip`).
     *
     * @return string[] list of relative file paths extracted, or empty array on failure
     */
    private function extractZip(string $zipPath, string $destinationDir): array {
        $realDest = realpath($destinationDir) ?: $destinationDir;

        // 1. Try PHP's ZipArchive if available
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $files = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name !== false && !str_ends_with($name, '/') && !str_ends_with($name, '\\')) {
                        $files[] = $name;
                    }
                }
                if ($zip->extractTo($realDest)) {
                    $zip->close();
                    return $files;
                }
                $zip->close();
            }
        }

        // 2. Fallback: tar (standard on Windows 10/11, macOS, and Linux)
        if (function_exists('exec')) {
            $cmd = 'tar -xf ' . escapeshellarg($zipPath) . ' -C ' . escapeshellarg($realDest);
            $out = [];
            $code = -1;
            @exec($cmd, $out, $code);
            if ($code === 0) {
                $listCmd = 'tar -tf ' . escapeshellarg($zipPath);
                $listOut = [];
                @exec($listCmd, $listOut, $listCode);
                $files = [];
                foreach ($listOut as $line) {
                    $line = trim($line);
                    if ($line !== '' && !str_ends_with($line, '/') && !str_ends_with($line, '\\')) {
                        $files[] = $line;
                    }
                }
                return $files;
            }
        }

        // 3. Fallback: PowerShell Expand-Archive (Windows)
        if (PHP_OS_FAMILY === 'Windows' && function_exists('exec')) {
            $psCmd = 'powershell.exe -NoProfile -NonInteractive -Command ' . escapeshellarg(
                'Expand-Archive -LiteralPath ' . escapeshellarg($zipPath) . ' -DestinationPath ' . escapeshellarg($realDest) . ' -Force'
            );
            $out = [];
            $code = -1;
            @exec($psCmd, $out, $code);
            if ($code === 0) {
                return ['extracted'];
            }
        }

        // 4. Fallback: unzip (Linux / macOS)
        if (function_exists('exec')) {
            $cmd = 'unzip -o ' . escapeshellarg($zipPath) . ' -d ' . escapeshellarg($realDest);
            $out = [];
            $code = -1;
            @exec($cmd, $out, $code);
            if ($code === 0) {
                return ['extracted'];
            }
        }

        return [];
    }
}