<?php

namespace McpServer\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class McpTool {
    public function __construct(
        public string $name,
        public string $description,
        public array|object $schema = []
    ) {}
}