<?php

/**
 * Model Context Protocol (MCP) Server in PHP
 * Supports both HTTP POST streams (php://input) and CLI standard I/O streams (php://stdin).
 */

// ============================================================================
// 1. Core Interfaces
// ============================================================================

/**
 * Interface ITool
 * Implement this interface to easily add new tools to the MCP server.
 */
interface ITool {
    /**
     * @return string The unique name of the tool (alphanumeric and underscores)
     */
    public function getName(): string;

    /**
     * @return string A human-readable description of what the tool does
     */
    public function getDescription(): string;

    /**
     * @return array The JSON Schema defining the expected arguments
     */
    public function getInputSchema(): array;

    /**
     * Executes the tool with the provided arguments.
     * * @param array $arguments
     * @return array An array of MCP content objects (e.g., [['type' => 'text', 'text' => '...']])
     */
    public function execute(array $arguments): array;
}

// ============================================================================
// 2. Tool Implementations (Modular)
// ============================================================================

class CalculatorTool implements ITool {
    public function getName(): string {
        return 'calculator';
    }

    public function getDescription(): string {
        return 'Performs basic addition or subtraction on two numbers.';
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => ['add', 'subtract'],
                    'description' => 'The math operation to perform'
                ],
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['operation', 'a', 'b']
        ];
    }

    public function execute(array $arguments): array {
        $op = $arguments['operation'] ?? 'add';
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;

        $result = ($op === 'subtract') ? ($a - $b) : ($a + $b);

        return [
            [
                'type' => 'text',
                'text' => "The result of $a $op $b is $result"
            ]
        ];
    }
}

class SystemTimeTool implements ITool {
    public function getName(): string {
        return 'get_system_time';
    }

    public function getDescription(): string {
        return 'Returns the current server system time in ISO 8601 format.';
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [], // No arguments required
        ];
    }

    public function execute(array $arguments): array {
        return [
            [
                'type' => 'text',
                'text' => date('c')
            ]
        ];
    }
}

// ============================================================================
// 3. MCP Server Core
// ============================================================================

class McpServer {
    /** @var ITool[] */
    private array $tools = [];

    /**
     * Registers a new tool into the server.
     */
    public function registerTool(ITool $tool): void {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Handles an incoming JSON-RPC payload string.
     */
    public function handleRequest(string $payload): ?string {
        $request = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return json_encode($this->createError(null, -32700, "Parse error or invalid JSON-RPC format"));
        }

        $isNotification = !array_key_exists('id', $request);
        $response = $this->processMethod($request);

        // Notifications don't require responses according to JSON-RPC specs
        if ($isNotification) {
            return null; 
        }

        return json_encode($response);
    }

    private function processMethod(array $request): array {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        try {
            switch ($method) {
                // Initial Handshake
                case 'initialize':
                    return $this->createSuccess($id, [
                        'protocolVersion' => '2024-11-05',
                        // Cast to object so json_encode outputs {} instead of []
                        'capabilities' => ['tools' => (object)[]],
                        'serverInfo' => [
                            'name' => 'php-simple-mcp',
                            'version' => '1.0.0'
                        ]
                    ]);

                // Notification from client confirming initialization
                case 'notifications/initialized':
                    return [];

                // List Available Tools
                case 'tools/list':
                    $toolList = [];
                    foreach ($this->tools as $tool) {
                        $toolList[] = [
                            'name' => $tool->getName(),
                            'description' => $tool->getDescription(),
                            'inputSchema' => $tool->getInputSchema()
                        ];
                    }
                    return $this->createSuccess($id, ['tools' => $toolList]);

                // Execute a Tool
                case 'tools/call':
                    $name = $params['name'] ?? '';
                    $args = $params['arguments'] ?? [];
                    
                    if (!isset($this->tools[$name])) {
                        return $this->createError($id, -32601, "Tool not found: $name");
                    }

                    $content = $this->tools[$name]->execute($args);
                    return $this->createSuccess($id, ['content' => $content, 'isError' => false]);

                // Unknown Method fallback
                default:
                    return $this->createError($id, -32601, "Method not found: $method");
            }
        } catch (Exception $e) {
            return $this->createError($id, -32603, "Internal server error: " . $e->getMessage());
        }
    }

    private function createSuccess($id, array $result): array {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result
        ];
    }

    private function createError($id, int $code, string $message): array {
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

// ============================================================================
// 4. Server Initialization & Stream Routing
// ============================================================================

$server = new McpServer();

// Easily register new tools here
$server->registerTool(new CalculatorTool());
$server->registerTool(new SystemTimeTool());

// Route based on environment (CLI vs Web Server)
if (php_sapi_name() === 'cli') {
    // STANDARD I/O STREAM MODE (For local MCP client runners)
    $in = fopen('php://stdin', 'r');
    while (($line = fgets($in)) !== false) {
        $response = $server->handleRequest(trim($line));
        if ($response) {
            echo $response . "\n";
        }
    }
    fclose($in);
} else {
    // HTTP STREAM MODE (Stateless JSON-RPC POST)
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
        exit;
    }

    // Read the raw HTTP request stream
    $payload = file_get_contents('php://input');
    $response = $server->handleRequest($payload);

    if ($response) {
        echo $response;
    }
}