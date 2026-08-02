<?php

declare(strict_types=1);

namespace McpServer\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class McpFunction {
    /**
     * @param string $name Tool name exposed to MCP clients.
     * @param string $description Tool description exposed to MCP clients.
     * @param array|object $schema JSON Schema describing the `arguments` parameter.
     * @param string[] $roles Roles required to call this tool (any match grants access).
     * @param string[] $permissions Permissions required to call this tool (any match grants access).
     */
    public function __construct(
        public string $name,
        public string $description,
        public array|object $schema = [],
        public array $roles = [],
        public array $permissions = [],
    ) {}
}
