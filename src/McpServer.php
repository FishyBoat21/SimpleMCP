<?php

declare(strict_types=1);

namespace McpServer;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use McpServer\Attributes\McpFunction;
use McpServer\Auth\ClipboardStore;
use McpServer\Auth\DebugLog;

class McpServer {
    /**
     * Marks a text block that is already an offload receipt, so the funnel never
     * wraps a receipt in a second clip.
     */
    private const OFFLOAD_MARKER = '[offloaded]';

    /** @var array<string, array{instance: object, method: string, name: string, description: string, schema: array|object, roles: string[], permissions: string[], userIndex: int|null, enabledCheck: (callable(?UserContext): bool)|null, offloadAt: int}> */
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
                'enabledCheck' => method_exists($toolContainer, 'isAvailable')
                    ? [$toolContainer, 'isAvailable']
                    : null,
                'offloadAt' => $mcpFunction->offloadAt,
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

                    // A disabled tool (its container reports itself unavailable,
                    // e.g. backend not configured) is treated as if it never
                    // existed — tool not found, not an access error.
                    $enabledCheck = $tool['enabledCheck'] ?? null;
                    if ($enabledCheck !== null && !$enabledCheck($this->user)) {
                        return $this->createError($id, -32601, "Tool not found: $name");
                    }

                    if (!$this->canCall($tool)) {
                        return $this->createError($id, -32001, "Access denied: insufficient permissions for tool: $name");
                    }

                    // The arguments array occupies parameter index 0; inject the
                    // current user at its declared index when the method asks for it.
                    $callArgs = [is_array($args) ? $args : []];
                    if ($tool['userIndex'] !== null && $tool['userIndex'] > 0) {
                        $callArgs[$tool['userIndex']] = $this->user;
                    }

                    $content = $tool['instance']->{$tool['method']}(...$callArgs);

                    return $this->createSuccess($id, [
                        'content' => $this->maybeOffload($content, $tool, $this->user ?? UserContext::local()),
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
     * Replace oversized text content blocks with clipboard receipts.
     *
     * Every MCP tool result is fed to the model verbatim, so a tool that returns a
     * large payload fills the context window. A tool that declares `offloadAt`
     * opts into having such a block stored as a clip and swapped for a short
     * receipt carrying the clip id — the caller can read it back on demand, or
     * pass the id to a tool that accepts `clip_id` so the bytes never enter the
     * context window at all.
     *
     * This is purely an optimisation, and is written to fail open: any failure to
     * store leaves the block untouched, so a broken, full, or absent clipboard
     * degrades to the current behaviour rather than failing the tool call.
     *
     * @param array<int, array<string, mixed>> $content blocks returned by the tool
     * @param array<string, mixed> $tool the registry entry for the tool
     * @return array<int, array<string, mixed>>
     */
    private function maybeOffload(array $content, array $tool, UserContext $user): array {
        $threshold = (int) ($tool['offloadAt'] ?? 0);
        if ($threshold <= 0 || !$this->clipboardAvailable()) {
            return $content;
        }

        foreach ($content as $index => $block) {
            // Only plain text can occupy a clip's TEXT column; images, audio and
            // resource blocks stay inline because the caller needs them as-is.
            if (!is_array($block) || ($block['type'] ?? '') !== 'text') {
                continue;
            }

            $text = $block['text'] ?? null;
            // `offloadAt: 8000` reads as "up to 8,000 bytes inline", so the
            // boundary is strict.
            if (!is_string($text) || strlen($text) <= $threshold) {
                continue;
            }

            if (str_starts_with($text, self::OFFLOAD_MARKER)) {
                continue;
            }

            try {
                $store = new ClipboardStore();
                $stored = $store->put($user->username, $text, '', (string) $tool['name']);

                if (isset($stored['error'])) {
                    // A payload over the per-clip cap will never fit, so say why
                    // it stayed inline instead of letting the caller assume the
                    // offload happened.
                    if (($stored['reason'] ?? '') === 'too_large') {
                        $content[] = [
                            'type' => 'text',
                            'text' => 'Note: this result is ' . number_format(strlen($text))
                                . " bytes, over the clipboard's per-clip limit, so it was returned inline. Consider ingest_document to store it.",
                        ];
                    }
                    continue;
                }

                $receipt = $store->offloadReceipt((string) $tool['name'], $stored, $text, $threshold);
                if ($receipt === null) {
                    continue;
                }

                $content[$index] = $receipt;

                // Replacing the block drops any pagination fields the result
                // carried itself, so point the caller at the head of the clip.
                if (self::looksLikeJson($text)) {
                    $content[] = [
                        'type' => 'text',
                        'text' => 'Note: this result was a JSON document; its own pagination fields (limit/offset/nextOffset) are inside the clip'
                            . ' — read the head of the clip (clipboard action=get id=' . $stored['id'] . ' max_chars=2000) to recover them.',
                    ];
                }

                DebugLog::write('clipboard offload tool=' . $tool['name'] . ' bytes=' . strlen($text) . ' clip=' . $stored['id']);
            } catch (Throwable $e) {
                DebugLog::write('clipboard offload FAILED tool=' . $tool['name'] . ' error=' . $e->getMessage());
                continue;
            }
        }

        return $content;
    }

    /**
     * Whether offloading is offered at all. It requires the `clipboard` tool to
     * exist and the current caller to be allowed to use it, so anonymous HTTP
     * callers keep receiving full results inline — they would otherwise share a
     * single unknown username's clipboard between unrelated clients.
     */
    private function clipboardAvailable(): bool {
        return isset($this->tools['clipboard']) && $this->canCall($this->tools['clipboard']);
    }

    /** A cheap "this looks like a JSON document" probe, for the receipt's hint. */
    private static function looksLikeJson(string $text): bool {
        $head = ltrim($text);
        return str_starts_with($head, '{') || str_starts_with($head, '[');
    }

    /**
     * Access rule: a tool whose container reports itself unavailable (see
     * `isAvailable()` on the tool class, e.g. a missing backend config) is
     * disabled for everyone — hidden from tools/list and not callable. A tool
     * that declares roles and/or permissions further requires the caller to
     * match within every declared category (any match within a category
     * suffices). A tool with no requirements is public.
     */
    private function canCall(array $tool, ?UserContext $user = null): bool {
        $user ??= $this->user ?? UserContext::local();

        if (($tool['enabledCheck'] ?? null) !== null && !($tool['enabledCheck'])($user)) {
            return false;
        }

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