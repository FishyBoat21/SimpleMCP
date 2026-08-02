<?php

declare(strict_types=1);

namespace McpServer;

/**
 * Immutable description of the user making the current request.
 *
 * In HTTP mode this is resolved from the OAuth bearer token; in CLI/stdio mode
 * the server falls back to {@see self::local()}, a trusted user with full access.
 *
 * The `*` wildcard inside $roles / $permissions matches any requirement.
 */
readonly class UserContext {
    /**
     * @param string[] $roles
     * @param string[] $permissions
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $username,
        public string $name = '',
        public array $roles = [],
        public array $permissions = [],
        public array $attributes = [],
    ) {}

    public function hasRole(string $role): bool {
        return $this->hasAnyRole([$role]);
    }

    public function hasPermission(string $permission): bool {
        return $this->hasAnyPermission([$permission]);
    }

    /** @param string[] $roles */
    public function hasAnyRole(array $roles): bool {
        return $this->hasAny($this->roles, $roles);
    }

    /** @param string[] $permissions */
    public function hasAnyPermission(array $permissions): bool {
        return $this->hasAny($this->permissions, $permissions);
    }

    /** @param string[] $granted @param string[] $required */
    private function hasAny(array $granted, array $required): bool {
        if (in_array('*', $granted, true)) {
            return true;
        }
        return !empty(array_intersect($required, $granted));
    }

    /** @return array{username: string, name: string, roles: string[], permissions: string[], attributes: array<string, mixed>} */
    public function toArray(): array {
        return [
            'username' => $this->username,
            'name' => $this->name,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            username: (string) ($data['username'] ?? 'anonymous'),
            name: (string) ($data['name'] ?? ''),
            roles: self::stringList($data['roles'] ?? []),
            permissions: self::stringList($data['permissions'] ?? []),
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
        );
    }

    /** Trusted user used by the CLI/stdio loop — full access. */
    public static function local(): self {
        return new self(
            username: 'local',
            name: 'Local User',
            roles: ['*'],
            permissions: ['*'],
        );
    }

    /** Untrusted user with no roles/permissions. */
    public static function anonymous(): self {
        return new self(
            username: 'anonymous',
            name: 'Anonymous',
        );
    }

    /** @return string[] */
    private static function stringList(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn(mixed $v): string => (string) $v, $value));
    }
}
