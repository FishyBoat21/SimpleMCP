<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;

/**
 * Opens the SQLite database and creates the schema idempotently.
 *
 * The file lives under data/ (gitignored) so no credentials or tokens are
 * ever committed. On first run the `users` table is seeded from config/users.php.
 */
final class Database {
    private PDO $pdo;

    public function __construct(?string $dbPath = null) {
        $dbPath ??= dirname(__DIR__, 2) . '/data/app.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');

        $this->createSchema();
    }

    public function pdo(): PDO {
        return $this->pdo;
    }

    private function createSchema(): void {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                username      TEXT PRIMARY KEY,
                name          TEXT NOT NULL DEFAULT '',
                email         TEXT,
                password_hash TEXT,
                roles         TEXT NOT NULL DEFAULT '[]',
                permissions   TEXT NOT NULL DEFAULT '[]',
                status        TEXT NOT NULL DEFAULT 'active',
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS auth_codes (
                code_hash             TEXT PRIMARY KEY,
                username              TEXT NOT NULL,
                client_id             TEXT NOT NULL,
                redirect_uri          TEXT NOT NULL,
                code_challenge        TEXT NOT NULL,
                code_challenge_method TEXT NOT NULL,
                expires_at            INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS access_tokens (
                token_hash TEXT PRIMARY KEY,
                username   TEXT NOT NULL,
                client_id  TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS refresh_tokens (
                token_hash TEXT PRIMARY KEY,
                username   TEXT NOT NULL,
                client_id  TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS clients (
                client_id                    TEXT PRIMARY KEY,
                client_secret_hash           TEXT,
                redirect_uris                TEXT NOT NULL,
                token_endpoint_auth_method   TEXT NOT NULL DEFAULT 'none',
                grant_types                  TEXT NOT NULL,
                response_types               TEXT NOT NULL,
                scope                        TEXT NOT NULL DEFAULT '',
                client_name                  TEXT NOT NULL DEFAULT '',
                registration_access_token_hash TEXT,
                client_id_issued_at          INTEGER NOT NULL,
                created_at                   INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS passkey_credentials (
                id            TEXT PRIMARY KEY,
                username      TEXT NOT NULL,
                public_key    TEXT NOT NULL,
                sign_count    INTEGER NOT NULL DEFAULT 0,
                aaguid        TEXT,
                name          TEXT NOT NULL DEFAULT 'Passkey',
                created_at    INTEGER NOT NULL,
                last_used_at  INTEGER,
                FOREIGN KEY(username) REFERENCES users(username) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_passkeys_username ON passkey_credentials(username);

            CREATE TABLE IF NOT EXISTS trusted_devices (
                id           TEXT PRIMARY KEY,
                username     TEXT NOT NULL,
                device_hash  TEXT NOT NULL,
                user_agent   TEXT NOT NULL DEFAULT '',
                ip_address   TEXT NOT NULL DEFAULT '',
                name         TEXT NOT NULL DEFAULT 'Web Browser',
                created_at   INTEGER NOT NULL,
                last_used_at INTEGER NOT NULL,
                expires_at   INTEGER NOT NULL,
                FOREIGN KEY(username) REFERENCES users(username) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_trusted_devices_user ON trusted_devices(username);
            CREATE INDEX IF NOT EXISTS idx_trusted_devices_hash ON trusted_devices(device_hash);

            CREATE TABLE IF NOT EXISTS email_otps (
                id          TEXT PRIMARY KEY,
                username    TEXT NOT NULL,
                otp_hash    TEXT NOT NULL,
                email       TEXT NOT NULL,
                attempts    INTEGER NOT NULL DEFAULT 0,
                created_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL,
                FOREIGN KEY(username) REFERENCES users(username) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_email_otps_user ON email_otps(username);
            SQL);

        // Migrate existing databases: ensure users table has the email column
        $cols = $this->pdo->query('PRAGMA table_info(users)')->fetchAll();
        $hasEmail = false;
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === 'email') {
                $hasEmail = true;
                break;
            }
        }
        if (!$hasEmail) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
        }
    }

    /**
     * Seed users from config when the table is empty, or backfill emails if missing.
     *
     * @param array<int, array<string, mixed>> $seedUsers
     */
    public function seedUsersIfEmpty(array $seedUsers): void {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            $this->backfillUserEmails($seedUsers);
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, name, email, password_hash, roles, permissions, status, created_at, updated_at)
             VALUES (:username, :name, :email, :password_hash, :roles, :permissions, :status, :created_at, :updated_at)'
        );

        $now = time();
        $this->pdo->beginTransaction();
        foreach ($seedUsers as $row) {
            $stmt->execute([
                ':username' => isset($row['username']) ? (string) $row['username'] : null,
                ':name' => (string) ($row['name'] ?? ''),
                ':email' => isset($row['email']) ? (string) $row['email'] : null,
                ':password_hash' => isset($row['password']) ? (string) $row['password'] : null,
                ':roles' => json_encode($row['roles'] ?? [], JSON_UNESCAPED_SLASHES),
                ':permissions' => json_encode($row['permissions'] ?? [], JSON_UNESCAPED_SLASHES),
                ':status' => (string) ($row['status'] ?? 'active'),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $this->pdo->commit();
    }

    /**
     * Backfill email addresses for existing users if missing.
     *
     * @param array<int, array<string, mixed>> $seedUsers
     */
    private function backfillUserEmails(array $seedUsers): void {
        $seedMap = [];
        foreach ($seedUsers as $row) {
            if (isset($row['username'], $row['email'])) {
                $seedMap[(string) $row['username']] = (string) $row['email'];
            }
        }

        $stmt = $this->pdo->query('SELECT username, email FROM users WHERE email IS NULL OR email = ""');
        $missing = $stmt->fetchAll();
        if (empty($missing)) {
            return;
        }

        $updateStmt = $this->pdo->prepare('UPDATE users SET email = :email, updated_at = :updated_at WHERE username = :username');
        $now = time();
        $this->pdo->beginTransaction();
        foreach ($missing as $row) {
            $username = (string) $row['username'];
            $email = $seedMap[$username] ?? ($username . '@example.com');
            $updateStmt->execute([
                ':email' => $email,
                ':updated_at' => $now,
                ':username' => $username,
            ]);
        }
        $this->pdo->commit();
    }
}
