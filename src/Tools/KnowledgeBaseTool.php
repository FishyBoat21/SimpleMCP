<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\Auth\DocumentStore;
use McpServer\Auth\MemoryStore;
use McpServer\UserContext;

/**
 * RAG knowledge-base tooling for the memory tool: ingest text documents into a
 * per-user, chunked store and retrieve the most relevant chunks for a query —
 * optionally fused with the knowledge-graph entities that also match. Together
 * with the graph, this makes the memory tool a complete knowledge base.
 *
 * Backed by src/Auth/DocumentStore.php (SQLite tables memory_documents /
 * memory_chunks plus an FTS5 mirror in the same data/memory.sqlite file). Like
 * the memory graph, every row is scoped to the calling user's username.
 *
 * Access: every tool here requires the caller to hold the `user` or `admin`
 * role, so only logged-in accounts can list or call them — anonymous HTTP
 * callers see none of these tools and get a -32001 access-denied on direct
 * calls. In stdio mode the injected `local` user holds the `*` role, so access
 * still works.
 */
readonly class KnowledgeBaseTool {
    /** @var string[] login required, mirroring MemoryTool::REQUIRED_ROLES */
    private const REQUIRED_ROLES = ['user', 'admin'];

    #[McpFunction(
        name: 'ingest_document',
        roles: self::REQUIRED_ROLES,
        description: 'Ingest a text-based document (txt, markdown, csv, json, html, code) into the knowledge base. The content is split into overlapping chunks for retrieval. Re-ingesting the same id (default: derived from the filename) replaces the previous version. Returns the document id, the chunk ids and the chunk count.',
        schema: [
            'type' => 'object',
            'properties' => [
                'content' => ['type' => 'string', 'description' => 'The raw text of the document to ingest.'],
                'filename' => ['type' => 'string', 'description' => 'Name of the file (used for the default id and format inference).'],
                'id' => ['type' => 'string', 'description' => 'Optional stable id for the document. Defaults to a slug of the filename; re-using an existing id replaces that document.'],
                'format' => ['type' => 'string', 'enum' => ['text', 'markdown', 'csv', 'json', 'html', 'code'], 'description' => 'Optional file format. Defaults to a guess from the filename extension.'],
                'title' => ['type' => 'string', 'description' => 'Optional human-readable title for the document.'],
                'source' => ['type' => 'string', 'description' => 'Optional provenance note, e.g. a URL or file path.'],
                'chunk_size' => ['type' => 'integer', 'default' => 1000, 'minimum' => 50, 'maximum' => 8000, 'description' => 'Target chunk length in characters.'],
                'chunk_overlap' => ['type' => 'integer', 'default' => 150, 'minimum' => 0, 'maximum' => 2000, 'description' => 'Characters of overlap carried between consecutive chunks so context spans boundaries.'],
            ],
            'required' => ['content', 'filename'],
        ]
    )]
    public function ingestDocument(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $result = (new DocumentStore())->ingestDocument($user->username, $arguments);
        if (isset($result['error'])) {
            return [['type' => 'text', 'text' => 'Error: ' . $result['error']]];
        }

        $chunkLabel = $result['chunkCount'] === 1 ? 'chunk' : 'chunks';
        $text = 'Ingested "' . $result['filename'] . '" as ' . $result['format']
            . " document \"{$result['id']}\" with {$result['chunkCount']} $chunkLabel.";
        if ($result['replaced']) {
            $text .= ' Replaced a previous version.';
        }
        $text .= "\n\nChunks:\n" . implode("\n", $result['chunks']);

        return [['type' => 'text', 'text' => $text]];
    }

    #[McpFunction(
        name: 'retrieve',
        roles: self::REQUIRED_ROLES,
        description: 'Retrieve the most relevant document chunks for a query (RAG retrieval). keyword = BM25 via the SQLite FTS5 index; semantic = fuzzy character n-gram similarity (resilient to typos and CJK text); hybrid = both fused with Reciprocal Rank Fusion (default). Pass document_id to scope retrieval to one document, and include_graph to also fuse matching knowledge-graph entities into the result under an "entities" key.',
        schema: [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search text, e.g. "deployment steps" or "how is auth handled".'],
                'search_type' => ['type' => 'string', 'enum' => ['keyword', 'semantic', 'hybrid'], 'default' => 'hybrid', 'description' => 'Retrieval strategy.'],
                'top_k' => ['type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 50, 'description' => 'Maximum number of chunks to return.'],
                'document_id' => ['type' => 'string', 'description' => 'Optional: restrict retrieval to a single document by its id.'],
                'include_content' => ['type' => 'boolean', 'default' => true, 'description' => 'Include each chunk\'s full text in the results.'],
                'include_graph' => ['type' => 'boolean', 'default' => false, 'description' => 'Also run search_graph over the query and return matching knowledge-graph entities under an "entities" key.'],
            ],
            'required' => ['query'],
        ]
    )]
    public function retrieve(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $documentId = $arguments['document_id'] ?? null;
        if (!is_string($documentId) || $documentId === '') {
            $documentId = null;
        }

        $result = (new DocumentStore())->retrieve(
            $user->username,
            (string) ($arguments['query'] ?? ''),
            (string) ($arguments['search_type'] ?? 'hybrid'),
            (int) ($arguments['top_k'] ?? 5),
            $documentId,
            (bool) ($arguments['include_content'] ?? true),
        );

        if (($arguments['include_graph'] ?? false) === true) {
            $graph = (new MemoryStore())->searchGraph(
                $user->username,
                (string) ($arguments['query'] ?? ''),
                'hybrid',
                (int) ($arguments['top_k'] ?? 5),
                1,
                null,
            );
            $result['entities'] = $graph['results'] ?? [];
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
    }

    #[McpFunction(
        name: 'list_documents',
        roles: self::REQUIRED_ROLES,
        description: 'List the documents ingested into the current user\'s knowledge base: id, filename, format, title, source, chunk count and timestamps.',
        schema: [
            'type' => 'object',
            'properties' => new \stdClass(), // encodes as {} so MCP clients accept it as a record
        ]
    )]
    public function listDocuments(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $result = (new DocumentStore())->listDocuments($user->username);
        return [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
    }

    #[McpFunction(
        name: 'get_document',
        roles: self::REQUIRED_ROLES,
        description: 'Get a document from the current user\'s knowledge base: its metadata plus the full text as chunks in document order.',
        schema: [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Document id.'],
            ],
            'required' => ['id'],
        ]
    )]
    public function getDocument(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $id = (string) ($arguments['id'] ?? '');
        if ($id === '') {
            return [['type' => 'text', 'text' => "Error: 'id' must be a non-empty string."]];
        }

        $result = (new DocumentStore())->getDocument($user->username, $id);
        if (isset($result['error'])) {
            return [['type' => 'text', 'text' => 'Warning: ' . $result['error']]];
        }

        return [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
    }

    #[McpFunction(
        name: 'delete_document',
        roles: self::REQUIRED_ROLES,
        description: 'Delete a document and all of its chunks from the current user\'s knowledge base. Idempotent: a missing document is reported as a warning.',
        schema: [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Document id to delete.'],
            ],
            'required' => ['id'],
        ]
    )]
    public function deleteDocument(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $id = (string) ($arguments['id'] ?? '');
        if ($id === '') {
            return [['type' => 'text', 'text' => "Error: 'id' must be a non-empty string."]];
        }

        $result = (new DocumentStore())->deleteDocument($user->username, $id);
        if (($result['deleted'] ?? false) === false) {
            return [['type' => 'text', 'text' => 'Warning: ' . ($result['error'] ?? 'unknown error')]];
        }

        $label = $result['chunksRemoved'] === 1 ? 'chunk' : 'chunks';
        return [
            [
                'type' => 'text',
                'text' => "Deleted document '{$result['id']}' ({$result['filename']}) and its {$result['chunksRemoved']} $label.",
            ],
        ];
    }
}
