<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * SQLite-backed OAuth token storage.
 *
 * Tokens handed to clients are 256-bit random strings; only their sha256 hashes
 * are stored at rest. Authorization codes are single-use (consumed atomically),
 * refresh tokens rotate (old deleted before new inserted, so replay fails).
 */
final class TokenStore {
    public function __construct(private readonly Database $db) {}

    public function createAuthCode(
        string $username,
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        int $ttl,
    ): string {
        $code = $this->generateToken();
        $this->db->pdo()->prepare(
            'INSERT INTO auth_codes (code_hash, username, client_id, redirect_uri, code_challenge, code_challenge_method, expires_at)
             VALUES (:hash, :username, :client_id, :redirect_uri, :challenge, :method, :expires_at)'
        )->execute([
            ':hash' => $this->hash($code),
            ':username' => $username,
            ':client_id' => $clientId,
            ':redirect_uri' => $redirectUri,
            ':challenge' => $codeChallenge,
            ':method' => $codeChallengeMethod,
            ':expires_at' => time() + $ttl,
        ]);
        return $code;
    }

    /**
     * Redeem an authorization code. Atomic and single-use: the row is deleted on
     * the first presentation, even if the binding check fails (prevents retries).
     *
     * @param array{client_id: string, redirect_uri: string} $binding expected client + redirect
     * @return array{username: string, code_challenge: string, code_challenge_method: string}|null
     */
    public function consumeAuthCode(string $code, array $binding): ?array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM auth_codes WHERE code_hash = :hash');
            $stmt->execute([':hash' => $this->hash($code)]);
            $row = $stmt->fetch();

            // Always delete: a code must never be redeemable twice.
            $pdo->prepare('DELETE FROM auth_codes WHERE code_hash = :hash')
                ->execute([':hash' => $this->hash($code)]);
            $pdo->commit();

            if ($row === false) {
                return null;
            }
            if ((int) $row['expires_at'] < time()) {
                return null;
            }
            if ($row['client_id'] !== $binding['client_id'] || $row['redirect_uri'] !== $binding['redirect_uri']) {
                return null;
            }
            return [
                'username' => (string) $row['username'],
                'code_challenge' => (string) $row['code_challenge'],
                'code_challenge_method' => (string) $row['code_challenge_method'],
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createAccessToken(string $username, string $clientId, int $ttl): string {
        $token = $this->generateToken();
        $this->db->pdo()->prepare(
            'INSERT INTO access_tokens (token_hash, username, client_id, expires_at, created_at)
             VALUES (:hash, :username, :client_id, :expires_at, :created_at)'
        )->execute([
            ':hash' => $this->hash($token),
            ':username' => $username,
            ':client_id' => $clientId,
            ':expires_at' => time() + $ttl,
            ':created_at' => time(),
        ]);
        return $token;
    }

    /** @return array<string, mixed>|null null when absent or expired. */
    public function findAccessToken(string $token): ?array {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM access_tokens WHERE token_hash = :hash');
        $stmt->execute([':hash' => $this->hash($token)]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['expires_at'] < time()) {
            return null;
        }
        return $row;
    }

    public function createRefreshToken(string $username, string $clientId, int $ttl): string {
        $token = $this->generateToken();
        $this->db->pdo()->prepare(
            'INSERT INTO refresh_tokens (token_hash, username, client_id, expires_at, created_at)
             VALUES (:hash, :username, :client_id, :expires_at, :created_at)'
        )->execute([
            ':hash' => $this->hash($token),
            ':username' => $username,
            ':client_id' => $clientId,
            ':expires_at' => time() + $ttl,
            ':created_at' => time(),
        ]);
        return $token;
    }

    /**
     * Rotate a refresh token: the presented token is deleted (replay fails) and
     * replaced with a fresh pair.
     *
     * @return array{access_token: string, refresh_token: string, username: string}|null
     */
    public function rotateRefreshToken(string $refreshToken, int $accessTtl, int $refreshTtl): ?array {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM refresh_tokens WHERE token_hash = :hash');
            $stmt->execute([':hash' => $this->hash($refreshToken)]);
            $row = $stmt->fetch();

            $pdo->prepare('DELETE FROM refresh_tokens WHERE token_hash = :hash')
                ->execute([':hash' => $this->hash($refreshToken)]);
            $pdo->commit();

            if ($row === false || (int) $row['expires_at'] < time()) {
                return null;
            }

            $username = (string) $row['username'];
            $clientId = (string) $row['client_id'];
            return [
                'access_token' => $this->createAccessToken($username, $clientId, $accessTtl),
                'refresh_token' => $this->createRefreshToken($username, $clientId, $refreshTtl),
                'username' => $username,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function generateToken(): string {
        return bin2hex(random_bytes(32));
    }

    private function hash(string $token): string {
        return hash('sha256', $token);
    }
}
