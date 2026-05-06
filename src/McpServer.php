<?php

declare(strict_types=1);

namespace McpServer;

use Exception;
use ReflectionClass;
use McpServer\Attributes\McpFunction;

class McpServer {
    /** @var array<string, array{instance: object, method: string, name: string, description: string, schema: array|object}> */
    private array $tools = [];

    public function registerTool(object $toolContainer): void {
        $reflection = new ReflectionClass($toolContainer);
        
        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(McpFunction::class);
            
            if (empty($attributes)) continue;

            /** @var McpFunction $mcpFunction */
            $mcpFunction = $attributes[0]->newInstance();
            
            $this->tools[$mcpFunction->name] = [
                'instance' => $toolContainer,
                'method' => $method->getName(),
                'name' => $mcpFunction->name,
                'description' => $mcpFunction->description,
                'schema' => $mcpFunction->schema
            ];
        }
    }

    public function registerToolsFromDirectory(string $directory, string $namespace): void {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob(rtrim($directory, '/\\') . '/*.php') as $file) {
            $className = basename($file, '.php');
            $fullClassName = $namespace . $className;
            
            // Trigger autoloader and check if class is valid
            if (class_exists($fullClassName)) {
                $reflection = new ReflectionClass($fullClassName);
                if (!$reflection->isAbstract()) {
                    foreach ($reflection->getMethods() as $method) {
                        if (!empty($method->getAttributes(McpFunction::class))) {
                            $this->registerTool(new $fullClassName());
                            break;
                        }
                    }
                }
            }
        }
    }

    public function handleRequest(string $payload): ?string {
        // Utilizing PHP 8.3+ built-in json_validate for performance
        if (!json_validate($payload)) {
            return json_encode($this->createError(null, -32700, "Parse error or invalid JSON-RPC format"));
        }

        $request = json_decode($payload, true);
        
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return json_encode($this->createError(null, -32600, "Invalid Request"));
        }

        $isNotification = !array_key_exists('id', $request);
        $response = $this->processMethod($request);

        return $isNotification ? null : json_encode($response);
    }

    private function processMethod(array $request): array {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        try {
            // Utilizing modern match expression for routing
            return match ($method) {
                'initialize' => $this->createSuccess($id, [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => (object)[]],
                    'serverInfo' => [
                        'name' => 'php-simple-mcp',
                        'version' => '1.0.0'
                    ]
                ]),
                
                'notifications/initialized' => [],
                
                'tools/list' => $this->createSuccess($id, [
                    // Utilizing arrow functions for array manipulation
                    'tools' => array_map(
                        fn(array $toolData) => [
                            'name' => $toolData['name'],
                            'description' => $toolData['description'],
                            'inputSchema' => $toolData['schema']
                        ],
                        array_values($this->tools)
                    )
                ]),
                
                'tools/call' => (function() use ($id, $params) {
                    $name = $params['name'] ?? '';
                    $args = $params['arguments'] ?? [];
                    
                    if (!isset($this->tools[$name])) {
                        return $this->createError($id, -32601, "Tool not found: $name");
                    }

                    $instance = $this->tools[$name]['instance'];
                    $methodName = $this->tools[$name]['method'];

                    return $this->createSuccess($id, [
                        'content' => $instance->$methodName($args), 
                        'isError' => false
                    ]);
                })(),
                
                default => $this->createError($id, -32601, "Method not found: $method"),
            };
        } catch (Exception $e) {
            return $this->createError($id, -32603, "Internal server error: " . $e->getMessage());
        }
    }

    // Utilizing PHP 8+ Union Types for IDs (Standard JSON-RPC can use int/string/null)
    private function createSuccess(int|string|null $id, array $result): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result
        ];
    }

    private function createError(int|string|null $id, int $code, string $message): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }
}