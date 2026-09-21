<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Per-account preferences editable on the /account page.
 *
 * A JSON object per username in the `user_settings` table of data/app.sqlite,
 * so each account stores its own values (e.g. the Stirling-PDF endpoint and
 * API key used by the markdown→PDF tool). In stdio mode the tool ignores these
 * and reads config/config.php instead — see McpServer\StirlingPdfClient.
 *
 * Table is created idempotently by {@see Database::createSchema()}.
 */
final class SettingsStore {
    private readonly Database $db;

    public function __construct(?Database $db = null) {
        // NB: non-promoted readonly assigned here so a null argument is allowed
        // to fall back to a fresh Database (readonly props can't be defaulted
        // AND reassigned).
        $this->db = $db ?? new Database();
    }

    /** @return array<string, mixed> the account's full settings object */
    public function get(string $username): array {
        if ($username === '') {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT settings FROM user_settings WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();
        if ($row === false) {
            return [];
        }
        $decoded = json_decode((string) $row['settings'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return mixed the named setting, or $default when absent */
    public function getSetting(string $username, string $key, mixed $default = null): mixed {
        $settings = $this->get($username);
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Upsert: merge $settings over the account's current values and persist.
     * Keys set to ''/null are dropped so callers can't store empty overrides
     * that would shadow the global config defaults.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed> the full saved settings object
     */
    public function set(string $username, array $settings): array {
        $saved = $this->get($username);
        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                unset($saved[$key]);
            } else {
                $saved[$key] = $value;
            }
        }

        $this->db->pdo()->prepare(
            'INSERT INTO user_settings (username, settings, updated_at)
             VALUES (:username, :settings, :updated_at)
             ON CONFLICT (username) DO UPDATE SET
                 settings   = excluded.settings,
                 updated_at = excluded.updated_at'
        )->execute([
            ':username' => $username,
            ':settings' => json_encode($saved, JSON_UNESCAPED_SLASHES),
            ':updated_at' => time(),
        ]);

        return $saved;
    }
}