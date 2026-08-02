<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;

readonly class AdminTool {
    /**
     * Demonstrates access control: this tool requires the caller to hold the
     * `admin` role. The server hides it from tools/list for other users and
     * rejects direct calls with a -32001 access-denied error.
     */
    #[McpFunction(
        name: 'server_status',
        description: 'Returns server runtime information. Admin-only.',
        schema: ['type' => 'object', 'properties' => new \stdClass()],
        roles: ['admin']
    )]
    public function serverStatus(array $arguments): array {
        return [
            [
                'type' => 'text',
                'text' => json_encode([
                    'server' => 'php-simple-mcp',
                    'version' => '1.0.0',
                    'time' => date('c'),
                    'php_version' => PHP_VERSION,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ]
        ];
    }
}
