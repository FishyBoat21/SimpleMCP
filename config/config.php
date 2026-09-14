<?php

declare(strict_types=1);

/**
 * Global runtime configuration for the Stirling-PDF conversion tools and the
 * clipboard (tool-output offloading) store.
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
    // ---------------------------------------------------------------------
    // Clipboard (tool output offloading)
    //
    // Every MCP tool result is injected verbatim into the model's context
    // window. Tools that can produce a lot of text declare an `offloadAt`
    // threshold on their #[McpFunction] attribute; a result larger than that
    // is stored server-side as a "clip" and replaced by a short receipt with a
    // clip id. The model can then read it back on demand, or hand the id to
    // another tool (e.g. ingest_document's `clip_id`) so the bytes never enter
    // the context window at all.
    //
    // Clips live in data/memory.sqlite (tables memory_clips and
    // memory_clip_tombstones), are scoped per user, and are capped at
    // `max_entries` with least-recently-used eviction. Pinned clips are exempt
    // from eviction; `ttl_seconds` expires the rest.
    //
    // These values are deployment-varying overrides. src/Auth/ClipboardStore.php
    // holds the built-in defaults and hard clamps, so a missing key or a
    // nonsense value can never produce an unbounded clipboard.
    // ---------------------------------------------------------------------
    'clipboard' => [
        // Maximum live clips per user. Pinned clips may push past this.
        'max_entries' => 10,

        // Longest TTL a caller may request (7 days), and the TTL applied to a
        // put that does not specify one (24 hours). 0 means "never expires".
        'max_ttl_seconds' => 604800,
        'default_ttl_seconds' => 86400,

        // Largest single clip. Anything bigger is refused (the tool result then
        // stays inline).
        'max_entry_bytes' => 1048576,

        // Bytes returned by one `clipboard action=get`. `max_chars` is a byte
        // budget despite the name: a byte cut is UTF-8-safe and errs
        // conservative for multi-byte text.
        'default_max_chars' => 20000,
        'hard_max_chars' => 100000,

        // Bytes of head+tail preview included in an automatic offload receipt.
        'preview_bytes' => 2000,

        // Whether a miss on an unknown clip id may say "this id belongs to a
        // different account". Set false for strict per-user opacity.
        'reveal_foreign_ids' => true,
    ],
];
