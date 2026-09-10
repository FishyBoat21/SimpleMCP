<?php

declare(strict_types=1);

/**
 * Global runtime configuration for the markdown→PDF tool.
 *
 * The markdown→PDF MCP tool (`convert_markdown_to_pdf`) converts Markdown text
 * to a PDF by posting it to a Stirling-PDF server (its `/api/v1/convert/markdown/pdf`
 * endpoint). The server location and optional API key are resolved as follows:
 *
 *   - stdio mode  → this file (the global default below).
 *   - HTTP mode   → the signed-in account's settings saved on the `/account`
 *                   page (src/Auth/SettingsStore.php, table `user_settings` in
 *                   data/app.sqlite). A value left blank on the account page
 *                   falls back to the global default here.
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
        // Leave empty to disable the tool until an endpoint is configured.
        'endpoint' => 'https://stirling.ad-ins.com',

        // Optional API key for Stirling-PDF instances that require one
        // (sent as the `X-API-KEY` header). Leave empty when your instance
        // has the API key feature disabled.
        'api_key' => '',
    ],
];