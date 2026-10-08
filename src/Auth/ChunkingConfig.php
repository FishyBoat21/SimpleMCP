<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Configuration for document chunking strategies, thresholds, and limits.
 */
final readonly class ChunkingConfig {
    public string $strategy;
    public int $chunkSize;
    public int $minChunkSize;
    public int $chunkOverlap;
    public int $overlapUnits;
    public int $breakpointPercentile;
    public int $windowUnits;
    public int $minUnitChars;
    public int $maxEmbeddedUnits;
    public string $chunkVectors;
    public bool $reuseVectors;

    public function __construct(
        string $strategy = 'auto',
        int $chunkSize = 1000,
        ?int $minChunkSize = null,
        int $chunkOverlap = 150,
        int $overlapUnits = 1,
        int $breakpointPercentile = 90,
        int $windowUnits = 1,
        int $minUnitChars = 80,
        int $maxEmbeddedUnits = 5000,
        string $chunkVectors = 'reembed',
        bool $reuseVectors = true,
    ) {
        $strategy = strtolower(trim($strategy));
        $this->strategy = in_array($strategy, ['auto', 'semantic', 'fixed'], true) ? $strategy : 'auto';

        $this->chunkSize = max(50, min(8000, $chunkSize));
        $resolvedMin = $minChunkSize ?? max(50, intdiv($this->chunkSize, 4));
        $this->minChunkSize = max(0, min($this->chunkSize, $resolvedMin));
        $this->chunkOverlap = max(0, min(intdiv($this->chunkSize, 2), min(2000, $chunkOverlap)));
        $this->overlapUnits = max(0, min(3, $overlapUnits));
        $this->breakpointPercentile = max(50, min(99, $breakpointPercentile));
        $this->windowUnits = max(0, min(3, $windowUnits));
        $this->minUnitChars = max(20, min(500, $minUnitChars));
        $this->maxEmbeddedUnits = max(0, $maxEmbeddedUnits);

        $chunkVectors = strtolower(trim($chunkVectors));
        $this->chunkVectors = in_array($chunkVectors, ['reembed', 'pooled'], true) ? $chunkVectors : 'reembed';
        $this->reuseVectors = $reuseVectors;
    }

    /**
     * Load configuration from file with optional overrides.
     *
     * @param array<string, mixed>|null $override
     */
    public static function load(?array $override = null): self {
        $cfg = [];
        $root = dirname(__DIR__, 2);
        $chunkFile = $root . '/config/chunking.php';

        if (file_exists($chunkFile)) {
            $loaded = require $chunkFile;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        } else {
            $configFile = $root . '/config/config.php';
            if (file_exists($configFile)) {
                $appCfg = require $configFile;
                if (is_array($appCfg) && isset($appCfg['chunking']) && is_array($appCfg['chunking'])) {
                    $cfg = $appCfg['chunking'];
                }
            }
        }

        if ($override !== null) {
            $cfg = array_merge($cfg, $override);
        }

        return self::fromArray($cfg);
    }

    /**
     * Create an instance from an array of options.
     *
     * @param array<string, mixed> $cfg
     */
    public static function fromArray(array $cfg): self {
        return new self(
            strategy: (string) ($cfg['strategy'] ?? $cfg['chunking'] ?? 'auto'),
            chunkSize: (int) ($cfg['chunk_size'] ?? 1000),
            minChunkSize: isset($cfg['min_chunk_size']) ? (int) $cfg['min_chunk_size'] : null,
            chunkOverlap: (int) ($cfg['chunk_overlap'] ?? 150),
            overlapUnits: (int) ($cfg['overlap_units'] ?? 1),
            breakpointPercentile: (int) ($cfg['breakpoint_percentile'] ?? 90),
            windowUnits: (int) ($cfg['window_units'] ?? 1),
            minUnitChars: (int) ($cfg['min_unit_chars'] ?? 80),
            maxEmbeddedUnits: (int) ($cfg['max_embedded_units'] ?? 5000),
            chunkVectors: (string) ($cfg['chunk_vectors'] ?? 'reembed'),
            reuseVectors: (bool) ($cfg['reuse_vectors'] ?? true),
        );
    }

    /**
     * Return a new instance merged with runtime parameters (e.g. tool arguments).
     *
     * @param array<string, mixed> $args
     */
    public function withArguments(array $args): self {
        $data = [
            'strategy' => $args['chunking'] ?? $args['strategy'] ?? $this->strategy,
            'chunk_size' => $args['chunk_size'] ?? $this->chunkSize,
            'min_chunk_size' => $args['min_chunk_size'] ?? $this->minChunkSize,
            'chunk_overlap' => $args['chunk_overlap'] ?? $this->chunkOverlap,
            'overlap_units' => $args['overlap_units'] ?? $this->overlapUnits,
            'breakpoint_percentile' => $args['breakpoint_percentile'] ?? $this->breakpointPercentile,
            'window_units' => $args['window_units'] ?? $this->windowUnits,
            'min_unit_chars' => $args['min_unit_chars'] ?? $this->minUnitChars,
            'max_embedded_units' => $args['max_embedded_units'] ?? $this->maxEmbeddedUnits,
            'chunk_vectors' => $args['chunk_vectors'] ?? $this->chunkVectors,
            'reuse_vectors' => $args['reuse_vectors'] ?? $this->reuseVectors,
        ];

        return self::fromArray($data);
    }

    /**
     * Determine the effective strategy for a document format and embedding availability.
     * Returns 'semantic', 'semantic-lexical', or 'fixed'.
     */
    public function effectiveStrategy(string $format, bool $embeddingsConfigured): string {
        $format = strtolower(trim($format));

        // Tabular structures never use semantic sentence splitting
        if (in_array($format, ['csv', 'json'], true)) {
            return 'fixed';
        }

        if ($this->strategy === 'fixed') {
            return 'fixed';
        }

        if ($this->strategy === 'auto') {
            // Markdown, HTML, and plain prose benefit from hierarchical semantic chunking
            if (in_array($format, ['text', 'markdown', 'html'], true)) {
                return $embeddingsConfigured ? 'semantic' : 'semantic-lexical';
            }
            return 'fixed';
        }

        // 'semantic' requested explicitly
        return $embeddingsConfigured ? 'semantic' : 'semantic-lexical';
    }
}
