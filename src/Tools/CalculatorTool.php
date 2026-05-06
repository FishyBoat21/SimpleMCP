<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;

readonly class CalculatorTool {
    #[McpFunction(
        name: 'add_numbers',
        description: 'Performs basic addition on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function add(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a + $b is " . ($a + $b)
            ]
        ];
    }

    #[McpFunction(
        name: 'subtract_numbers',
        description: 'Performs basic subtraction on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function subtract(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a - $b is " . ($a - $b)
            ]
        ];
    }
}