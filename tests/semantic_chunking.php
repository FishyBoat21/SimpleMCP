<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\ChunkingConfig;
use McpServer\Auth\DocumentStore;
use McpServer\Auth\EmbeddingService;
use McpServer\Auth\SemanticChunker;

function same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ": expected '$haystack' to contain '$needle'");
    }
}

$tempDb = tempnam(sys_get_temp_dir(), 'simplemcp-semantic-');
if ($tempDb === false) {
    throw new RuntimeException('Could not create temporary SQLite file');
}

try {
    echo "=== Running Semantic Chunking Test Suite ===\n";

    // 1. Mock embedding transport that outputs topic vectors
    $embedCallCount = 0;
    $fakeTransport = static function (array $req) use (&$embedCallCount): array {
        $embedCallCount += count($req['input']);
        return array_map(static function (string $text): array {
            $isCats = stripos($text, 'cat') !== false || stripos($text, 'feline') !== false;
            $isDogs = stripos($text, 'dog') !== false || stripos($text, 'canine') !== false;
            return [
                $isCats ? 1.0 : 0.0,
                $isDogs ? 1.0 : 0.0,
                0.1,
            ];
        }, $req['input']);
    };

    $embedding = new EmbeddingService(['model' => 'test-model', 'base_url' => 'http://localhost'], $fakeTransport);
    $store = new DocumentStore($tempDb, $embedding);
    $chunker = new SemanticChunker($embedding);

    // TEST 1: Regression check - fixed chunking matches DocumentStore::chunk
    echo "Test 1: Fixed chunking backwards compatibility... ";
    $sampleText = str_repeat("Sentence number one. Sentence number two.\n\n", 20);
    $fixedCfg = new ChunkingConfig(strategy: 'fixed', chunkSize: 300, chunkOverlap: 50);
    $fixedResult = $chunker->chunk($sampleText, 'text', $fixedCfg);
    $legacyResult = DocumentStore::chunk($sampleText, 300, 50);
    same(count($legacyResult), count($fixedResult['chunks']), 'Chunk counts must match between legacy and fixed');
    for ($i = 0; $i < count($legacyResult); $i++) {
        same($legacyResult[$i], $fixedResult['chunks'][$i]['content'], "Chunk #$i content must match exactly");
    }
    echo "PASSED\n";

    // TEST 2: Markdown structural segmentation & heading paths
    echo "Test 2: Markdown heading hierarchy & code block protection... ";
    $md = <<<'MD'
# Guide
Introductory text about the system.

## Setup
Installation steps for all platforms.

```bash
# This is a bash comment, not a markdown heading
npm install
```

### Windows
Windows specific instructions and registry tweaks.
MD;

    $mdSections = $chunker->structuralSections($md, 'markdown');
    same(3, count($mdSections), 'Should have 3 distinct structural sections');
    same('Guide', $mdSections[0]['heading_path'], 'Section 0 heading path');
    same('Guide > Setup', $mdSections[1]['heading_path'], 'Section 1 heading path');
    assertContains('# This is a bash comment', $mdSections[1]['content'], 'Code block comment retained inside setup');
    same('Guide > Setup > Windows', $mdSections[2]['heading_path'], 'Section 2 heading path');
    echo "PASSED\n";

    // TEST 3: Breakpoint detection splitting two distinct topics
    echo "Test 3: Breakpoint detection on distinct topics... ";
    $catsAndDogs = <<<'TEXT'
Cats are small carnivorous mammals with soft fur, short muzzles, and retractile claws. Felines have been domesticated for thousands of years as companions and mouse catchers. Domestic cats communicate through meowing, purring, and hissing sounds.
Dogs are domesticated canines known for loyalty and trainability across various breeds. Canine companions require regular exercise and social interaction with humans. Dogs have an exceptional sense of smell and hearing compared to people.
TEXT;

    $semCfg = new ChunkingConfig(
        strategy: 'semantic',
        chunkSize: 200,
        minChunkSize: 80,
        breakpointPercentile: 50,
        overlapUnits: 0
    );
    $semResult = $chunker->chunk($catsAndDogs, 'text', $semCfg);
    same(true, count($semResult['chunks']) >= 2, 'Should split into multiple chunks');
    assertContains('Cats', $semResult['chunks'][0]['content'], 'First chunk covers felines');
    assertContains('Dogs', $semResult['chunks'][count($semResult['chunks']) - 1]['content'], 'Later chunk covers canines');
    echo "PASSED\n";

    // TEST 4: Lexical fallback when embedding fails
    echo "Test 4: Lexical fallback when embedding fails... ";
    $failingTransport = static function (array $req): array {
        return []; // Simulate HTTP failure
    };
    $failingEmbedding = new EmbeddingService(['model' => 'fail', 'base_url' => 'http://localhost'], $failingTransport);
    $failingChunker = new SemanticChunker($failingEmbedding);

    $fallbackResult = $failingChunker->chunk($catsAndDogs, 'text', $semCfg);
    same(true, count($fallbackResult['chunks']) >= 1, 'Should produce chunks even on embedding failure');
    same(true, $fallbackResult['stats']['fallbackSections'] > 0 || $fallbackResult['stats']['strategy'] === 'semantic-lexical', 'Stats report fallback');
    echo "PASSED\n";

    // TEST 5: Large document budget (max_embedded_units)
    echo "Test 5: Budget enforcement via max_embedded_units... ";
    $budgetCfg = new ChunkingConfig(
        strategy: 'semantic',
        chunkSize: 150,
        minChunkSize: 50,
        maxEmbeddedUnits: 1 // Only 1 unit budget allowed
    );
    $budgetResult = $chunker->chunk($catsAndDogs, 'text', $budgetCfg);
    same(true, $budgetResult['stats']['fallbackSections'] > 0, 'Sections beyond budget fell back to lexical');
    echo "PASSED\n";

    // TEST 6: Ingestion in DocumentStore, metadata storage, and retrieval
    echo "Test 6: Ingestion, metadata persistence, and retrieval... ";
    $ingestResult = $store->ingestDocument('alice', [
        'id' => 'animal-guide',
        'filename' => 'guide.md',
        'title' => 'Animal Guide',
        'content' => $md,
        'chunking' => 'auto',
        'min_chunk_size' => 40,
    ]);

    same(false, isset($ingestResult['error']), 'Ingest should succeed');
    same(3, $ingestResult['chunkCount'], 'Should have 3 chunks matching markdown sections');

    $docDetail = $store->getDocument('alice', 'animal-guide');
    same('Animal Guide', $docDetail['title'], 'Doc title stored');
    same('semantic', $docDetail['chunking'], 'chunking field returned by getDocument');
    same('Guide > Setup > Windows', $docDetail['chunks'][2]['section'], 'Section heading path attached to chunk #2');
    same(true, isset($docDetail['chunks'][2]['charStart']), 'charStart offset stored');

    // Retrieval
    $ret = $store->retrieve('alice', 'registry tweaks', 'keyword');
    same(true, $ret['total'] > 0, 'Should find Windows chunk by keyword');
    same('Guide > Setup > Windows', $ret['results'][0]['section'], 'Section path surfaced in retrieval');
    echo "PASSED\n";

    // TEST 7: Vector reuse on re-ingestion
    echo "Test 7: Vector reuse on unchanged chunks... ";
    $callsBefore = $embedCallCount;
    $reingest = $store->ingestDocument('alice', [
        'id' => 'animal-guide',
        'filename' => 'guide.md',
        'title' => 'Animal Guide',
        'content' => $md,
        'chunking' => 'auto',
        'min_chunk_size' => 40,
        'reuse_vectors' => true,
    ]);
    same(true, $reingest['replaced'], 'Marked as replaced');
    same($callsBefore, $embedCallCount, 'Embedding transport should NOT be called again due to content_hash reuse');
    echo "PASSED\n";

    // TEST 8: Pooled vector generation
    echo "Test 8: Pooled chunk vector generation... ";
    $pooledCfg = new ChunkingConfig(
        strategy: 'semantic',
        chunkSize: 180,
        minChunkSize: 60,
        chunkVectors: 'pooled'
    );
    $pooledRes = $chunker->chunk($catsAndDogs, 'text', $pooledCfg);
    same(true, $pooledRes['stats']['pooledVectors'] > 0, 'Pooled vectors generated');
    same(3, count($pooledRes['chunks'][0]['vector']), 'Pooled vector dimensions match model');
    echo "PASSED\n";

    // TEST 9: Undersized structural section merging (Defect 5)
    echo "Test 9: Undersized structural section merging... ";
    $tiny = '';
    for ($i = 1; $i <= 12; $i++) {
        $tiny .= "## Item $i\nShort note $i.\n\n";
    }
    $tinyCfg = new ChunkingConfig(strategy: 'semantic', chunkSize: 1000, minChunkSize: 250);
    $tinyRes = $chunker->chunk($tiny, 'markdown', $tinyCfg);
    same(1, count($tinyRes['chunks']), '12 tiny sections should merge into 1 chunk when under min_chunk_size');
    echo "PASSED\n";

    // TEST 10: Code fence preservation in oversize section (Defects 1 & 2)
    echo "Test 10: Code fence preservation in oversize section... ";
    $codeMd = "## Usage\nRun the installer first. Then configure the service. After that start it up and verify the logs.\n```sh\n# Install the package. Then restart.\n- not a list item\n1. not numbered either\necho \"Done. All good.\"\n```\nAfterwards check the dashboard. It should list every worker. Report issues in the tracker.";
    $codeCfg = new ChunkingConfig(strategy: 'semantic', chunkSize: 110, minChunkSize: 40);
    $codeRes = $chunker->chunk($codeMd, 'markdown', $codeCfg);
    $foundFence = false;
    foreach ($codeRes['chunks'] as $c) {
        if (str_contains($c['content'], "```sh\n# Install the package.") && str_contains($c['content'], "echo \"Done. All good.\"\n```")) {
            $foundFence = true;
        }
    }
    same(true, $foundFence, 'Code fence should remain atomic with newlines and indentation preserved');
    echo "PASSED\n";

    // TEST 11: Table preservation in oversize section (Defects 1 & 2)
    echo "Test 11: Markdown table preservation in oversize section... ";
    $tblMd = "## Pricing\nHere is the detailed overview of our service tiers. Please review before choosing.\n\n| Tier | Cost | Queries |\n| :--- | :--- | :--- |\n| Free | \$0   | 100     |\n| Pro  | \$20  | 10000   |\n\nMake sure your billing card is attached to your account.";
    $tblCfg = new ChunkingConfig(strategy: 'semantic', chunkSize: 110, minChunkSize: 40);
    $tblRes = $chunker->chunk($tblMd, 'markdown', $tblCfg);
    $foundTable = false;
    foreach ($tblRes['chunks'] as $c) {
        if (str_contains($c['content'], "| Tier | Cost | Queries |\n| :--- | :--- | :--- |\n| Free | \$0   | 100     |\n| Pro  | \$20  | 10000   |")) {
            $foundTable = true;
        }
    }
    same(true, $foundTable, 'Markdown table rows and pipes should remain intact');
    echo "PASSED\n";

    // TEST 12: Circuit breaker on failing embedding endpoint (Defect 6)
    echo "Test 12: Circuit breaker on failing embedding endpoint... ";
    $brokenCalls = 0;
    $brokenTransport = static function (array $req) use (&$brokenCalls): array {
        $brokenCalls++;
        return [];
    };
    $brokenEmbedding = new EmbeddingService(['model' => 'fail', 'base_url' => 'http://localhost'], $brokenTransport);
    $circuitChunker = new SemanticChunker($brokenEmbedding);
    $multiOversize = '';
    for ($i = 1; $i <= 5; $i++) {
        $multiOversize .= "## Section $i\n" . str_repeat("Sentence number $i for testing oversize breakpoint splitting. ", 10) . "\n\n";
    }
    $circuitRes = $circuitChunker->chunk($multiOversize, 'markdown', new ChunkingConfig(strategy: 'semantic', chunkSize: 200, minChunkSize: 50));
    same(1, $brokenCalls, 'Embedding endpoint should only be called once before circuit breaker trips');
    same(true, $circuitChunker->isCircuitBroken(), 'Circuit breaker should be active');
    same(5, $circuitRes['stats']['fallbackSections'], 'All 5 sections should fall back to lexical');
    echo "PASSED\n";

    // TEST 13: Pooled vector profile tagging (Defect 7)
    echo "Test 13: Pooled vector profile tagging with :pooled... ";
    $store->ingestDocument('bob', [
        'id' => 'pooled-doc',
        'filename' => 'doc.txt',
        'content' => $catsAndDogs,
        'chunking' => 'semantic',
        'chunk_size' => 200,
        'min_chunk_size' => 80,
        'chunk_vectors' => 'pooled',
    ]);
    $rawPdo = new PDO('sqlite:' . $tempDb);
    $stmt = $rawPdo->prepare('SELECT profile FROM memory_embeddings WHERE username = :u AND document_id = :d');
    $stmt->execute([':u' => 'bob', ':d' => 'pooled-doc']);
    $profiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    same(true, count($profiles) > 0, 'Embeddings saved for pooled doc');
    $hasPooled = false;
    $hasDirect = false;
    foreach ($profiles as $prof) {
        if (str_ends_with((string) $prof, ':pooled')) {
            $hasPooled = true;
        } else {
            $hasDirect = true;
        }
    }
    same(true, $hasPooled, 'Pooled chunks should be tagged with :pooled profile');
    same(true, $hasDirect, 'Oversized sub-chunks without pooled vectors should have direct profile');
    echo "PASSED\n";

    echo "\nAll 13 semantic chunking tests PASSED successfully!\n";
} finally {
    if (file_exists($tempDb)) {
        @unlink($tempDb);
    }
}
