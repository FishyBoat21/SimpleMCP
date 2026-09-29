<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * SQLite-backed storage for WebAuthn passkey credentials.
 */
final class PasskeyStore {
    public function __construct(private readonly Database $db) {}

    /**
     * Store a newly registered passkey credential.
     */
    public function create(
        string $id,
        string $username,
        string $publicKeyPem,
        int $signCount = 0,
        ?string $aaguid = null,
        string $name = 'Passkey'
    ): void {
        $now = time();
        $this->db->pdo()->prepare(
            'INSERT INTO passkey_credentials (id, username, public_key, sign_count, aaguid, name, created_at, last_used_at)
             VALUES (:id, :username, :public_key, :sign_count, :aaguid, :name, :created_at, :last_used_at)'
        )->execute([
            ':id' => $id,
            ':username' => $username,
            ':public_key' => $publicKeyPem,
            ':sign_count' => $signCount,
            ':aaguid' => $aaguid,
            ':name' => $name !== '' ? $name : 'Passkey',
            ':created_at' => $now,
            ':last_used_at' => $now,
        ]);
    }

    /**
     * Find credential by its base64url credential ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM passkey_credentials WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Get all passkey credentials registered for a given username.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByUsername(string $username): array {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM passkey_credentials WHERE username = :username ORDER BY created_at DESC');
        $stmt->execute([':username' => $username]);
        return $stmt->fetchAll();
    }

    /**
     * Update sign count and last used timestamp after successful authentication.
     */
    public function updateSignCount(string $id, int $signCount): void {
        $this->db->pdo()->prepare(
            'UPDATE passkey_credentials SET sign_count = :sign_count, last_used_at = :last_used_at WHERE id = :id'
        )->execute([
            ':sign_count' => $signCount,
            ':last_used_at' => time(),
            ':id' => $id,
        ]);
    }

    /**
     * Delete a passkey owned by a user.
     */
    public function delete(string $id, string $username): bool {
        $stmt = $this->db->pdo()->prepare('DELETE FROM passkey_credentials WHERE id = :id AND username = :username');
        $stmt->execute([':id' => $id, ':username' => $username]);
        return $stmt->rowCount() > 0;
    }
}
