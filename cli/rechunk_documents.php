<?php

declare(strict_types=1);

/**
 * CLI tool for re-chunking existing documents in SimpleMCP.
 *
 * Re-processes stored documents using structural and semantic chunking,
 * updating chunks, section headings, and vector embeddings atomically per document.
 *
 * Usage:
 *   php cli/rechunk_documents.php --all                       # Re-chunk all documents with auto strategy
 *   php cli/rechunk_documents.php --id=my-doc                 # Re-chunk specific document
 *   php cli/rechunk_documents.php --user=local --all          # Target specific user
 *   php cli/rechunk_documents.php --chunking=semantic         # Force semantic chunking
 *   php cli/rechunk_documents.php --from-source               # Re-read from source file if readable
 *   php cli/rechunk_documents.php --chunk-vectors=pooled      # Use pooled vectors instead of re-embedding
 *   php cli/rechunk_documents.php --dry-run                   # Preview chunk count changes without writing DB
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\ChunkingConfig;
use McpServer\Auth\DocumentStore;
use McpServer\Auth\EmbeddingService;
use McpServer\Auth\SemanticChunker;

$options = getopt('', [
    'user::',
    'id::',
    'all',
    'chunking::',
    'max-units::',
    'chunk-vectors::',
    'from-source',
    'dry-run',
    'help',
]);

if (isset($options['help']) || (!isset($options['id']) && !isset($options['all']))) {
    echo <<<'HELP'
SimpleMCP Document Re-Chunker

Usage:
  php cli/rechunk_documents.php --all [options]
  php cli/rechunk_documents.php --id=<document_id> [options]

Options:
  --user=<username>         Target specific username (default: all users in memory.sqlite)
  --id=<document_id>        Re-chunk a specific document by its id
  --all                     Re-chunk all documents for the target user(s)
  --chunking=<strategy>     Strategy: 'auto' (default), 'semantic', or 'fixed'
  --max-units=<N>           Max units sent for breakpoint embedding (default: 0 = unlimited)
  --chunk-vectors=<method>  Chunk vector method: 'reembed' (default) or 'pooled'
  --from-source             Re-read original text from source file path when available
  --dry-run                 Simulate re-chunking and preview chunk counts without modifying DB
  --help                    Display this help message

HELP;
    exit(isset($options['help']) ? 0 : 1);
}

$targetUser = isset($options['user']) ? trim((string) $options['user']) : null;
$targetId = isset($options['id']) ? trim((string) $options['id']) : null;
$baseConfig = ChunkingConfig::load();
$strategy = isset($options['chunking']) ? strtolower(trim((string) $options['chunking'])) : $baseConfig->strategy;
if (!in_array($strategy, ['auto', 'semantic', 'fixed'], true)) {
    echo "Error: Invalid --chunking '$strategy'. Must be 'auto', 'semantic', or 'fixed'.\n";
    exit(1);
}

$maxUnits = isset($options['max-units']) ? max(0, (int) $options['max-units']) : $baseConfig->maxEmbeddedUnits;
$chunkVectors = isset($options['chunk-vectors']) ? strtolower(trim((string) $options['chunk-vectors'])) : $baseConfig->chunkVectors;
if (!in_array($chunkVectors, ['reembed', 'pooled'], true)) {
    echo "Error: Invalid --chunk-vectors '$chunkVectors'. Must be 'reembed' or 'pooled'.\n";
    exit(1);
}

$fromSource = isset($options['from-source']);
$dryRun = isset($options['dry-run']);

$dbPath = dirname(__DIR__) . '/data/memory.sqlite';
if (!file_exists($dbPath)) {
    echo "Error: Memory database not found at $dbPath\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$embeddingService = new EmbeddingService();
$documentStore = new DocumentStore($dbPath, $embeddingService);

echo "\n======================================================\n";
echo "  SimpleMCP Document Re-Chunker\n";
echo "======================================================\n";
echo "  Strategy:      " . ucfirst($strategy) . "\n";
echo "  Max Units:     " . ($maxUnits === 0 ? "Unlimited (0)" : $maxUnits) . "\n";
echo "  Chunk Vectors: " . ucfirst($chunkVectors) . "\n";
echo "  From Source:   " . ($fromSource ? "Yes (prefer disk source)" : "No (reconstruct)") . "\n";
echo "  Dry Run:       " . ($dryRun ? "Yes (preview only)" : "No") . "\n";
echo "======================================================\n\n";

if (!$dryRun && $embeddingService->isConfigured() && $strategy !== 'fixed') {
    echo "Connecting to embedding endpoint... ";
    $testStart = microtime(true);
    $probe = $embeddingService->embed(['SimpleMCP connectivity probe']);
    $probeDuration = microtime(true) - $testStart;
    if ($probe === [] || !isset($probe[0]) || !is_array($probe[0])) {
        echo "FAILED!\n";
        echo "Could not reach " . $embeddingService->getBaseUrl() . ". Proceeding with lexical breakpoints.\n\n";
    } else {
        echo "OK (" . round($probeDuration * 1000) . "ms, " . count($probe[0]) . " dimensions)\n\n";
    }
}

// Discover users
if ($targetUser !== null && $targetUser !== '') {
    $users = [$targetUser];
} else {
    $stmt = $pdo->query('SELECT DISTINCT username FROM memory_documents');
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($users === []) {
        $users = ['local'];
    }
}

function reconstructDocumentText(array $chunks): string {
    $count = count($chunks);
    if ($count === 0) {
        return '';
    }
    if ($count === 1) {
        return (string) $chunks[0]['content'];
    }

    $fullText = (string) $chunks[0]['content'];
    for ($i = 1; $i < $count; $i++) {
        $next = (string) $chunks[$i]['content'];
        // Detect overlap with end of accumulated text
        $matched = false;
        $maxCheck = min(mb_strlen($fullText), mb_strlen($next), 500);
        for ($len = $maxCheck; $len >= 20; $len--) {
            $suffix = mb_substr($fullText, -$len);
            $prefix = mb_substr($next, 0, $len);
            if ($suffix === $prefix) {
                $fullText .= mb_substr($next, $len);
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $fullText .= "\n\n" . $next;
        }
    }

    return trim($fullText);
}

$totalProcessed = 0;
$totalBeforeChunks = 0;
$totalAfterChunks = 0;
$startTime = microtime(true);

foreach ($users as $username) {
    $list = $documentStore->listDocuments($username);
    $docs = $list['documents'];

    if ($targetId !== null) {
        $docs = array_values(array_filter($docs, static fn(array $d): bool => $d['id'] === $targetId));
    }

    if ($docs === []) {
        continue;
    }

    echo "User: '$username' (" . count($docs) . " document(s))\n";

    foreach ($docs as $doc) {
        $docId = (string) $doc['id'];
        $docDetail = $documentStore->getDocument($username, $docId);
        if (isset($docDetail['error'])) {
            echo "  - Document '$docId': {$docDetail['error']}\n";
            continue;
        }

        $sourceContent = null;
        if ($fromSource && !empty($doc['source'])) {
            $srcPath = (string) $doc['source'];
            if (!is_file($srcPath)) {
                $rel = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
                if (is_file($rel)) {
                    $srcPath = $rel;
                }
            }
            if (is_file($srcPath) && is_readable($srcPath)) {
                $raw = @file_get_contents($srcPath);
                if (is_string($raw) && trim($raw) !== '') {
                    $sourceContent = $raw;
                }
            }
        }

        $content = $sourceContent ?? reconstructDocumentText($docDetail['chunks'] ?? []);
        if (trim($content) === '') {
            echo "  - Document '$docId': empty content, skipping.\n";
            continue;
        }

        $beforeCount = (int) ($docDetail['chunkCount'] ?? count($docDetail['chunks'] ?? []));

        if ($dryRun) {
            $chunkConfig = ChunkingConfig::load([
                'strategy' => $strategy,
                'max_embedded_units' => $maxUnits,
                'chunk_vectors' => $chunkVectors,
            ]);
            $dryEmbedding = new EmbeddingService(['model' => 'dry-run'], static fn(array $r): array => array_map(
                static fn(string $t): array => [1.0, 0.0, 0.0],
                $r['input']
            ));
            $chunker = new SemanticChunker($dryEmbedding);
            $sim = $chunker->chunk($content, (string) $doc['format'], $chunkConfig);
            $afterCount = count($sim['chunks']);

            echo sprintf(
                "  - [Dry-Run] '%s' (%s): %d -> %d chunks (strategy: %s)\n",
                $docId,
                $doc['filename'],
                $beforeCount,
                $afterCount,
                $sim['stats']['strategy']
            );

            $totalBeforeChunks += $beforeCount;
            $totalAfterChunks += $afterCount;
            $totalProcessed++;
        } else {
            $t0 = microtime(true);
            $result = $documentStore->ingestDocument($username, [
                'id' => $docId,
                'filename' => $doc['filename'],
                'title' => $doc['title'],
                'source' => $doc['source'],
                'format' => $doc['format'],
                'content' => $content,
                'chunking' => $strategy,
                'max_embedded_units' => $maxUnits,
                'chunk_vectors' => $chunkVectors,
            ]);

            $elapsed = round((microtime(true) - $t0) * 1000);
            if (isset($result['error'])) {
                echo "  - Document '$docId' failed: {$result['error']}\n";
                continue;
            }

            $afterCount = (int) $result['chunkCount'];
            $st = $result['stats'] ?? [];
            $unitsStr = !empty($st['unitsEmbedded']) ? ", {$st['unitsEmbedded']} units embedded" : '';
            $pooledStr = !empty($st['pooledVectors']) ? ", {$st['pooledVectors']} pooled" : '';

            echo sprintf(
                "  - Re-chunked '%s': %d -> %d chunks in %dms (%s%s%s)\n",
                $docId,
                $beforeCount,
                $afterCount,
                $elapsed,
                $result['chunking'],
                $unitsStr,
                $pooledStr
            );

            $totalBeforeChunks += $beforeCount;
            $totalAfterChunks += $afterCount;
            $totalProcessed++;
        }
    }
}

$elapsedTotal = round(microtime(true) - $startTime, 2);
echo "\n======================================================\n";
echo "  Finished: $totalProcessed document(s) processed in {$elapsedTotal}s\n";
echo "  Chunks:   $totalBeforeChunks -> $totalAfterChunks\n";
echo "======================================================\n\n";
