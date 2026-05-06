<?php

namespace McpServer\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class McpFunction {
    public function __construct(
        public string $name,
        public string $description,
        public array|object $schema = []
    ) {}
}