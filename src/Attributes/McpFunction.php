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
     * @param int $offloadAt When > 0, a text result block larger than this many bytes is
     *        stored on the server as a clipboard clip and replaced by a short receipt
     *        carrying the clip id (see McpServer\Auth\ClipboardStore). 0 — the default —
     *        returns the result inline, so existing tools are unaffected. This keeps
     *        bulk tool output out of the model's context window: the caller reads the
     *        clip back on demand, or passes the id to a tool that accepts `clip_id`.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array|object $schema = [],
        public array $roles = [],
        public array $permissions = [],
        public int $offloadAt = 0,
    ) {}
}
