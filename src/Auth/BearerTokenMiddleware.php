<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Middleware that validates Bearer tokens on HTTP MCP requests.
 * On success, stores the token payload in $_REQUEST['_oauth_user'].
 * On failure, sends a 401 Unauthorized JSON response and exits.
 */
class BearerTokenMiddleware {
    private OAuthServer $oauth;

    public function __construct(OAuthServer $oauth) {
        $this->oauth = $oauth;
    }

    /**
     * @param string[] $requiredScopes Scopes that must be present in the token
     */
    public function authenticate(array $requiredScopes = ['mcp']): array {
        $token = $this->extractBearerToken();

        if ($token === null) {
            $this->unauthorized('Bearer token required', 'invalid_token');
        }

        $payload = $this->oauth->introspectToken($token);

        if ($payload === null) {
            $this->unauthorized('Token is invalid, expired, or revoked', 'invalid_token');
        }

        // Scope check
        $grantedScopes = array_filter(array_map('trim', explode(' ', $payload['scopes'])));
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $grantedScopes, true)) {
                $this->unauthorized("Insufficient scope. Required: $scope", 'insufficient_scope');
            }
        }

        return $payload;
    }

    private function extractBearerToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        // Also check query param as fallback (discouraged but handy for testing)
        return $_GET['access_token'] ?? null;
    }

    private function unauthorized(string $message, string $errorCode): never {
        http_response_code(401);
        header('WWW-Authenticate: Bearer realm="MCP Server", error="' . $errorCode . '", error_description="' . addslashes($message) . '"');
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                'code'    => -32001,
                'message' => $message,
                'data'    => ['oauth_error' => $errorCode]
            ]
        ]);
        exit;
    }
}
