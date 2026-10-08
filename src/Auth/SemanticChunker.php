<?php

declare(strict_types=1);

namespace McpServer\Auth;

use App;

/**
 * Hierarchical semantic chunker for large documents.
 *
 * Implements a multi-stage chunking pipeline:
 * 1. Structural segmentation (Markdown headings, code blocks, tables; HTML headings; text paragraphs).
 * 2. Sentence unit segmentation for sections exceeding target chunk_size.
 * 3. Breakpoint detection via sliding-window embedding distance or TF-IDF lexical cosine fallback.
 * 4. Size enforcement (merging small segments, recursively splitting oversize segments).
 * 5. Heading context attribution, unit-based overlap, and optional vector pooling.
 */
final class SemanticChunker {
    private EmbeddingService $embeddingService;
    private bool $embeddingCircuitBroken = false;

    public function __construct(?EmbeddingService $embeddingService = null) {
        $this->embeddingService = $embeddingService ?? (class_exists(App::class) ? App::embeddingService() : new EmbeddingService());
    }

    public function isCircuitBroken(): bool {
        return $this->embeddingCircuitBroken;
    }

    public function resetCircuitBreaker(): void {
        $this->embeddingCircuitBroken = false;
    }

    /**
     * Chunk document text according to configuration.
     *
     * @return array{
     *     chunks: array<int, array{
     *         content: string,
     *         heading_path: string,
     *         char_start: ?int,
     *         char_end: ?int,
     *         vector: ?array<int, float>
     *     }>,
     *     stats: array{
     *         strategy: string,
     *         unitsEmbedded: int,
     *         fallbackSections: int,
     *         pooledVectors: int,
     *         totalUnits: int
     *     }
     * }
     */
    public function chunk(string $text, string $format, ChunkingConfig $cfg): array {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $effectiveStrategy = $cfg->effectiveStrategy($format, $this->embeddingService->isConfigured());

        if ($effectiveStrategy === 'fixed') {
            return $this->chunkFixed($text, $cfg);
        }

        $stats = [
            'strategy' => $effectiveStrategy,
            'unitsEmbedded' => 0,
            'fallbackSections' => 0,
            'pooledVectors' => 0,
            'totalUnits' => 0,
        ];

        $rawSections = $this->structuralSections($text, $format);
        $sections = $this->mergeStructuralSections($rawSections, $text, $cfg->minChunkSize, $cfg->chunkSize);
        $finalChunks = [];
        $budget = $cfg->maxEmbeddedUnits; // 0 = unlimited

        // Process sections in document order
        foreach ($sections as $section) {
            $content = $section['content'];
            $headingPath = $section['heading_path'];
            $startOffset = $section['char_start'];

            if (mb_strlen($content) <= $cfg->chunkSize) {
                // Section fits in a single chunk
                $finalChunks[] = [
                    'content' => $content,
                    'heading_path' => $headingPath,
                    'char_start' => $startOffset,
                    'char_end' => $startOffset + mb_strlen($content),
                    'vector' => null,
                ];
                continue;
            }

            // Section is oversized: split into sentence units protecting code blocks and tables
            $units = $this->splitUnits($content, $cfg->minUnitChars, $startOffset);
            $stats['totalUnits'] += count($units);

            if (count($units) <= 1) {
                // Cannot split by units, fallback to fixed chunking for this section
                $subChunks = DocumentStore::chunk($content, $cfg->chunkSize, $cfg->chunkOverlap);
                foreach ($subChunks as $sub) {
                    $finalChunks[] = [
                        'content' => $sub,
                        'heading_path' => $headingPath,
                        'char_start' => null,
                        'char_end' => null,
                        'vector' => null,
                    ];
                }
                continue;
            }

            // Decide whether to use embedding breakpoints or lexical breakpoints
            $canEmbed = !$this->embeddingCircuitBroken
                && ($effectiveStrategy === 'semantic')
                && ($budget === 0 || ($stats['unitsEmbedded'] + count($units) <= $budget));

            $unitVectors = null;
            $breakpoints = [];

            if ($canEmbed) {
                $embedResult = $this->breakpointsByEmbedding($units, $cfg);
                if ($embedResult !== null) {
                    $breakpoints = $embedResult['breakpoints'];
                    $unitVectors = $embedResult['unitVectors'];
                    $stats['unitsEmbedded'] += count($units);
                } else {
                    // Embedding call failed: circuit breaker tripped, fallback to lexical
                    $stats['fallbackSections']++;
                    $breakpoints = $this->breakpointsLexical($units, $cfg);
                }
            } else {
                if ($effectiveStrategy === 'semantic') {
                    $stats['fallbackSections']++;
                }
                $breakpoints = $this->breakpointsLexical($units, $cfg);
            }

            // Assemble units into segments according to breakpoints
            $segments = $this->assembleSegments($units, $breakpoints, $cfg, $unitVectors, $content, $startOffset);

            foreach ($segments as $seg) {
                $vector = null;
                if ($cfg->chunkVectors === 'pooled' && isset($seg['unit_vectors']) && !empty($seg['unit_vectors'])) {
                    $vector = $this->poolVectors($seg['unit_vectors']);
                    if ($vector !== null) {
                        $stats['pooledVectors']++;
                    }
                }

                $finalChunks[] = [
                    'content' => $seg['content'],
                    'heading_path' => $headingPath,
                    'char_start' => $seg['char_start'],
                    'char_end' => $seg['char_end'],
                    'vector' => $vector,
                ];
            }
        }

        return [
            'chunks' => $finalChunks,
            'stats' => $stats,
        ];
    }

    /**
     * Legacy fixed character / paragraph chunking.
     *
     * @return array{
     *     chunks: array<int, array{
     *         content: string,
     *         heading_path: string,
     *         char_start: ?int,
     *         char_end: ?int,
     *         vector: null
     *     }>,
     *     stats: array<string, mixed>
     * }
     */
    private function chunkFixed(string $text, ChunkingConfig $cfg): array {
        $rawChunks = DocumentStore::chunk($text, $cfg->chunkSize, $cfg->chunkOverlap);
        $chunks = [];

        foreach ($rawChunks as $content) {
            $chunks[] = [
                'content' => $content,
                'heading_path' => '',
                'char_start' => null,
                'char_end' => null,
                'vector' => null,
            ];
        }

        return [
            'chunks' => $chunks,
            'stats' => [
                'strategy' => 'fixed',
                'unitsEmbedded' => 0,
                'fallbackSections' => 0,
                'pooledVectors' => 0,
                'totalUnits' => 0,
            ],
        ];
    }

    /**
     * Merge adjacent undersized structural sections up to chunkSize.
     *
     * @param array<int, array{content: string, heading_path: string, char_start: int, char_end: int}> $sections
     * @return array<int, array{content: string, heading_path: string, char_start: int, char_end: int}>
     */
    public function mergeStructuralSections(array $sections, string $text, int $minChunkSize, int $chunkSize): array {
        if (count($sections) <= 1) {
            return $sections;
        }

        $merged = [];
        $current = null;

        foreach ($sections as $sec) {
            if ($current === null) {
                $current = $sec;
                continue;
            }

            $currLen = mb_strlen($current['content']);
            $secLen = mb_strlen($sec['content']);
            $combinedLen = $currLen + 1 + $secLen;

            $shouldMerge = ($currLen < $minChunkSize || $secLen < $minChunkSize) && ($combinedLen <= $chunkSize);

            if ($shouldMerge) {
                $start = $current['char_start'];
                $end = $sec['char_end'];
                $content = trim(mb_substr($text, $start, $end - $start));
                $current = [
                    'content' => $content,
                    'heading_path' => $current['heading_path'] !== '' ? $current['heading_path'] : $sec['heading_path'],
                    'char_start' => $start,
                    'char_end' => $end,
                ];
            } else {
                $merged[] = $current;
                $current = $sec;
            }
        }

        if ($current !== null) {
            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * Stage 1: Structural segmentation.
     *
     * @return array<int, array{content: string, heading_path: string, char_start: int, char_end: int}>
     */
    public function structuralSections(string $text, string $format): array {
        $format = strtolower(trim($format));

        return match ($format) {
            'markdown' => $this->segmentMarkdown($text),
            'html' => $this->segmentHtml($text),
            default => $this->segmentText($text),
        };
    }

    /**
     * Segment Markdown text by ATX headings while protecting fenced code blocks and tables.
     *
     * @return array<int, array{content: string, heading_path: string, char_start: int, char_end: int}>
     */
    private function segmentMarkdown(string $text): array {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false || $lines === []) {
            return [];
        }

        $sections = [];
        $headingStack = []; // level => heading_text
        $currentLines = [];
        $inCodeBlock = false;
        $fenceMarker = '';
        $charOffset = 0;
        $sectionStart = 0;

        foreach ($lines as $lineIndex => $line) {
            $trimmed = trim($line);

            // Check for fenced code block toggle
            if (preg_match('/^(```+|~~~+)/', $trimmed, $fenceMatches)) {
                $marker = substr($fenceMatches[1], 0, 3);
                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $fenceMarker = $marker;
                } elseif ($marker === $fenceMarker) {
                    $inCodeBlock = false;
                    $fenceMarker = '';
                }
            }

            // ATX heading check (only outside code blocks)
            if (!$inCodeBlock && preg_match('/^(#{1,6})\s+(.+)$/', $line, $hMatches)) {
                $level = strlen($hMatches[1]);
                $title = trim($hMatches[2]);

                // Flush preceding lines if any
                $content = trim(implode("\n", $currentLines));
                if ($content !== '') {
                    $sections[] = [
                        'content' => $content,
                        'heading_path' => implode(' > ', array_values($headingStack)),
                        'char_start' => $sectionStart,
                        'char_end' => $charOffset,
                    ];
                }

                // Update heading stack: prune headings with level >= current level
                $newStack = [];
                foreach ($headingStack as $lvl => $hTitle) {
                    if ($lvl < $level) {
                        $newStack[$lvl] = $hTitle;
                    }
                }
                $newStack[$level] = $title;
                $headingStack = $newStack;

                $currentLines = [$line];
                $sectionStart = $charOffset;
            } else {
                $currentLines[] = $line;
            }

            $charOffset += mb_strlen($line) + 1; // +1 for newline
        }

        $content = trim(implode("\n", $currentLines));
        if ($content !== '') {
            $sections[] = [
                'content' => $content,
                'heading_path' => implode(' > ', array_values($headingStack)),
                'char_start' => $sectionStart,
                'char_end' => mb_strlen($text),
            ];
        }

        return $sections !== [] ? $sections : [[
            'content' => trim($text),
            'heading_path' => '',
            'char_start' => 0,
            'char_end' => mb_strlen($text),
        ]];
    }

    /**
     * Segment HTML text by heading tags <h1>..<h6>.
     *
     * @return array<int, array{content: string, heading_path: string, char_start: int, char_end: int}>
     */
    private function segmentHtml(string $text): array {
        // Find all heading tags
        if (!preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $this->segmentText($text);
        }

        $sections = [];
        $headingStack = [];
        $lastOffset = 0;
        $totalMatches = count($matches[0]);

        for ($i = 0; $i < $totalMatches; $i++) {
            $tagOffset = $matches[0][$i][1];
            $level = (int) $matches[1][$i][0];
            $title = trim(strip_tags($matches[2][$i][0]));

            if ($tagOffset > $lastOffset) {
                $chunkText = trim(substr($text, $lastOffset, $tagOffset - $lastOffset));
                if ($chunkText !== '') {
                    $sections[] = [
                        'content' => $chunkText,
                        'heading_path' => implode(' > ', array_values($headingStack)),
                        'char_start' => $lastOffset,
                        'char_end' => $tagOffset,
                    ];
                }
            }

            // Update heading stack
            $newStack = [];
            foreach ($headingStack as $lvl => $hTitle) {
                if ($lvl < $level) {
                    $newStack[$lvl] = $hTitle;
                }
            }
            $newStack[$level] = $title;
            $headingStack = $newStack;

            $lastOffset = $tagOffset;
        }

        if ($lastOffset < strlen($text)) {
            $chunkText = trim(substr($text, $lastOffset));
            if ($chunkText !== '') {
                $sections[] = [
                    'content' => $chunkText,
                    'heading_path' => implode(' > ', array_values($headingStack)),
                    'char_start' => $lastOffset,
                    'char_end' => strlen($text),
                ];
            }
        }

        return $sections !== [] ? $sections : $this->segmentText($text);
    }

    /**
     * Segment plain text by double newlines (paragraphs).
     *
     * @return array<int, array{content: string, heading_path: string, char_start: int, char_end: int}>
     */
    private function segmentText(string $text): array {
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if ($paragraphs === false || $paragraphs === []) {
            return [[
                'content' => trim($text),
                'heading_path' => '',
                'char_start' => 0,
                'char_end' => mb_strlen($text),
            ]];
        }

        $sections = [];
        foreach ($paragraphs as $para) {
            $content = trim($para[0]);
            if ($content === '') {
                continue;
            }
            $start = $para[1];
            $sections[] = [
                'content' => $content,
                'heading_path' => '',
                'char_start' => $start,
                'char_end' => $start + mb_strlen($content),
            ];
        }

        return $sections !== [] ? $sections : [[
            'content' => trim($text),
            'heading_path' => '',
            'char_start' => 0,
            'char_end' => mb_strlen($text),
        ]];
    }

    /**
     * Stage 2: Split text into sentence units, treating code fences and tables as atomic units.
     *
     * @return array<int, array{text: string, char_start: int, char_end: int, is_atomic?: bool}>
     */
    public function splitUnits(string $text, int $minUnitChars, int $baseOffset = 0): array {
        $pattern = '/(```[^\n]*\n.*?\n```|~~~[^\n]*\n.*?\n~~~|(?:^[ \t]*\|[^\n]+\|[ \t]*\n[ \t]*\|(?:[ \t]*:?-+:?[ \t]*\|)+[ \t]*(?:\n[ \t]*\|[^\n]+\|[ \t]*)*))/ms';
        $units = [];
        $lastByte = 0;
        $textLen = strlen($text);

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $mText = $match[0];
                $mByteOffset = $match[1];

                if ($mByteOffset > $lastByte) {
                    $prose = substr($text, $lastByte, $mByteOffset - $lastByte);
                    $proseCharOffset = $baseOffset + mb_strlen(substr($text, 0, $lastByte));
                    $units = array_merge($units, $this->splitProseUnits($prose, $minUnitChars, $proseCharOffset));
                }

                $unitCharOffset = $baseOffset + mb_strlen(substr($text, 0, $mByteOffset));
                $units[] = [
                    'text' => trim($mText),
                    'char_start' => $unitCharOffset,
                    'char_end' => $unitCharOffset + mb_strlen($mText),
                    'is_atomic' => true,
                ];
                $lastByte = $mByteOffset + strlen($mText);
            }
        }

        if ($lastByte < $textLen) {
            $prose = substr($text, $lastByte);
            $proseCharOffset = $baseOffset + mb_strlen(substr($text, 0, $lastByte));
            $units = array_merge($units, $this->splitProseUnits($prose, $minUnitChars, $proseCharOffset));
        }

        return $units;
    }

    /**
     * Split prose text by sentence boundaries, merging small fragments.
     *
     * @return array<int, array{text: string, char_start: int, char_end: int, is_atomic: bool}>
     */
    private function splitProseUnits(string $text, int $minUnitChars, int $baseCharOffset): array {
        $rawUnits = preg_split(
            '/(?<=[.?!])(?=\s+[A-Z0-9\p{Lu}])|(?<=[。！？])|(?<=\n)(?=\s*[-*+0-9#])/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE
        );

        if ($rawUnits === false || $rawUnits === []) {
            $t = trim($text);
            if ($t === '') {
                return [];
            }
            $leadSpace = mb_strlen($text) - mb_strlen(ltrim($text));
            $start = $baseCharOffset + $leadSpace;
            return [['text' => $t, 'char_start' => $start, 'char_end' => $start + mb_strlen($t), 'is_atomic' => false]];
        }

        $units = [];
        $buffer = '';
        $bufStart = null;
        $bufEnd = null;

        foreach ($rawUnits as $item) {
            $rawSeg = $item[0];
            $byteOffset = $item[1];
            $segment = trim($rawSeg);

            if ($segment === '') {
                continue;
            }

            $segCharOffset = $baseCharOffset + mb_strlen(substr($text, 0, $byteOffset));
            $leadSpace = mb_strlen($rawSeg) - mb_strlen(ltrim($rawSeg));
            $charStart = $segCharOffset + $leadSpace;
            $charEnd = $charStart + mb_strlen($segment);

            if ($bufStart === null) {
                $bufStart = $charStart;
            }
            $bufEnd = $charEnd;

            $candidate = $buffer === '' ? $segment : $buffer . ' ' . $segment;

            if (mb_strlen($candidate) >= $minUnitChars) {
                $units[] = [
                    'text' => $candidate,
                    'char_start' => $bufStart,
                    'char_end' => $bufEnd,
                    'is_atomic' => false,
                ];
                $buffer = '';
                $bufStart = null;
                $bufEnd = null;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            if ($units !== []) {
                $lastIdx = count($units) - 1;
                $units[$lastIdx]['text'] .= ' ' . $buffer;
                $units[$lastIdx]['char_end'] = $bufEnd ?? $units[$lastIdx]['char_end'];
            } else {
                $units[] = [
                    'text' => $buffer,
                    'char_start' => $bufStart ?? $baseCharOffset,
                    'char_end' => $bufEnd ?? ($baseCharOffset + mb_strlen($buffer)),
                    'is_atomic' => false,
                ];
            }
        }

        return $units;
    }

    /**
     * Stage 3: Embedding-based breakpoint detection in batches of 100 with circuit breaker.
     *
     * @param array<int, array{text: string, char_start: int, char_end: int}> $units
     * @return array{breakpoints: array<int, bool>, unitVectors: array<int, array<int, float>>}|null
     */
    private function breakpointsByEmbedding(array $units, ChunkingConfig $cfg): ?array {
        $count = count($units);
        if ($count <= 1) {
            return ['breakpoints' => [], 'unitVectors' => []];
        }

        $windows = $this->buildWindows($units, $cfg->windowUnits);
        $batchSize = 100;
        $distances = [];
        $unitVectors = [];
        $prevVector = null;
        $prevIndex = null;

        for ($offset = 0; $offset < $count; $offset += $batchSize) {
            $batch = array_slice($windows, $offset, $batchSize);
            try {
                $batchVectors = $this->embeddingService->embed($batch);
            } catch (\Throwable $e) {
                $this->embeddingCircuitBroken = true;
                return null;
            }

            if (!is_array($batchVectors) || count($batchVectors) !== count($batch)) {
                $this->embeddingCircuitBroken = true;
                return null;
            }

            $batchCount = count($batchVectors);
            for ($b = 0; $b < $batchCount; $b++) {
                $globalIdx = $offset + $b;
                $vec = $batchVectors[$b];
                $unitVectors[$globalIdx] = $vec;

                if ($prevVector !== null && $prevIndex !== null) {
                    $sim = EmbeddingService::cosineSimilarity($prevVector, $vec);
                    $distances[$prevIndex] = 1.0 - $sim;
                }
                $prevVector = $vec;
                $prevIndex = $globalIdx;
            }
        }

        $threshold = $this->percentile($distances, $cfg->breakpointPercentile);
        $breakpoints = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $breakpoints[$i] = ($distances[$i] ?? 0.0) >= $threshold;
        }

        return [
            'breakpoints' => $breakpoints,
            'unitVectors' => $unitVectors,
        ];
    }

    /**
     * Stage 3 Fallback: Lexical TF-IDF cosine breakpoint detection.
     *
     * @param array<int, array{text: string, char_start: int, char_end: int}> $units
     * @return array<int, bool>
     */
    private function breakpointsLexical(array $units, ChunkingConfig $cfg): array {
        $count = count($units);
        if ($count <= 1) {
            return [];
        }

        $windows = $this->buildWindows($units, $cfg->windowUnits);
        $distances = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $distances[$i] = 1.0 - $this->lexicalCosine($windows[$i], $windows[$i + 1]);
        }

        $threshold = $this->percentile($distances, $cfg->breakpointPercentile);
        $breakpoints = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $breakpoints[$i] = $distances[$i] >= $threshold;
        }

        return $breakpoints;
    }

    /**
     * Stage 4: Assemble units into size-enforced segments.
     *
     * @param array<int, array{text: string, char_start: int, char_end: int}> $units
     * @param array<int, bool> $breakpoints
     * @param array<int, array<int, float>>|null $unitVectors
     * @return array<int, array{content: string, char_start: int, char_end: int, unit_vectors?: ?array<int, array<int, float>>}>
     */
    private function assembleSegments(
        array $units,
        array $breakpoints,
        ChunkingConfig $cfg,
        ?array $unitVectors = null,
        ?string $sectionContent = null,
        int $sectionBaseOffset = 0,
    ): array {
        $count = count($units);
        if ($count === 0) {
            return [];
        }

        // Group units into raw segments based on breakpoints
        $rawSegments = [];
        $currentUnits = [];
        $currentVectors = [];

        for ($i = 0; $i < $count; $i++) {
            $currentUnits[] = $units[$i];
            if ($unitVectors !== null && isset($unitVectors[$i])) {
                $currentVectors[] = $unitVectors[$i];
            }

            $isCut = ($i === $count - 1) || (!empty($breakpoints[$i]));
            if ($isCut) {
                $rawSegments[] = [
                    'units' => $currentUnits,
                    'vectors' => $currentVectors,
                ];
                $currentUnits = [];
                $currentVectors = [];
            }
        }

        // Size enforcement: merge segments shorter than min_chunk_size
        $merged = [];
        foreach ($rawSegments as $seg) {
            $segText = $this->joinUnits($seg['units']);
            if ($merged !== [] && mb_strlen($segText) < $cfg->minChunkSize) {
                // Merge with previous segment
                $lastIdx = count($merged) - 1;
                $merged[$lastIdx]['units'] = array_merge($merged[$lastIdx]['units'], $seg['units']);
                $merged[$lastIdx]['vectors'] = array_merge($merged[$lastIdx]['vectors'], $seg['vectors']);
            } else {
                $merged[] = $seg;
            }
        }

        // Stage 5: Apply unit overlap and split oversized segments
        $finalSegments = [];
        $prevUnits = [];

        foreach ($merged as $seg) {
            $uList = $seg['units'];
            $vList = $seg['vectors'];

            // Prepend overlap from previous segment if configured
            if ($cfg->overlapUnits > 0 && $prevUnits !== []) {
                $overlapSlice = array_slice($prevUnits, -$cfg->overlapUnits);
                $uList = array_merge($overlapSlice, $uList);
            }

            $prevUnits = $seg['units'];
            $startChar = $uList[0]['char_start'];
            $endChar = $uList[count($uList) - 1]['char_end'];

            if ($sectionContent !== null) {
                $relStart = max(0, $startChar - $sectionBaseOffset);
                $relEnd = min(mb_strlen($sectionContent), $endChar - $sectionBaseOffset);
                $contentText = ($relEnd > $relStart)
                    ? trim(mb_substr($sectionContent, $relStart, $relEnd - $relStart))
                    : $this->joinUnits($uList);
            } else {
                $contentText = $this->joinUnits($uList);
            }

            if (mb_strlen($contentText) <= $cfg->chunkSize) {
                $finalSegments[] = [
                    'content' => $contentText,
                    'char_start' => $startChar,
                    'char_end' => $endChar,
                    'unit_vectors' => $vList,
                ];
            } else {
                // Split oversized segment by line / character chunks
                $subChunks = DocumentStore::chunk($contentText, $cfg->chunkSize, $cfg->chunkOverlap);
                foreach ($subChunks as $sub) {
                    $finalSegments[] = [
                        'content' => $sub,
                        'char_start' => $startChar,
                        'char_end' => $endChar,
                        'unit_vectors' => null, // Defect 7: Sub-chunks must not inherit full pooled vector
                    ];
                }
            }
        }

        return $finalSegments;
    }

    /**
     * Compute sliding window texts around each unit.
     *
     * @param array<int, array{text: string}> $units
     * @return array<int, string>
     */
    private function buildWindows(array $units, int $windowRadius): array {
        $count = count($units);
        $windows = [];

        for ($i = 0; $i < $count; $i++) {
            $start = max(0, $i - $windowRadius);
            $length = min($count - $start, 1 + (2 * $windowRadius));
            $slice = array_slice($units, $start, $length);
            $parts = array_map(static fn(array $u): string => $u['text'], $slice);
            $windows[$i] = implode(' ', $parts);
        }

        return $windows;
    }

    /**
     * Lexical token cosine similarity between two text windows.
     */
    private function lexicalCosine(string $a, string $b): float {
        $tokensA = $this->tokenize($a);
        $tokensB = $this->tokenize($b);

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $allKeys = array_unique(array_merge(array_keys($tokensA), array_keys($tokensB)));
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($allKeys as $k) {
            $vA = $tokensA[$k] ?? 0;
            $vB = $tokensB[$k] ?? 0;
            $dot += $vA * $vB;
            $normA += $vA * $vA;
            $normB += $vB * $vB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return array<string, int> term frequencies
     */
    private function tokenize(string $text): array {
        $clean = mb_strtolower(trim($text));
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $clean, $matches);
        if (empty($matches[0])) {
            return [];
        }

        return array_count_values($matches[0]);
    }

    /**
     * Calculate percentile value from an array of floats.
     *
     * @param float[] $values
     */
    private function percentile(array $values, int $percentile): float {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $index = ($percentile / 100.0) * (count($values) - 1);
        $floor = (int) floor($index);
        $ceil = (int) ceil($index);
        if ($floor === $ceil) {
            return $values[$floor];
        }
        $d = $index - $floor;
        return $values[$floor] * (1.0 - $d) + $values[$ceil] * $d;
    }

    /**
     * Average and L2-normalize an array of unit vectors.
     *
     * @param array<int, array<int, float>> $vectors
     * @return array<int, float>|null
     */
    private function poolVectors(array $vectors): ?array {
        if ($vectors === []) {
            return null;
        }
        $dims = count($vectors[0]);
        if ($dims === 0) {
            return null;
        }

        $sum = array_fill(0, $dims, 0.0);
        foreach ($vectors as $vec) {
            if (count($vec) !== $dims) {
                return null;
            }
            for ($d = 0; $d < $dims; $d++) {
                $sum[$d] += (float) $vec[$d];
            }
        }

        $norm = 0.0;
        for ($d = 0; $d < $dims; $d++) {
            $norm += $sum[$d] * $sum[$d];
        }

        if ($norm <= 0.0) {
            return null;
        }

        $invNorm = 1.0 / sqrt($norm);
        for ($d = 0; $d < $dims; $d++) {
            $sum[$d] *= $invNorm;
        }

        return $sum;
    }

    /**
     * @param array<int, array{text: string}> $units
     */
    private function joinUnits(array $units): string {
        return trim(implode(' ', array_column($units, 'text')));
    }
}
