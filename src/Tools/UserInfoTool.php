<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\UserContext;

readonly class UserInfoTool {
    /**
     * Demonstrates the user scope: the server injects the current UserContext
     * into any tool method that declares a `?UserContext` parameter (reflection
     * at registration time). In CLI/stdio mode this is the trusted local user.
     */
    #[McpFunction(
        name: 'get_current_user',
        description: 'Returns information about the currently authenticated user (username, name, roles, permissions).',
        schema: ['type' => 'object', 'properties' => new \stdClass()]
    )]
    public function getCurrentUser(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();

        return [
            [
                'type' => 'text',
                'text' => json_encode($user->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ]
        ];
    }
}
