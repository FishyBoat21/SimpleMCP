<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;

/**
 * SQLite-backed document store for the RAG knowledge-base tooling.
 *
 * Documents are ingested as text (txt / markdown / csv / json / html / code),
 * split into overlapping chunks, and persisted in the same database file as the
 * knowledge graph (data/memory.sqlite) — separate tables: memory_documents and
 * memory_chunks, with an FTS5 mirror (memory_chunks_fts) powering BM25 keyword
 * retrieval. Everything is created idempotently on first use.
 *
 * Every row is scoped to a username, so each user gets an isolated document
 * library, exactly like the per-user graph in MemoryStore. In stdio mode the
 * injected user is the trusted `local` user.
 *
 * Retrieval mirrors the graph's zero-dependency search: keyword = FTS5 BM25,
 * semantic = IDF-weighted character n-gram similarity, hybrid = Reciprocal Rank
 * Fusion of both. Documents carry created/updated metadata but no validity
 * window — document content is not a temporal fact, so there is no as_of /
 * invalidate plumbing here.
 */
final class DocumentStore {
    private PDO $pdo;

    public function __construct(?string $dbPath = null) {
        $dbPath ??= dirname(__DIR__, 2) . '/data/memory.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');

        $this->createSchema();
    }

    private function createSchema(): void {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS memory_documents (
                id          TEXT NOT NULL,
                username    TEXT NOT NULL,
                filename    TEXT NOT NULL,
                title       TEXT NOT NULL DEFAULT '',
                source      TEXT NOT NULL DEFAULT '',
                format      TEXT NOT NULL DEFAULT 'text',
                chunk_count INTEGER NOT NULL DEFAULT 0,
                created_at  INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL,
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_documents_username ON memory_documents(username);

            CREATE TABLE IF NOT EXISTS memory_chunks (
                id          TEXT NOT NULL,
                username    TEXT NOT NULL,
                document_id TEXT NOT NULL,
                idx         INTEGER NOT NULL,
                content     TEXT NOT NULL,
                created_at  INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL,
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_chunks_document ON memory_chunks(username, document_id);

            CREATE VIRTUAL TABLE IF NOT EXISTS memory_chunks_fts USING fts5(
                username    UNINDEXED,
                chunk_id    UNINDEXED,
                document_id UNINDEXED,
                content
            );
            SQL);

        // A database created before the FTS index existed has chunks but no
        // index entries: rebuild once so keyword search covers pre-existing data.
        $indexed = (int) $this->pdo->query('SELECT COUNT(*) FROM memory_chunks_fts')->fetchColumn();
        $chunks = (int) $this->pdo->query('SELECT COUNT(*) FROM memory_chunks')->fetchColumn();
        if ($indexed === 0 && $chunks > 0) {
            $rows = $this->pdo->query('SELECT username, id FROM memory_chunks')->fetchAll();
            foreach ($rows as $row) {
                $this->syncFtsRow($row['username'], $row['id']);
            }
        }
    }

    /**
     * Split a document into retrieval-friendly chunks. Each chunk targets
     * `chunkSize` characters; consecutive chunks overlap by `overlap` characters
     * so context spans the boundaries. Splitting prefers natural boundaries:
     * paragraphs, then lines, then a hard split for oversized single lines.
     *
     * @param string $text the raw document text
     * @param int $chunkSize target chunk length (clamped 50..8000)
     * @param int $overlap characters carried between chunks (clamped 0..size/2)
     * @return string[] chunks in document order
     */
    public static function chunk(string $text, int $chunkSize = 1000, int $overlap = 150): array {
        $chunkSize = max(50, min(8000, $chunkSize));
        $overlap = max(0, min(intdiv($chunkSize, 2), $overlap));
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        // 1. Atomic pieces, each <= chunkSize, broken at paragraph/line boundaries.
        $pieces = [];
        foreach (preg_split('/\n\s*\n/', $text) as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if (strlen($paragraph) <= $chunkSize) {
                $pieces[] = $paragraph;
                continue;
            }

            // A long paragraph: group adjacent short lines, hard-split long ones.
            $buf = '';
            foreach (explode("\n", $paragraph) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strlen($line) > $chunkSize) {
                    if ($buf !== '') {
                        $pieces[] = $buf;
                        $buf = '';
                    }
                    $parts = function_exists('mb_str_split')
                        ? mb_str_split($line, $chunkSize)
                        : str_split($line, $chunkSize);
                    foreach ($parts as $part) {
                        $pieces[] = $part;
                    }
                    continue;
                }
                $candidate = $buf === '' ? $line : $buf . "\n" . $line;
                if (strlen($candidate) <= $chunkSize) {
                    $buf = $candidate;
                } else {
                    $pieces[] = $buf;
                    $buf = $line;
                }
            }
            if ($buf !== '') {
                $pieces[] = $buf;
            }
        }
        if ($pieces === []) {
            return [];
        }

        // 2. Greedy pack into chunks, carrying the previous chunk's tail forward.
        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $candidate = $current === '' ? $piece : $current . "\n\n" . $piece;
            if ($current !== '' && strlen($candidate) > $chunkSize) {
                $chunks[] = $current;
                // NB: -$overlap must not be -0 (PHP treats -0 as 0, returning the
                // whole chunk); guard so overlap=0 yields an empty tail.
                $tail = $overlap > 0
                    ? trim(function_exists('mb_substr') ? mb_substr($current, -$overlap) : substr($current, -$overlap))
                    : '';
                if ($tail !== '' && strlen($tail . "\n\n" . $piece) <= $chunkSize) {
                    $current = $tail . "\n\n" . $piece;
                } else {
                    $current = $piece; // overlap would overflow — start clean
                }
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Ingest a document: chunk its content and store it, replacing any previous
     * version with the same id. The id defaults to a slug of the filename, so
     * re-ingesting the same filename replaces that document idempotently.
     *
     * @param string $username owner of the document
     * @param array<string, mixed> $params content, filename, optional id/format/title/source/chunk_size/chunk_overlap
     * @return array<string, mixed> {id, filename, format, title, source, chunkCount, replaced, chunks} or {error}
     */
    public function ingestDocument(string $username, array $params): array {
        $content = trim((string) ($params['content'] ?? ''));
        $filename = trim((string) ($params['filename'] ?? ''));
        if ($content === '') {
            return ['error' => 'content must be a non-empty string.'];
        }
        if ($filename === '') {
            return ['error' => 'filename must be a non-empty string.'];
        }

        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            $id = self::slugify($filename) ?: 'document';
        }

        $format = (string) ($params['format'] ?? self::inferFormat($filename));
        if (!in_array($format, ['text', 'markdown', 'csv', 'json', 'html', 'code'], true)) {
            $format = 'text';
        }

        $chunkSize = max(50, min(8000, (int) ($params['chunk_size'] ?? 1000)));
        $overlap = max(0, min(2000, (int) ($params['chunk_overlap'] ?? 150)));

        $chunks = self::chunk($content, $chunkSize, $overlap);
        if ($chunks === []) {
            return ['error' => 'Document contains no chunkable text.'];
        }

        $existing = $this->findDocument($username, $id);
        $now = time();

        $this->pdo->prepare(
            'INSERT INTO memory_documents (id, username, filename, title, source, format, chunk_count, created_at, updated_at)
             VALUES (:id, :username, :filename, :title, :source, :format, :chunk_count, :created_at, :updated_at)
             ON CONFLICT (username, id) DO UPDATE SET
                 filename  = excluded.filename,
                 title     = excluded.title,
                 source    = excluded.source,
                 format    = excluded.format,
                 chunk_count = excluded.chunk_count,
                 updated_at = excluded.updated_at'
        )->execute([
            ':id' => $id,
            ':username' => $username,
            ':filename' => $filename,
            ':title' => (string) ($params['title'] ?? ''),
            ':source' => (string) ($params['source'] ?? ''),
            ':format' => $format,
            ':chunk_count' => count($chunks),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        // Replace the old chunk set (and its FTS mirror) with the new one.
        $this->deleteChunks($username, $id);

        $chunkIds = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO memory_chunks (id, username, document_id, idx, content, created_at, updated_at)
             VALUES (:id, :username, :document_id, :idx, :content, :created_at, :updated_at)'
        );
        foreach ($chunks as $idx => $text) {
            $chunkId = $id . '#' . $idx;
            $stmt->execute([
                ':id' => $chunkId,
                ':username' => $username,
                ':document_id' => $id,
                ':idx' => $idx,
                ':content' => $text,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $this->syncFtsRow($username, $chunkId);
            $chunkIds[] = $chunkId;
        }

        return [
            'id' => $id,
            'filename' => $filename,
            'format' => $format,
            'title' => (string) ($params['title'] ?? ''),
            'source' => (string) ($params['source'] ?? ''),
            'chunkCount' => count($chunks),
            'replaced' => $existing !== null,
            'chunks' => $chunkIds,
        ];
    }

    /**
     * Retrieve the most relevant chunks for a query. keyword = FTS5 BM25 over
     * the chunk index; semantic = IDF-weighted character n-gram similarity;
     * hybrid = Reciprocal Rank Fusion of both. An optional `documentId` scopes
     * the retrieval to a single document.
     *
     * @param string $username owner of the documents
     * @param string $query search text
     * @param string $searchType keyword|semantic|hybrid
     * @param int $topK max results (clamped 1..50)
     * @param string|null $documentId restrict to one document
     * @param bool $includeContent include each chunk's full text
     * @return array<string, mixed>
     */
    public function retrieve(
        string $username,
        string $query,
        string $searchType = 'hybrid',
        int $topK = 5,
        ?string $documentId = null,
        bool $includeContent = true,
    ): array {
        $topK = max(1, min(50, $topK));
        $query = trim($query);
        if ($query === '') {
            return ['query' => '', 'searchType' => $searchType, 'topK' => $topK, 'total' => 0, 'results' => []];
        }
        if (!in_array($searchType, ['keyword', 'semantic', 'hybrid'], true)) {
            $searchType = 'hybrid';
        }

        $keywordIds = array_map(
            static fn(array $row): string => $row['id'],
            $this->keywordSearch($username, $query, $documentId),
        );
        $semanticIds = array_map(
            static fn(array $row): string => $row['id'],
            $this->semanticSearch($username, $query, $documentId),
        );

        $lists = [];
        if ($searchType === 'keyword' || $searchType === 'hybrid') {
            $lists['keyword'] = $keywordIds;
        }
        if ($searchType === 'semantic' || $searchType === 'hybrid') {
            $lists['semantic'] = $semanticIds;
        }

        $ranked = $this->rrf($lists);
        $resultIds = array_slice(array_keys($ranked), 0, $topK);

        $results = [];
        foreach ($resultIds as $chunkId) {
            $chunk = $this->chunkRow($username, $chunkId);
            if ($chunk === null) {
                continue;
            }
            $item = [
                'chunkId' => $chunkId,
                'documentId' => $chunk['document_id'],
                'chunkIndex' => (int) $chunk['idx'],
                'score' => round($ranked[$chunkId], 4),
                'matchedOn' => $this->matchedOn($chunk['content'], $query),
            ];
            if ($includeContent) {
                $item['content'] = $chunk['content'];
            }
            $doc = $this->findDocument($username, $chunk['document_id']);
            if ($doc !== null) {
                $item['document'] = [
                    'filename' => $doc['filename'],
                    'title' => $doc['title'],
                    'source' => $doc['source'],
                    'format' => $doc['format'],
                ];
            }
            $results[] = $item;
        }

        return [
            'query' => $query,
            'searchType' => $searchType,
            'topK' => $topK,
            'total' => count($results),
            'results' => $results,
        ];
    }

    /** @return array{documents: array<int, array<string, mixed>>, total: int} */
    public function listDocuments(string $username): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, title, source, format, chunk_count, created_at, updated_at
             FROM memory_documents
             WHERE username = :username
             ORDER BY created_at DESC'
        );
        $stmt->execute([':username' => $username]);

        $documents = [];
        foreach ($stmt->fetchAll() as $row) {
            $documents[] = [
                'id' => $row['id'],
                'filename' => $row['filename'],
                'title' => $row['title'],
                'source' => $row['source'],
                'format' => $row['format'],
                'chunkCount' => (int) $row['chunk_count'],
                'createdAt' => $this->formatTime($row['created_at']),
                'updatedAt' => $this->formatTime($row['updated_at']),
            ];
        }
        return ['documents' => $documents, 'total' => count($documents)];
    }

    /**
     * A document and its full text: metadata plus every chunk in order.
     *
     * @return array<string, mixed> document with `chunks`, or {error}
     */
    public function getDocument(string $username, string $id): array {
        $doc = $this->findDocument($username, $id);
        if ($doc === null) {
            return ['error' => "Document '$id' not found in this user's knowledge base."];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, idx, content FROM memory_chunks
             WHERE username = :username AND document_id = :document_id
             ORDER BY idx'
        );
        $stmt->execute([':username' => $username, ':document_id' => $id]);

        $chunks = [];
        foreach ($stmt->fetchAll() as $row) {
            $chunks[] = [
                'chunkId' => $row['id'],
                'index' => (int) $row['idx'],
                'content' => $row['content'],
            ];
        }

        return [
            'id' => $doc['id'],
            'filename' => $doc['filename'],
            'format' => $doc['format'],
            'title' => $doc['title'],
            'source' => $doc['source'],
            'chunkCount' => (int) $doc['chunk_count'],
            'createdAt' => $this->formatTime($doc['created_at']),
            'updatedAt' => $this->formatTime($doc['updated_at']),
            'chunks' => $chunks,
        ];
    }

    /**
     * Delete a document and all of its chunks (cascading the FTS mirror).
     *
     * @return array{deleted: bool, error?: string, id?: string, filename?: string, chunksRemoved?: int}
     */
    public function deleteDocument(string $username, string $id): array {
        $doc = $this->findDocument($username, $id);
        if ($doc === null) {
            return ['deleted' => false, 'error' => "Document '$id' not found in this user's knowledge base."];
        }

        $this->deleteChunks($username, $id);
        $this->pdo->prepare('DELETE FROM memory_documents WHERE username = :username AND id = :id')
            ->execute([':username' => $username, ':id' => $id]);

        return [
            'deleted' => true,
            'id' => $doc['id'],
            'filename' => $doc['filename'],
            'chunksRemoved' => (int) $doc['chunk_count'],
        ];
    }

    /** @return array<int, array{id: string, content: string}> ranked chunk rows, best first */
    private function keywordSearch(string $username, string $query, ?string $documentId): array {
        $match = $this->ftsMatchQuery($query);
        if ($match === '') {
            return [];
        }

        $sql = 'SELECT chunk_id AS id, content
                FROM memory_chunks_fts
                WHERE username = :username AND memory_chunks_fts MATCH :match';
        if ($documentId !== null) {
            $sql .= ' AND document_id = :document_id';
        }
        $sql .= ' ORDER BY rank DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':match', $match);
        $stmt->bindValue(':limit', 200, PDO::PARAM_INT);
        if ($documentId !== null) {
            $stmt->bindValue(':document_id', $documentId);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array{id: string, score: float}> chunks by semantic
     *         similarity, best first. Same zero-dependency character-trigram
     *         approach as MemoryStore::semanticSearch, applied over chunk
     *         content with corpus-level IDF weighting.
     */
    private function semanticSearch(string $username, string $query, ?string $documentId): array {
        $qGrams = $this->nGramSet($query);
        if ($qGrams === []) {
            return [];
        }

        $sql = 'SELECT id, content FROM memory_chunks WHERE username = :username';
        $params = [':username' => $username];
        if ($documentId !== null) {
            $sql .= ' AND document_id = :document_id';
            $params[':document_id'] = $documentId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $docs = [];
        $df = [];
        foreach ($stmt->fetchAll() as $row) {
            $grams = $this->nGramSet((string) $row['content']);
            if ($grams === []) {
                continue;
            }
            $docs[$row['id']] = $grams;
            foreach ($grams as $gram => $_) {
                $df[$gram] = ($df[$gram] ?? 0) + 1;
            }
        }
        $n = max(1, count($docs));

        $scored = [];
        foreach ($docs as $id => $docGrams) {
            $numerator = 0.0;
            $denominator = 0.0;
            foreach ($qGrams as $gram => $_) {
                $idf = log(($n + 1) / (($df[$gram] ?? 0) + 1)) + 1;
                $denominator += $idf;
                if (isset($docGrams[$gram])) {
                    $numerator += $idf;
                }
            }
            $score = $denominator > 0.0 ? $numerator / $denominator : 0.0;
            if ($score >= 0.25) {
                $scored[] = ['id' => $id, 'score' => $score];
            }
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));
        return $scored;
    }

    /**
     * Reciprocal Rank Fusion over ranked id lists — see MemoryStore::rrf for the
     * rationale (k = 60).
     *
     * @param array<string, string[]> $lists strategy name => ranked ids
     * @return array<string, float> id => RRF score, best first
     */
    private function rrf(array $lists): array {
        $scores = [];
        foreach ($lists as $list) {
            foreach (array_values($list) as $position => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (60.0 + $position);
            }
        }
        arsort($scores, SORT_NUMERIC);
        return $scores;
    }

    /** Turn a user query into an FTS5 MATCH expression (see MemoryStore). */
    private function ftsMatchQuery(string $query): string {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?? [];
        $terms = [];
        foreach ($tokens as $token) {
            $terms[] = '"' . str_replace('"', '', $token) . '"*';
        }
        return implode(' OR ', $terms);
    }

    /** Character trigrams (byte-level, case-folded) — see MemoryStore::nGramSet. @return array<string, true> */
    private function nGramSet(string $text): array {
        $text = strtolower($text);
        $set = [];
        $length = strlen($text);
        for ($i = 0; $i + 3 <= $length; $i++) {
            $set[substr($text, $i, 3)] = true;
        }
        return $set;
    }

    /** Which searchable field(s) contain the query verbatim. @return string[] */
    private function matchedOn(string $content, string $query): array {
        return $content !== '' && stripos($content, $query) !== false ? ['content'] : [];
    }

    /** @return array<string, mixed>|null */
    private function findDocument(string $username, string $id): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, filename, title, source, format, chunk_count, created_at, updated_at
             FROM memory_documents
             WHERE username = :username AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array{id: string, document_id: string, idx: int, content: string}|null */
    private function chunkRow(string $username, string $chunkId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, document_id, idx, content FROM memory_chunks
             WHERE username = :username AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':id' => $chunkId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Delete every chunk (and its FTS mirror) belonging to a document. */
    private function deleteChunks(string $username, string $documentId): void {
        $this->pdo->prepare('DELETE FROM memory_chunks_fts WHERE username = :username AND document_id = :document_id')
            ->execute([':username' => $username, ':document_id' => $documentId]);
        $this->pdo->prepare('DELETE FROM memory_chunks WHERE username = :username AND document_id = :document_id')
            ->execute([':username' => $username, ':document_id' => $documentId]);
    }

    /** Refresh the FTS mirror for one chunk, keeping it in lockstep with the source row. */
    private function syncFtsRow(string $username, string $chunkId): void {
        $stmt = $this->pdo->prepare(
            'SELECT document_id, content FROM memory_chunks WHERE username = :username AND id = :id'
        );
        $stmt->execute([':username' => $username, ':id' => $chunkId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $this->pdo->prepare('DELETE FROM memory_chunks_fts WHERE username = :username AND chunk_id = :id')
            ->execute([':username' => $username, ':id' => $chunkId]);
        $this->pdo->prepare(
            'INSERT INTO memory_chunks_fts (username, chunk_id, document_id, content)
             VALUES (:username, :id, :document_id, :content)'
        )->execute([
            ':username' => $username,
            ':id' => $chunkId,
            ':document_id' => $row['document_id'],
            ':content' => $row['content'],
        ]);
    }

    /** A lowercase dashed slug of a filename (CJK-safe), empty when nothing survives. */
    private static function slugify(string $filename): string {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', strtolower($base)) ?? '';
        return trim($slug, '-');
    }

    /** Guess the document format from the filename extension. */
    private static function inferFormat(string $filename): string {
        return match (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            'md', 'markdown' => 'markdown',
            'csv' => 'csv',
            'json' => 'json',
            'html', 'htm' => 'html',
            'php', 'py', 'js', 'ts', 'jsx', 'tsx', 'go', 'rs', 'java', 'kt', 'c', 'cpp', 'h', 'hpp',
            'sh', 'bash', 'zsh', 'sql', 'css', 'scss', 'rb', 'yml', 'yaml', 'xml', 'lua', 'r' => 'code',
            default => 'text',
        };
    }

    /** Render a Unix timestamp as an ISO-8601 string, or null when absent. */
    private function formatTime(mixed $ts): ?string {
        if ($ts === null || $ts === '') {
            return null;
        }
        return gmdate('c', (int) $ts);
    }
}
