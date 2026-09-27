<?php

declare(strict_types=1);

/**
 * OpenAI Vector Embedding configuration for SimpleMCP.
 *
 * Copy this file to `config/embedding.php` and configure your API key.
 * When configured, SimpleMCP automatically generates dense vector embeddings
 * for document chunks and knowledge graph observations, replacing the
 * character-trigram weighted loop with cosine similarity vector semantic search.
 *
 * Environment variables:
 * - OPENAI_API_KEY: Your OpenAI API key
 * - OPENAI_EMBEDDING_MODEL: Embedding model (default: text-embedding-3-small)
 * - OPENAI_BASE_URL: Custom endpoint (default: https://api.openai.com/v1)
 */
return [
    // Your OpenAI API Key (or set the OPENAI_API_KEY environment variable)
    'api_key' => getenv('OPENAI_API_KEY') ?: '',

    // Standard OpenAI embedding model:
    // - 'text-embedding-3-small' (default, 1536 dims, fast & accurate)
    // - 'text-embedding-3-large' (3072 dims, maximum accuracy)
    // - 'text-embedding-ada-002' (1536 dims, legacy standard)
    'model' => getenv('OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small',

    // Base URL for OpenAI API (or compatible local proxy, e.g. Ollama, Azure, vLLM)
    'base_url' => getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1',

    // Optional reduced dimensions (supported by text-embedding-3-* models: e.g. 512, 1024)
    'dimensions' => null,

    // HTTP request timeout in seconds
    'timeout' => 15,
];
