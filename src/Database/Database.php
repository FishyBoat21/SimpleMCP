<?php

declare(strict_types=1);

namespace McpServer\Database;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;
    private static string $dbPath = __DIR__ . '/../../data/oauth.db';

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dir = dirname(self::$dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . self::$dbPath, options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            self::migrate(self::$instance);
        }

        return self::$instance;
    }

    private static function migrate(PDO $pdo): void {
        $pdo->exec("PRAGMA journal_mode=WAL;");
        $pdo->exec("PRAGMA foreign_keys=ON;");

        // Users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                username    TEXT NOT NULL UNIQUE,
                password    TEXT NOT NULL,
                email       TEXT,
                role        TEXT NOT NULL DEFAULT 'user',
                created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
        ");

        // OAuth2 clients
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS oauth_clients (
                client_id     TEXT PRIMARY KEY,
                client_secret TEXT NOT NULL,
                name          TEXT NOT NULL,
                redirect_uris TEXT NOT NULL,  -- JSON array
                scopes        TEXT NOT NULL DEFAULT 'mcp',
                created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );
        ");

        // Authorization codes (short-lived, single-use)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS oauth_auth_codes (
                code          TEXT PRIMARY KEY,
                client_id     TEXT NOT NULL,
                user_id       INTEGER NOT NULL,
                redirect_uri  TEXT NOT NULL,
                scopes        TEXT NOT NULL,
                code_challenge      TEXT,
                code_challenge_method TEXT,
                expires_at    INTEGER NOT NULL,
                used          INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (client_id) REFERENCES oauth_clients(client_id),
                FOREIGN KEY (user_id)   REFERENCES users(id)
            );
        ");

        // Access tokens
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS oauth_access_tokens (
                token         TEXT PRIMARY KEY,
                client_id     TEXT NOT NULL,
                user_id       INTEGER NOT NULL,
                scopes        TEXT NOT NULL,
                expires_at    INTEGER NOT NULL,
                revoked       INTEGER NOT NULL DEFAULT 0,
                created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                FOREIGN KEY (client_id) REFERENCES oauth_clients(client_id),
                FOREIGN KEY (user_id)   REFERENCES users(id)
            );
        ");

        // Refresh tokens
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
                token         TEXT PRIMARY KEY,
                access_token  TEXT NOT NULL,
                expires_at    INTEGER NOT NULL,
                revoked       INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (access_token) REFERENCES oauth_access_tokens(token)
            );
        ");

        // Add role column for existing databases
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
            $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");
        } catch (PDOException $e) {
            // Column might already exist
        }

        // Seed a default admin user if none exist
        $count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count === 0) {
            $hash = password_hash('admin', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)")
                ->execute(['admin', $hash, 'admin@localhost', 'admin']);
        }

        // Seed a default MCP client if none exist
        $count = (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients")->fetchColumn();
        if ($count === 0) {
            $secret = bin2hex(random_bytes(32));
            $pdo->prepare("
                INSERT INTO oauth_clients (client_id, client_secret, name, redirect_uris, scopes)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                'mcp-default-client',
                $secret,
                'MCP Default Client',
                json_encode(['http://127.0.0.1:33389/mcp-oauth-callback']),
                'mcp profile'
            ]);
        }
    }
}
