<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\DocumentStore;
use McpServer\Auth\EmbeddingService;
use McpServer\Auth\MemoryStore;
use McpServer\McpServer;
use McpServer\Tools\KnowledgeBaseTool;
use McpServer\UserContext;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'simplemcp-retrieval-');
$sourcePath = tempnam(sys_get_temp_dir(), 'simplemcp-source-');
if ($path === false || $sourcePath === false) {
    throw new RuntimeException('Could not create temporary SQLite file');
}

try {
    $calls = 0;
    $embeddingA = new EmbeddingService(
        ['model' => 'model-a', 'base_url' => 'http://localhost'],
        static function (array $request) use (&$calls): array {
            $calls++;
            return array_map(static fn(string $text): array => [1.0, str_contains($text, 'alpha') ? 1.0 : 0.0], $request['input']);
        }
    );
    check(EmbeddingService::cosineSimilarity([1.0, 0.0], [1.0]) === 0.0, 'Mismatched vector dimensions cannot be scored');
    $documents = new DocumentStore($path, $embeddingA);
    $documents->ingestDocument('alice', ['id' => 'doc', 'filename' => 'doc.txt', 'content' => 'alpha evidence']);
    $calls = 0;
    check($documents->retrieve('alice', 'alpha', 'keyword')['total'] === 1, 'Keyword lookup finds the chunk');
    check($calls === 0, 'Keyword-only retrieval must not call the embedding endpoint');
    check(isset($documents->ingestDocument('alice', ['path' => __FILE__])['error']), 'Direct text ingestion rejects paths');
    check(isset($documents->ingestDocument('alice', ['file_path' => __FILE__, 'content' => 'bypass', 'filename' => 'bypass.txt'])['error']), 'Direct text ingestion rejects path aliases');
    file_put_contents($sourcePath, 'Local source text');
    check($documents->ingestDocumentFromPath('alice', ['path' => $sourcePath, 'id' => 'local'])['chunkCount'] === 1, 'Local path ingestion reads a text file');

    $embeddingB = new EmbeddingService(
        ['model' => 'model-b', 'base_url' => 'http://localhost'],
        static fn(array $request): array => array_map(static fn(string $text): array => [0.0, 1.0], $request['input'])
    );
    $documentsB = new DocumentStore($path, $embeddingB);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $oldProfile = $pdo->query("SELECT profile FROM memory_embeddings WHERE username = 'alice' AND target_type = 'chunk' AND target_id = 'doc#0'")->fetchColumn();
    check($documentsB->retrieve('alice', 'alpha', 'semantic')['total'] === 1, 'Semantic retrieval falls back while new-model vectors are pending');
    check($pdo->query("SELECT profile FROM memory_embeddings WHERE username = 'alice' AND target_type = 'chunk' AND target_id = 'doc#0'")->fetchColumn() === $oldProfile, 'Retrieval does not backfill vectors');
    check($documentsB->syncAllChunkEmbeddings('alice')['chunks_embedded'] === 2, 'Model change re-embeds existing chunks');
    check($pdo->query("SELECT profile FROM memory_embeddings WHERE username = 'alice' AND target_type = 'chunk'")->fetchColumn() === $embeddingB->getProfile(), 'Stored vector has current profile');

    $graphA = new MemoryStore($path, $embeddingA);
    $graphA->createEntities('alice', [['id' => 'profile-check', 'name' => 'Profile check', 'entityType' => 'concept', 'observations' => ['alpha evidence']]]);
    $graphA->createEntities('alice', [['id' => 'profile-check', 'name' => 'Profile check', 'entityType' => 'concept', 'observations' => ['alpha evidence']]]);
    check($graphA->addObservations('alice', [['entityName' => 'profile-check', 'contents' => ['alpha evidence']]])['updates']['profile-check']['added'] === 0, 'Duplicate observation is a no-op');
    check((int) $pdo->query("SELECT COUNT(*) FROM memory_entities_history WHERE id = 'profile-check'")->fetchColumn() === 0, 'Idempotent writes do not create historical versions');
    $graphB = new MemoryStore($path, $embeddingB);
    check(($graphB->searchGraph('alice', 'alpha', 'semantic', 5, 0)['results'][0]['id'] ?? null) === 'profile-check', 'Graph search falls back while new-model vectors are pending');
    check($pdo->query("SELECT profile FROM memory_embeddings WHERE target_type = 'observation' AND target_id = 'profile-check'")->fetchColumn() === $embeddingA->getProfile(), 'Graph retrieval does not backfill vectors');
    check($graphB->syncAllObservationEmbeddings('alice', false, null, 50, true)['pending_observations'] === 1, 'Dry run counts stale observation vectors');
    check($graphB->syncAllObservationEmbeddings('alice')['observations_embedded'] === 1, 'Graph model change regenerates vectors');

    $rpc = new McpServer();
    $rpc->registerTool(new KnowledgeBaseTool());
    $listRequest = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR);
    $httpList = json_decode($rpc->handleRequest($listRequest, UserContext::local()), true, flags: JSON_THROW_ON_ERROR);
    $httpNames = array_column($httpList['result']['tools'], 'name');
    check(in_array('ingest_document', $httpNames, true), 'Text ingestion remains available over HTTP');
    check(!in_array('ingest_document_from_path', $httpNames, true), 'Path ingestion is hidden over HTTP');
    $callRequest = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'ingest_document_from_path', 'arguments' => ['path' => __FILE__]]], JSON_THROW_ON_ERROR);
    $httpCall = json_decode($rpc->handleRequest($callRequest, UserContext::local()), true, flags: JSON_THROW_ON_ERROR);
    check($httpCall['error']['code'] === -32001, 'Direct HTTP path calls are rejected');
    $localList = json_decode($rpc->handleRequest($listRequest), true, flags: JSON_THROW_ON_ERROR);
    check(in_array('ingest_document_from_path', array_column($localList['result']['tools'], 'name'), true), 'Path ingestion is available on stdio');
    $httpAfterLocal = json_decode($rpc->handleRequest($callRequest, UserContext::local()), true, flags: JSON_THROW_ON_ERROR);
    check($httpAfterLocal['error']['code'] === -32001, 'A local request never unlocks the next HTTP request');

    $withoutEmbeddings = new EmbeddingService(['api_key' => '']);
    $graph = new MemoryStore($path, $withoutEmbeddings);
    $now = time();
    $before = $now - 5;
    $graph->createEntities('alice', [
        ['id' => 'a', 'name' => 'Alpha', 'entityType' => 'concept', 'observations' => ['old fact'], 'validFrom' => $now - 20],
        ['id' => 'b', 'name' => 'Beta', 'entityType' => 'concept', 'observations' => [], 'validFrom' => $now - 20],
    ]);
    $graph->createRelations('alice', [['from' => 'a', 'to' => 'b', 'relationType' => 'related_to', 'validFrom' => $now - 20]]);
    $pdo->exec('UPDATE memory_entities SET updated_at = ' . ($now - 10) . " WHERE username = 'alice' AND id IN ('a','b')");
    $pdo->exec('UPDATE memory_relations SET updated_at = ' . ($now - 10) . " WHERE username = 'alice' AND from_entity = 'a'");
    $graph->addObservations('alice', [['entityName' => 'a', 'contents' => ['new fact']]]);
    $graph->invalidateRelation('alice', 'a', 'b', 'related_to', $now);

    $historical = $graph->readGraph('alice', $before, false, 'a', 1, null, 100, 0, true);
    check(count($historical['entities']) === 2, 'Historical subgraph contains both entities');
    check(count($historical['relations']) === 1, 'Historical relation remains visible');
    $old = $graph->readGraph('alice', $before, false, null, 0, 'a');
    check($old['entity']['observations'] === ['old fact'], 'Historical entity returns old observations');
    $oldSearch = $graph->searchGraph('alice', 'old', 'keyword', 5, 0, $before);
    check(($oldSearch['results'][0]['id'] ?? null) === 'a', 'Historical FTS searches old observations');
    check(($graph->searchGraph('alice', 'old', 'semantic', 5, 0, $before)['results'][0]['id'] ?? null) === 'a', 'Historical semantic search uses historical text');
    $current = $graph->readGraph('alice', null, false, null, 0, 'a');
    check($current['entity']['observations'] === ['old fact', 'new fact'], 'Current observations include the update');
    $graph->createRelations('alice', [['from' => 'a', 'to' => 'b', 'relationType' => 'related_to', 'validFrom' => $now + 10]]);
    check(count($graph->readGraph('alice', $now + 5, false, 'a', 1)['relations']) === 0, 'Reactivation preserves the validity gap');
    check(count($graph->readGraph('alice', $now + 11, false, 'a', 1)['relations']) === 1, 'Reactivated relation appears in its new window');

    echo "Retrieval and history tests passed\n";
} finally {
    unset($documents, $documentsB, $graph, $graphA, $graphB, $pdo);
    foreach ([$path, $path . '-wal', $path . '-shm', $sourcePath] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
