<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\McpServer;

$server = new McpServer();

// Auto-register tools from the Tools directory automatically!
$server->registerToolsFromDirectory(__DIR__ . '/src/Tools', 'McpServer\\Tools\\');

if (php_sapi_name() === 'cli') {
    $in = fopen('php://stdin', 'r');
    while (($line = fgets($in)) !== false) {
        // Assignment inside condition simplifies control flow
        if ($response = $server->handleRequest(trim($line))) {
            echo $response . "\n";
        }
    }
    fclose($in);
} else {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
        exit;
    }

    if ($response = $server->handleRequest(file_get_contents('php://input'))) {
        echo $response;
    }
}