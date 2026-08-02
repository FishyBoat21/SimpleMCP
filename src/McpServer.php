<?php

declare(strict_types=1);

namespace McpServer;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use McpServer\Attributes\McpFunction;

class McpServer {
    /** @var array<string, array{instance: object, method: string, name: string, description: string, schema: array|object, roles: string[], permissions: string[], userIndex: int|null}> */
    private array $tools = [];

    /** The user for the current request; defaults to the trusted local user. */
    private ?UserContext $user = null;

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
                'schema' => $mcpFunction->schema,
                'roles' => $mcpFunction->roles,
                'permissions' => $mcpFunction->permissions,
                'userIndex' => self::userParameterIndex($method),
            ];
        }
    }

    /**
     * Find the index of an optional `?UserContext` parameter so the server can
     * inject the current user at call time. Returns null when the method only
     * takes the arguments array.
     */
    private static function userParameterIndex(ReflectionMethod $method): ?int {
        foreach ($method->getParameters() as $i => $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $type->getName() === UserContext::class) {
                return $i;
            }
        }
        return null;
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

    public function handleRequest(string $payload, ?UserContext $user = null): ?string {
        // The per-request user. CLI/stdio mode has no HTTP layer, so it defaults
        // to the trusted local user with full access.
        $this->user = $user ?? UserContext::local();

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
                    // Only expose tools the current user is allowed to call.
                    'tools' => array_map(
                        fn(array $toolData) => [
                            'name' => $toolData['name'],
                            'description' => $toolData['description'],
                            'inputSchema' => $toolData['schema']
                        ],
                        array_values(array_filter($this->tools, fn(array $tool) => $this->canCall($tool)))
                    )
                ]),
                
                'tools/call' => (function() use ($id, $params) {
                    $name = $params['name'] ?? '';
                    $args = $params['arguments'] ?? [];

                    if (!isset($this->tools[$name])) {
                        return $this->createError($id, -32601, "Tool not found: $name");
                    }

                    $tool = $this->tools[$name];

                    if (!$this->canCall($tool)) {
                        return $this->createError($id, -32001, "Access denied: insufficient permissions for tool: $name");
                    }

                    // The arguments array occupies parameter index 0; inject the
                    // current user at its declared index when the method asks for it.
                    $callArgs = [is_array($args) ? $args : []];
                    if ($tool['userIndex'] !== null && $tool['userIndex'] > 0) {
                        $callArgs[$tool['userIndex']] = $this->user;
                    }

                    return $this->createSuccess($id, [
                        'content' => $tool['instance']->{$tool['method']}(...$callArgs),
                        'isError' => false
                    ]);
                })(),
                
                default => $this->createError($id, -32601, "Method not found: $method"),
            };
        } catch (Throwable $e) {
            // Catch Throwable, not just Exception, so PHP Errors escaping a tool
            // (e.g. a TypeError from malformed arguments) become a -32603 JSON-RPC
            // error instead of killing the request with a 500 HTML page.
            return $this->createError($id, -32603, "Internal server error: " . $e->getMessage());
        }
    }

    /**
     * Access rule: a tool that declares roles and/or permissions requires the
     * caller to match within every declared category (any match within a
     * category suffices). A tool with no requirements is public.
     */
    private function canCall(array $tool, ?UserContext $user = null): bool {
        $user ??= $this->user ?? UserContext::local();
        $roles = $tool['roles'] ?? [];
        $permissions = $tool['permissions'] ?? [];

        if ($roles === [] && $permissions === []) {
            return true;
        }

        return ($roles === [] || $user->hasAnyRole($roles))
            && ($permissions === [] || $user->hasAnyPermission($permissions));
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