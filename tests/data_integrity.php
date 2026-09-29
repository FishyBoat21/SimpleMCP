<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\DocumentStore;
use McpServer\Auth\EmbeddingIdentity;
use McpServer\Auth\EmbeddingService;
use McpServer\Auth\MemoryStore;

function same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$path = tempnam(sys_get_temp_dir(), 'simplemcp-integrity-');
if ($path === false) {
    throw new RuntimeException('Could not create a temporary SQLite file');
}

try {
    $failEmbeddings = false;
    $embedding = new EmbeddingService(
        ['model' => 'test', 'base_url' => 'http://localhost'],
        static function (array $request) use (&$failEmbeddings): array {
            if ($failEmbeddings) {
                throw new RuntimeException('injected embedding failure');
            }
            return array_map(static fn(string $text): array => [str_contains($text, 'Alice') ? 1.0 : 0.0, str_contains($text, 'Bob') ? 1.0 : 0.0], $request['input']);
        }
    );
    $documents = new DocumentStore($path, $embedding);
    $graph = new MemoryStore($path, $embedding);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $documents->ingestDocument('alice', ['id' => 'shared', 'filename' => 'same.txt', 'title' => 'Original', 'content' => 'Alice original content']);
    $documents->ingestDocument('bob', ['id' => 'shared', 'filename' => 'same.txt', 'content' => 'Bob different content']);
    $graph->createEntities('alice', [['id' => 'shared', 'name' => 'Alice', 'entityType' => 'person', 'observations' => ['Alice observation']]]);
    $graph->createEntities('bob', [['id' => 'shared', 'name' => 'Bob', 'entityType' => 'person', 'observations' => ['Bob observation']]]);

    $rows = $pdo->query('SELECT id, username, target_type, text_content FROM memory_embeddings ORDER BY username, target_type')->fetchAll(PDO::FETCH_ASSOC);
    same(4, count($rows), 'Both users retain their chunk and observation vectors');
    foreach ($rows as $row) {
        same(true, str_contains($row['text_content'], ucfirst($row['username'])), 'Vector content belongs to its owner');
        same(true, str_starts_with($row['id'], 'v2:'), 'Vector ID uses the new scheme');
    }
    same(4, count(array_unique(array_column($rows, 'id'))), 'Vector IDs are distinct across users and types');
    same(EmbeddingIdentity::chunk('alice', 'shared#0'), $pdo->query("SELECT embedding_id FROM memory_chunks WHERE username = 'alice'")->fetchColumn(), 'Chunk pointer is owner-specific');

    $failEmbeddings = true;
    try {
        $documents->ingestDocument('alice', ['id' => 'shared', 'filename' => 'same.txt', 'title' => 'Changed', 'content' => 'Alice replacement content']);
        throw new RuntimeException('Embedding failure was not raised');
    } catch (RuntimeException $e) {
        same('injected embedding failure', $e->getMessage(), 'Expected embedding failure');
    }
    $failEmbeddings = false;
    same('Alice original content', $pdo->query("SELECT content FROM memory_chunks WHERE username = 'alice'")->fetchColumn(), 'Embedding failure keeps old chunks');

    $pdo->exec("CREATE TRIGGER reject_replacement BEFORE INSERT ON memory_chunks WHEN NEW.content LIKE '%replacement%' BEGIN SELECT RAISE(FAIL, 'injected SQL failure'); END");
    try {
        $documents->ingestDocument('alice', ['id' => 'shared', 'filename' => 'same.txt', 'title' => 'Changed', 'content' => 'Alice replacement content']);
        throw new RuntimeException('SQL failure was not raised');
    } catch (PDOException $e) {
        same(true, str_contains($e->getMessage(), 'injected SQL failure'), 'Expected SQL failure');
    }
    same('Alice original content', $pdo->query("SELECT content FROM memory_chunks WHERE username = 'alice'")->fetchColumn(), 'SQL failure rolls back old chunks');
    same('Original', $pdo->query("SELECT title FROM memory_documents WHERE username = 'alice'")->fetchColumn(), 'SQL failure rolls back metadata');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM memory_chunks_fts WHERE username = 'alice' AND content = 'Alice original content'")->fetchColumn(), 'SQL failure rolls back FTS');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM memory_embeddings WHERE username = 'alice' AND target_type = 'chunk'")->fetchColumn(), 'SQL failure rolls back vectors');
    $pdo->exec('DROP TRIGGER reject_replacement');

    $result = $documents->ingestDocument('alice', ['id' => 'shared', 'filename' => 'same.txt', 'title' => 'Changed', 'content' => 'Alice replacement content']);
    same(true, $result['replaced'], 'Successful replacement reports the previous document');
    same('Alice replacement content', $pdo->query("SELECT content FROM memory_chunks WHERE username = 'alice'")->fetchColumn(), 'Successful replacement updates chunks');
    same('Changed', $pdo->query("SELECT title FROM memory_documents WHERE username = 'alice'")->fetchColumn(), 'Successful replacement updates metadata');
    same(0, (int) $pdo->query("SELECT COUNT(*) FROM memory_chunks_fts WHERE username = 'alice' AND content = 'Alice original content'")->fetchColumn(), 'Successful replacement removes old FTS rows');
    same(1, (int) $pdo->query("SELECT COUNT(*) FROM memory_embeddings WHERE username = 'alice' AND target_type = 'chunk' AND text_content = 'Alice replacement content'")->fetchColumn(), 'Successful replacement updates the vector');

    // Simulate a pre-fix database. The one-time migration must discard all
    // possibly mixed vectors, leaving source rows available for regeneration.
    $pdo->exec("UPDATE memory_embeddings SET id = 'chunk:shared#0' WHERE username = 'alice' AND target_type = 'chunk'");
    $pdo->exec("DELETE FROM memory_store_meta WHERE key = 'embedding_identity_v2'");
    unset($graph);
    $graph = new MemoryStore($path, $embedding);
    same(0, (int) $pdo->query('SELECT COUNT(*) FROM memory_embeddings')->fetchColumn(), 'Migration removes unsafe vectors');
    same(null, $pdo->query("SELECT embedding_id FROM memory_chunks WHERE username = 'alice'")->fetchColumn(), 'Migration clears chunk pointers');
    same('[]', $pdo->query("SELECT embedding_pointers FROM memory_entities WHERE username = 'alice'")->fetchColumn(), 'Migration clears observation pointers');
    same(2, $documents->syncAllChunkEmbeddings('alice', true)['chunks_processed'] + $documents->syncAllChunkEmbeddings('bob', true)['chunks_processed'], 'Chunks can be regenerated');
    $graph->syncAllObservationEmbeddings('alice', true);
    $graph->syncAllObservationEmbeddings('bob', true);
    same(4, (int) $pdo->query('SELECT COUNT(*) FROM memory_embeddings')->fetchColumn(), 'All vectors regenerate after migration');

    echo "Data integrity tests passed\n";
} finally {
    unset($documents, $graph, $pdo);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
