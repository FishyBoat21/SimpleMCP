<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;

/** Stable, globally unique IDs for vectors in the shared embeddings table. */
final class EmbeddingIdentity {
    public static function chunk(string $username, string $chunkId): string {
        return self::id($username, 'chunk', $chunkId);
    }

    public static function observation(string $username, string $entityId, int $index): string {
        return self::id($username, 'observation', $entityId, $index);
    }

    private static function id(string $username, string $type, string $targetId, ?int $index = null): string {
        return 'v2:' . hash('sha256', json_encode([$username, $type, $targetId, $index], JSON_THROW_ON_ERROR));
    }

    /**
     * Old IDs omitted the owner. Their rows may already contain another user's
     * content, so discard these derived vectors and regenerate them from source.
     * The marker makes the migration safe when both stores open the same DB.
     */
    public static function migrate(PDO $pdo): void {
        $pdo->exec('CREATE TABLE IF NOT EXISTS memory_store_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->beginTransaction();
        try {
            $done = $pdo->query("SELECT value FROM memory_store_meta WHERE key = 'embedding_identity_v2'")->fetchColumn();
            if ($done === false) {
                $pdo->exec('DELETE FROM memory_embeddings');
                if (self::hasColumn($pdo, 'memory_chunks', 'embedding_id')) {
                    $pdo->exec('UPDATE memory_chunks SET embedding_id = NULL');
                }
                if (self::hasColumn($pdo, 'memory_entities', 'embedding_pointers')) {
                    $pdo->exec("UPDATE memory_entities SET embedding_pointers = '[]'");
                }
                $pdo->exec("INSERT INTO memory_store_meta (key, value) VALUES ('embedding_identity_v2', '1')");
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool {
        $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        return in_array($column, array_column($columns, 'name'), true);
    }
}
