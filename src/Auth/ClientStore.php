<?php

declare(strict_types=1);

namespace McpServer\Auth;

use InvalidArgumentException;

/**
 * OAuth client registry.
 *
 * Two sources are merged: static clients from config/oauth.php (pre-registered,
 * e.g. `mcp-client` and the `openwebui` confidential client) and clients created
 * dynamically via RFC 7591 dynamic client registration (stored in SQLite).
 *
 * Client secrets are never returned by {@see self::find()}; the registration
 * response carries the secret exactly once. Dynamically registered secrets are
 * sha256-hashed at rest.
 */
final class ClientStore {
    /** @param array<int, array<string, mixed>> $staticClients */
    public function __construct(
        private readonly Database $db,
        private readonly array $staticClients = [],
    ) {}

    /**
     * Look up a client (static or dynamic).
     *
     * @return array<string, mixed>|null normalized client record, or null
     */
    public function find(string $clientId): ?array {
        foreach ($this->staticClients as $client) {
            $id = (string) ($client['client_id'] ?? $client['id'] ?? '');
            if ($id === $clientId) {
                return $this->normalizeStatic($client);
            }
        }
        return $this->findDynamic($clientId);
    }

    public function verifySecret(string $clientId, string $secret): bool {
        foreach ($this->staticClients as $client) {
            $id = (string) ($client['client_id'] ?? $client['id'] ?? '');
            if ($id === $clientId) {
                $stored = $client['client_secret'] ?? null;
                return $stored !== null && hash_equals((string) $stored, $secret);
            }
        }
        $row = $this->findDynamic($clientId);
        return $row !== null
            && $row['client_secret_hash'] !== null
            && hash_equals((string) $row['client_secret_hash'], hash('sha256', $secret));
    }

    /**
     * Dynamic client registration (RFC 7591 §2).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> RFC 7591 §3.2.1 registration response.
     * @throws InvalidArgumentException on invalid metadata (-> invalid_client_metadata).
     */
    public function register(array $payload): array {
        $redirectUris = $this->validateRedirectUris($payload['redirect_uris'] ?? null);

        // Default to client_secret_post (not the RFC 7591 default of
        // client_secret_basic) for compatibility with Cherry Studio.
        $authMethod = (string) ($payload['token_endpoint_auth_method'] ?? 'client_secret_post');
        if (!in_array($authMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            throw new InvalidArgumentException('token_endpoint_auth_method must be one of: none, client_secret_basic, client_secret_post.');
        }

        $grantTypes = $this->validateSubset($payload['grant_types'] ?? ['authorization_code'], ['authorization_code', 'refresh_token'], 'grant_types');
        $responseTypes = $this->validateSubset($payload['response_types'] ?? ['code'], ['code'], 'response_types');

        $confidential = $authMethod !== 'none';
        $clientId = bin2hex(random_bytes(16));
        $clientSecret = $confidential ? bin2hex(random_bytes(24)) : null;
        $registrationAccessToken = bin2hex(random_bytes(32));

        $now = time();
        $this->db->pdo()->prepare(
            'INSERT INTO clients (client_id, client_secret_hash, redirect_uris, token_endpoint_auth_method, grant_types, response_types, scope, client_name, registration_access_token_hash, client_id_issued_at, created_at)
             VALUES (:client_id, :client_secret_hash, :redirect_uris, :auth_method, :grant_types, :response_types, :scope, :client_name, :registration_token_hash, :issued_at, :created_at)'
        )->execute([
            ':client_id' => $clientId,
            ':client_secret_hash' => $clientSecret !== null ? hash('sha256', $clientSecret) : null,
            ':redirect_uris' => json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
            ':auth_method' => $authMethod,
            ':grant_types' => json_encode($grantTypes, JSON_UNESCAPED_SLASHES),
            ':response_types' => json_encode($responseTypes, JSON_UNESCAPED_SLASHES),
            ':scope' => (string) ($payload['scope'] ?? ''),
            ':client_name' => (string) ($payload['client_name'] ?? ''),
            ':registration_token_hash' => hash('sha256', $registrationAccessToken),
            ':issued_at' => $now,
            ':created_at' => $now,
        ]);

        $response = [
            'client_id' => $clientId,
            'client_secret_expires_at' => 0,
            'client_id_issued_at' => $now,
            'registration_access_token' => $registrationAccessToken,
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => $grantTypes,
            'response_types' => $responseTypes,
            'scope' => (string) ($payload['scope'] ?? ''),
            'client_name' => (string) ($payload['client_name'] ?? ''),
        ];
        if ($confidential) {
            $response['client_secret'] = $clientSecret;
        }
        return $response;
    }

    /**
     * Redirect URIs must be exact-match https (except loopback) with no fragment.
     */
    public static function isValidRedirectUri(string $uri): bool {
        if ($uri === '' || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme === 'https') {
            return true;
        }
        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** @return array<string, mixed>|null */
    private function findDynamic(string $clientId): ?array {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM clients WHERE client_id = :client_id');
        $stmt->execute([':client_id' => $clientId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'client_id' => (string) $row['client_id'],
            'client_secret_hash' => $row['client_secret_hash'],
            'redirect_uris' => json_decode((string) $row['redirect_uris'], true) ?: [],
            'token_endpoint_auth_method' => (string) $row['token_endpoint_auth_method'],
            'grant_types' => json_decode((string) $row['grant_types'], true) ?: [],
            'response_types' => json_decode((string) $row['response_types'], true) ?: [],
            'scope' => (string) $row['scope'],
            'client_name' => (string) $row['client_name'],
            'registration_access_token_hash' => $row['registration_access_token_hash'],
        ];
    }

    /** @param array<string, mixed> $client */
    private function normalizeStatic(array $client): array {
        return [
            'client_id' => (string) ($client['client_id'] ?? $client['id'] ?? ''),
            'redirect_uris' => $client['redirect_uris'] ?? [],
            'token_endpoint_auth_method' => (string) ($client['token_endpoint_auth_method'] ?? 'none'),
            'grant_types' => $client['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'response_types' => $client['response_types'] ?? ['code'],
            'scope' => (string) ($client['scope'] ?? ''),
            'client_name' => (string) ($client['client_name'] ?? ''),
        ];
    }

    /** @return string[] */
    private function validateRedirectUris(mixed $value): array {
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException('redirect_uris must be a non-empty array.');
        }
        $uris = array_values(array_map(static fn(mixed $u): string => trim((string) $u), $value));
        if (count(array_unique($uris)) !== count($uris)) {
            throw new InvalidArgumentException('redirect_uris must not contain duplicates.');
        }
        foreach ($uris as $uri) {
            if (!self::isValidRedirectUri($uri)) {
                throw new InvalidArgumentException('Invalid redirect_uri: ' . $uri);
            }
        }
        return $uris;
    }

    /** @param string[] $allowed */
    private function validateSubset(mixed $value, array $allowed, string $field): array {
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException("$field must be a non-empty array.");
        }
        $items = array_values(array_map(static fn(mixed $v): string => (string) $v, $value));
        foreach ($items as $item) {
            if (!in_array($item, $allowed, true)) {
                throw new InvalidArgumentException("$field contains an unsupported value: $item");
            }
        }
        return $items;
    }
}
