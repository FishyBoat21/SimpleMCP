<?php

declare(strict_types=1);

namespace McpServer\Auth;

use McpServer\UserContext;

/**
 * DB-backed user accounts.
 *
 * A user is "pending" (needs onboarding) when their row exists with no password
 * set (`password_hash` IS NULL / status 'pending'). Such users are routed into a
 * "set password" flow wherever they attempt to log in; they keep the username
 * that was provisioned for them (it is not editable).
 */
final class UserStore {
    public function __construct(private readonly Database $db) {}

    /** @return array<string, mixed>|null */
    public function getByUsername(string $username): ?array {
        return $this->fetch('SELECT * FROM users WHERE username = :username', [':username' => $username]);
    }

    /**
     * Verify a username/password pair.
     *
     * @return array<string, mixed>|null the user row, or null for unknown user /
     *         wrong password / pending account (no user enumeration).
     */
    public function authenticate(string $username, string $password): ?array {
        $row = $this->getByUsername($username);
        if ($row === null || !$this->verifyPassword($password, $row['password_hash'] ?? null)) {
            return null;
        }
        return $row;
    }

    public function isPending(string $username): bool {
        $row = $this->getByUsername($username);
        return $row !== null && $this->rowIsPending($row);
    }

    /**
     * Complete onboarding for a pending user, or create a brand-new active user.
     *
     * @param string|null $existingUsername the pending user's provisioned username
     *        when onboarding an existing row, or null for brand-new public onboarding.
     * @param string $username the username a brand-new user chose; ignored when
     *        `$existingUsername` is set (a pending user keeps their provisioned name).
     * @return UserContext|null null when the username is taken or invalid.
     */
    public function onboardUser(?string $existingUsername, string $username, string $password, string $name = ''): ?UserContext {
        if (!$this->validateNewPassword($password)) {
            return null;
        }

        if ($existingUsername !== null) {
            $row = $this->getByUsername($existingUsername);
            if ($row === null || !$this->rowIsPending($row)) {
                return null;
            }
            // A pending user keeps their provisioned username; it is not editable.
            $username = (string) $row['username'];
            $this->db->pdo()->prepare(
                'UPDATE users SET name = :name, password_hash = :hash, status = :status, updated_at = :updated_at WHERE username = :username'
            )->execute([
                ':name' => $name !== '' ? $name : (string) $row['name'],
                ':hash' => $this->hashPassword($password),
                ':status' => 'active',
                ':updated_at' => time(),
                ':username' => $row['username'],
            ]);
            return $this->toUserContext($this->getByUsername($row['username']) ?? $row);
        }

        // Brand-new user (public onboarding) chooses their own username.
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            return null;
        }
        if (!$this->usernameAvailable($username, null)) {
            return null;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO users (username, name, password_hash, roles, permissions, status, created_at, updated_at)
             VALUES (:username, :name, :hash, :roles, :permissions, :status, :created_at, :updated_at)'
        )->execute([
            ':username' => $username,
            ':name' => $name,
            ':hash' => $this->hashPassword($password),
            ':roles' => '[]',
            ':permissions' => '[]',
            ':status' => 'active',
            ':created_at' => time(),
            ':updated_at' => time(),
        ]);
        return $this->toUserContext($this->getByUsername($username) ?? []);
    }

    public function changePassword(string $username, string $oldPassword, string $newPassword): bool {
        $row = $this->getByUsername($username);
        if ($row === null || !$this->verifyPassword($oldPassword, $row['password_hash'] ?? null)) {
            return false;
        }
        if (!$this->validateNewPassword($newPassword)) {
            return false;
        }
        $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE username = :username'
        )->execute([
            ':hash' => $this->hashPassword($newPassword),
            ':updated_at' => time(),
            ':username' => $username,
        ]);
        return true;
    }

    public function toUserContext(array $row): UserContext {
        return new UserContext(
            username: (string) ($row['username'] ?? 'unknown'),
            name: (string) ($row['name'] ?? ''),
            roles: $this->decodeList($row['roles'] ?? '[]'),
            permissions: $this->decodeList($row['permissions'] ?? '[]'),
        );
    }

    /** @param string[] $roles */
    public function setRoles(string $username, array $roles): void {
        $this->db->pdo()->prepare('UPDATE users SET roles = :roles, updated_at = :updated_at WHERE username = :username')->execute([
            ':roles' => json_encode(array_values($roles), JSON_UNESCAPED_SLASHES),
            ':updated_at' => time(),
            ':username' => $username,
        ]);
    }

    // ---- internals ---------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function fetch(string $sql, array $params): ?array {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function rowIsPending(array $row): bool {
        return ($row['status'] ?? 'active') === 'pending' || ($row['password_hash'] ?? null) === null;
    }

    private function verifyPassword(string $password, ?string $storedHash): bool {
        if ($storedHash === null || $storedHash === '') {
            return false;
        }
        // bcrypt/argon2 hashes are verified by the password API.
        if (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$argon2')) {
            return password_verify($password, $storedHash);
        }
        // Plaintext fallback for ad-hoc development configs.
        return hash_equals($storedHash, $password);
    }

    private function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    private function normalizeUsername(string $username): string {
        return trim($username);
    }

    private function validateNewPassword(string $password): bool {
        return strlen($password) >= 8;
    }

    private function usernameAvailable(string $username, ?string $excludeUsername): bool {
        $row = $this->getByUsername($username);
        if ($row === null) {
            return true;
        }
        return $excludeUsername !== null && $row['username'] === $excludeUsername;
    }

    /** @return string[] */
    private function decodeList(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_map(static fn(mixed $v): string => (string) $v, $decoded));
    }
}
