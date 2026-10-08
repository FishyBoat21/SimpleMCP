<?php

declare(strict_types=1);

/**
 * Semantic and Large-Document Chunking Configuration for SimpleMCP.
 *
 * Copy this file to `config/chunking.php` to customize document chunking behavior.
 * When configured, SimpleMCP can intelligently segment large documents using
 * document structure (markdown headings, code blocks, tables) and dense vector
 * embedding similarity breakpoints or lexical TF-IDF fallback.
 *
 * Environment variables:
 * - CHUNKING_STRATEGY: auto | semantic | fixed (default: auto)
 * - CHUNKING_MAX_EMBEDDED_UNITS: Max units embedded during breakpoint detection (default: 5000, 0 = unlimited)
 * - CHUNKING_CHUNK_VECTORS: reembed | pooled (default: reembed)
 */
return [
    // Chunking strategy:
    // - 'auto': Markdown, HTML, and text use semantic chunking; code, CSV, and JSON use fixed.
    // - 'semantic': Semantic chunking for all formats except tabular CSV/JSON.
    // - 'fixed': Legacy character/paragraph greedy chunking.
    'strategy' => getenv('CHUNKING_STRATEGY') ?: 'auto',

    // Target maximum chunk length in characters (50..8000)
    'chunk_size' => 1000,

    // Minimum chunk length before merging with neighbor (clamped to 50..chunk_size)
    'min_chunk_size' => 250,

    // Characters of overlap carried between consecutive chunks in 'fixed' strategy
    'chunk_overlap' => 150,

    // Whole sentence/unit overlap carried forward in semantic chunking (0..3)
    'overlap_units' => 1,

    // Percentile threshold for breakpoint detection (50..99). Higher = fewer, larger chunks.
    'breakpoint_percentile' => 90,

    // Neighbor units included on each side when building unit context window (0..3)
    'window_units' => 1,

    // Minimum character length for an independent unit; smaller sentences merge into neighbors
    'min_unit_chars' => 80,

    // Maximum units sent for breakpoint embedding per document (0 = unlimited).
    // Sections beyond this budget use TF-IDF lexical similarity breakpoints.
    'max_embedded_units' => (int) (getenv('CHUNKING_MAX_EMBEDDED_UNITS') ?: 5000),

    // Final chunk vector strategy:
    // - 'reembed': Embed final contextualized chunk text directly (optimal retrieval quality).
    // - 'pooled': Average unit vectors computed during breakpoint detection where available.
    'chunk_vectors' => getenv('CHUNKING_CHUNK_VECTORS') ?: 'reembed',

    // Reuse existing vectors from memory_embeddings when content_hash + vector profile match
    'reuse_vectors' => true,
];
