<?php

declare(strict_types=1);

/**
 * SimpleMCP Root Entry Point / Router.
 *
 * For production deployments:
 * - Point web server document root to the `public/` directory (e.g. `public/index.php`).
 * - Run CLI/stdio MCP via `php stdio.php`.
 *
 * This file delegates cleanly:
 * - In CLI mode: dispatches to `stdio.php`.
 * - In HTTP mode: dispatches to `public/index.php`.
 */

if (php_sapi_name() === 'cli') {
    require __DIR__ . '/stdio.php';
} else {
    require __DIR__ . '/public/index.php';
}
