<?php

declare(strict_types=1);

/**
 * Global runtime configuration for the Stirling-PDF conversion tools.
 *
 * The PDF and image conversion MCP tools (`convert_markdown_to_pdf`,
 * `convert_pdf_to_markdown`, `convert_image_to_pdf`, `convert_pdf_to_image`)
 * post documents to a Stirling-PDF server (`/api/v1/convert/markdown/pdf`,
 * `/api/v1/convert/pdf/markdown`, `/api/v1/convert/img/pdf`, `/api/v1/convert/pdf/img`).
 * The server location and optional API key are resolved as follows:
 *
 *   - stdio mode  → this file (the global default below).
 *   - HTTP mode   → the signed-in account's settings saved on the `/account`
 *                   page (src/Auth/SettingsStore.php, table `user_settings` in
 *                   data/app.sqlite). A value left blank on the account page
 *                   falls back to the global default here.
 *
 * With no endpoint configured for a caller, the conversion tools are disabled
 * entirely: they are hidden from tools/list and a direct call is answered with
 * tool-not-found.
 *
 * The API key is passed to Stirling-PDF as an `X-API-KEY` request header and is
 * only required when your Stirling-PDF instance has the API key feature enabled.
 *
 * Keep this file free of secrets in version control — set values locally or via
 * environment-aware configuration.
 */
return [
    'stirling_pdf' => [
        // Base URL of the Stirling-PDF server, e.g. "https://stirling.ad-ins.com".
        // Leave empty to disable the conversion tools (they are hidden from
        // tools/list and not callable) until an endpoint is configured.
        'endpoint' => 'https://stirling.ad-ins.com',

        // Optional API key for Stirling-PDF instances that require one
        // (sent as the `X-API-KEY` header). Leave empty when your instance
        // has the API key feature disabled.
        'api_key' => '',
    ],
];