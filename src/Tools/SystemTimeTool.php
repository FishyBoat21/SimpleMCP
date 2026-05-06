<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;

readonly class SystemTimeTool {
    #[McpFunction(
        name: 'get_system_time',
        description: 'Returns the current server system time in ISO 8601 format.',
        // Replace the invalid (object)[] cast with new \stdClass()
        schema: ['type' => 'object', 'properties' => new \stdClass()]
    )]
    public function getTime(array $arguments): array {
        return [
            [
                'type' => 'text',
                'text' => date('c')
            ]
        ];
    }
}