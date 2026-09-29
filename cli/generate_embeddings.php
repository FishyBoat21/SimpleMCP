<?php

declare(strict_types=1);

/**
 * CLI tool for generating and backfilling dense vector embeddings for
 * knowledge graph observations and document chunks.
 *
 * Usage:
 *   php cli/generate_embeddings.php                       # Generate missing embeddings for all users & stores
 *   php cli/generate_embeddings.php --type=memory         # Generate only memory observation embeddings
 *   php cli/generate_embeddings.php --type=documents      # Generate only document chunk embeddings
 *   php cli/generate_embeddings.php --user=local          # Target specific user
 *   php cli/generate_embeddings.php --force               # Re-generate all embeddings even if already cached
 *   php cli/generate_embeddings.php --batch-size=50       # Set request batch size
 *   php cli/generate_embeddings.php --dry-run             # Inspect work without calling API or writing DB
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\DocumentStore;
use McpServer\Auth\EmbeddingService;
use McpServer\Auth\MemoryStore;

$options = getopt('', [
    'user::',
    'type::',
    'batch-size::',
    'force',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
SimpleMCP Vector Embedding Generator

Usage:
  php cli/generate_embeddings.php [options]

Options:
  --user=<username>     Target specific username (default: all users found in memory.sqlite)
  --type=<type>         Target store: 'all' (default), 'memory', or 'documents'
  --batch-size=<N>      Number of texts per embedding request (default: 50, max: 100)
  --force               Re-generate embeddings even if already cached
  --dry-run             Count items needing embeddings without calling API or modifying DB
  --help                Display this help message

HELP;
    exit(0);
}

$targetUser = isset($options['user']) ? trim((string) $options['user']) : null;
$type = isset($options['type']) ? strtolower(trim((string) $options['type'])) : 'all';
if (!in_array($type, ['all', 'memory', 'documents'], true)) {
    echo "Error: Invalid --type '$type'. Must be 'all', 'memory', or 'documents'.\n";
    exit(1);
}

$batchSize = isset($options['batch-size']) ? max(1, min(100, (int) $options['batch-size'])) : 50;
$force = isset($options['force']);
$dryRun = isset($options['dry-run']);

$dbPath = dirname(__DIR__) . '/data/memory.sqlite';
if (!file_exists($dbPath)) {
    echo "Error: Memory database not found at $dbPath\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Initialize embedding service and stores
$embeddingService = new EmbeddingService();
$memoryStore = new MemoryStore($dbPath, $embeddingService);
$documentStore = new DocumentStore($dbPath, $embeddingService);

echo "\n======================================================\n";
echo "  SimpleMCP Vector Embedding Generator\n";
echo "======================================================\n";
echo "  Model:       " . $embeddingService->getModel() . "\n";
echo "  Endpoint:    " . $embeddingService->getBaseUrl() . "\n";
echo "  Configured:  " . ($embeddingService->isConfigured() ? "Yes" : "No") . "\n";
echo "  Target Store:" . ucfirst($type) . "\n";
echo "  Batch Size:  $batchSize\n";
echo "  Force Mode:  " . ($force ? "Yes (re-embed all)" : "No (missing only)") . "\n";
echo "  Dry Run:     " . ($dryRun ? "Yes (preview only)" : "No") . "\n";
echo "======================================================\n\n";

if (!$embeddingService->isConfigured()) {
    echo "Error: EmbeddingService is not configured.\n";
    echo "Check config/embedding.php or set OPENAI_API_KEY / OPENAI_BASE_URL.\n\n";
    exit(1);
}

// Quick health-check call to verify embedding endpoint
echo "Connecting to embedding endpoint... ";
$testStart = microtime(true);
$probe = $embeddingService->embed(['SimpleMCP connectivity probe']);
$probeDuration = microtime(true) - $testStart;

if ($probe === [] || !isset($probe[0]) || !is_array($probe[0])) {
    echo "FAILED!\n";
    echo "Could not generate probe embedding from " . $embeddingService->getBaseUrl() . "/embeddings\n";
    echo "Please verify that your embedding server is running and the model '" . $embeddingService->getModel() . "' is loaded.\n\n";
    exit(1);
}

$dimensions = count($probe[0]);
echo "OK (" . round($probeDuration * 1000) . "ms, $dimensions dimensions)\n\n";

// Discover users
if ($targetUser !== null && $targetUser !== '') {
    $users = [$targetUser];
} else {
    $stmt = $pdo->query('SELECT DISTINCT username FROM memory_entities UNION SELECT DISTINCT username FROM memory_documents');
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($users === []) {
        $users = ['local'];
    }
}

$startTime = microtime(true);
$totalObsEmbedded = 0;
$totalEntitiesProcessed = 0;
$totalChunksEmbedded = 0;
$totalChunksProcessed = 0;

function renderProgressBar(string $label, int $current, int $total, float $start): void {
    $width = 30;
    $pct = $total > 0 ? min(1.0, $current / $total) : 1.0;
    $filled = (int) round($width * $pct);
    $bar = str_repeat('=', max(0, $filled - 1)) . ($filled > 0 ? '>' : '') . str_repeat(' ', max(0, $width - $filled));
    $elapsed = round(microtime(true) - $start, 1);
    $rate = $elapsed > 0 ? round($current / $elapsed, 1) : 0;
    $percentStr = str_pad(number_format($pct * 100, 1) . '%', 6, ' ', STR_PAD_LEFT);
    $countStr = str_pad("$current/$total", 12, ' ', STR_PAD_LEFT);
    echo "\r  $label [$bar] $percentStr $countStr ({$rate} items/s, {$elapsed}s)";
    if ($current >= $total) {
        echo "\n";
    }
}

foreach ($users as $username) {
    echo "Processing user: '$username'\n";

    // 1. Memory Observation Embeddings
    if ($type === 'all' || $type === 'memory') {
        $candStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM memory_entities
             WHERE username = :username AND observations != '[]' AND observations != ''"
             . ($force ? '' : " AND (embedding_pointers = '[]' OR embedding_pointers IS NULL)")
        );
        $candStmt->execute([':username' => $username]);
        $candEntities = (int) $candStmt->fetchColumn();

        echo "  [Memory] Entities needing inspection: $candEntities\n";

        if ($dryRun) {
            echo "  [Memory] (Dry-run) Skipped actual embedding.\n";
        } elseif ($candEntities > 0) {
            $memStart = microtime(true);
            $memResult = $memoryStore->syncAllObservationEmbeddings(
                $username,
                $force,
                function (int $completed, int $total) use ($memStart) {
                    renderProgressBar('Embedding observations:', $completed, $total, $memStart);
                },
                $batchSize
            );
            $totalEntitiesProcessed += $memResult['entities_processed'];
            $totalObsEmbedded += $memResult['observations_embedded'];
            echo "  [Memory] Synced {$memResult['observations_embedded']} observation embeddings across {$memResult['entities_processed']} entities.\n";
        } else {
            echo "  [Memory] All observation embeddings are up to date.\n";
        }
    }

    // 2. Document Chunk Embeddings
    if ($type === 'all' || $type === 'documents') {
        $chunkStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM memory_chunks c
             LEFT JOIN memory_embeddings e ON c.username = e.username AND e.target_type = "chunk" AND e.target_id = c.id
             WHERE c.username = :username' . ($force ? '' : ' AND e.id IS NULL')
        );
        $chunkStmt->execute([':username' => $username]);
        $candChunks = (int) $chunkStmt->fetchColumn();

        echo "  [Documents] Chunks needing embeddings: $candChunks\n";

        if ($dryRun) {
            echo "  [Documents] (Dry-run) Skipped actual embedding.\n";
        } elseif ($candChunks > 0) {
            $docStart = microtime(true);
            $docResult = $documentStore->syncAllChunkEmbeddings(
                $username,
                $force,
                function (int $completed, int $total) use ($docStart) {
                    renderProgressBar('Embedding chunks:      ', $completed, $total, $docStart);
                },
                $batchSize
            );
            $totalChunksProcessed += $docResult['chunks_processed'];
            $totalChunksEmbedded += $docResult['chunks_embedded'];
            echo "  [Documents] Synced {$docResult['chunks_embedded']} chunk embeddings.\n";
        } else {
            echo "  [Documents] All document chunk embeddings are up to date.\n";
        }
    }
    echo "\n";
}

$elapsedTotal = round(microtime(true) - $startTime, 2);
$finalObsTotal = (int) $pdo->query("SELECT COUNT(*) FROM memory_embeddings WHERE target_type = 'observation'")->fetchColumn();
$finalChunkTotal = (int) $pdo->query("SELECT COUNT(*) FROM memory_embeddings WHERE target_type = 'chunk'")->fetchColumn();
$finalTotal = $finalObsTotal + $finalChunkTotal;

echo "======================================================\n";
echo "  Generation Summary\n";
echo "======================================================\n";
echo "  Observations embedded now:  $totalObsEmbedded\n";
echo "  Document chunks embedded:   $totalChunksEmbedded\n";
echo "  Total observation records:  $finalObsTotal\n";
echo "  Total chunk records:        $finalChunkTotal\n";
echo "  Total vector embeddings:    $finalTotal ($dimensions dims)\n";
echo "  Total time elapsed:         {$elapsedTotal}s\n";
echo "======================================================\n\n";

echo "Vector semantic search is now fully active with dense vector embeddings!\n";
