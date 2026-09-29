<?php

declare(strict_types=1);

/**
 * SimpleMCP stdio JSON-RPC entry point.
 *
 * Runs the MCP server over standard input/output (line-delimited JSON-RPC 2.0).
 * Every request runs under the trusted local user context with full tool access.
 *
 * Usage:
 *   php stdio.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\McpServer;

$server = new McpServer();
$server->registerToolsFromDirectory(__DIR__ . '/src/Tools', 'McpServer\\Tools\\');

$in = fopen('php://stdin', 'r');
if ($in === false) {
    fwrite(STDERR, "Failed to open php://stdin\n");
    exit(1);
}

while (($line = fgets($in)) !== false) {
    $trimmed = trim($line);
    if ($trimmed === '') {
        continue;
    }
    if ($response = $server->handleRequest($trimmed)) {
        echo $response . "\n";
    }
}
fclose($in);
