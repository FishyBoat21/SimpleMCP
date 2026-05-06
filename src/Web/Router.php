<?php

declare(strict_types=1);

namespace McpServer\Web;

/**
 * Minimal router for the SSO web UI.
 * Matches on REQUEST_METHOD + PATH_INFO (or SCRIPT_NAME-relative path).
 */
class Router {
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function get(string $pattern, callable $handler): void {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $this->currentPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) continue;

            $regex  = $this->patternToRegex($route['pattern']);
            if (preg_match($regex, $path, $matches)) {
                // Named captures become route params
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
    }

    private function currentPath(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        // Strip the script directory (for sub-directory installs)
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        if ($scriptDir && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        return '/' . ltrim($path ?: '/', '/');
    }

    private function patternToRegex(string $pattern): string {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
