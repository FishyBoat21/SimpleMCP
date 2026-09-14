<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;
use PDOException;
use Throwable;

/**
 * Per-user clipboard for bulky tool output, capped and evicted least-recently-used.
 *
 * The MCP protocol feeds every tool result to the model verbatim, so a tool that
 * returns a large payload floods the context window. This store is the escape
 * hatch: a result over its tool's `offloadAt` threshold is written here and
 * replaced in the response by a short receipt carrying a clip id. The caller can
 * then read the clip back on demand, or hand the id to another tool that accepts
 * `clip_id` — in which case the bytes never enter the context window at all.
 *
 * Storage is data/memory.sqlite (tables memory_clips and memory_clip_tombstones),
 * created idempotently on first use and scoped to a username like every other
 * per-user store here. Persistence is not optional: HTTP mode builds a fresh
 * McpServer per request (see index.php), so an in-process clipboard would be
 * empty on every call.
 *
 * Retention: at most `clipboard.max_entries` (default 10) live clips per user,
 * evicted least-recently-used first. `pinned` clips are exempt from eviction
 * (they may push the clipboard over the limit), and `expires_at` expires the
 * rest. There is no scheduler in this codebase — stdio is a fgets() loop and
 * there is no daemon — so expiry is swept lazily at the top of every entry point.
 *
 * Eviction is visible, not silent. Deleting a clip frees its bytes, so the
 * "what happened to my handle" answer is recorded separately in a small,
 * bounded tombstone table (metadata only, newest 32 per user). A `get` on a
 * dead id can therefore say deleted / evicted / expired rather than a bare
 * "not found" — a model told only "not found" for a clip it did hold will try
 * to reconstruct the data instead of re-running the tool that produced it.
 */
final class ClipboardStore {
    /** Live clips per user. Pinned clips may push past this; see HARD_MAX_ENTRIES. */
    private const DEFAULT_MAX_ENTRIES = 10;

    /** Ceiling even for pinned overflow — the one case where put() refuses. */
    private const HARD_MAX_ENTRIES = 50;

    /** Tombstones kept per user; older ones age out (the miss message says so). */
    private const MAX_TOMBSTONES = 32;

    /** TTL applied to a put that does not specify one. */
    private const DEFAULT_TTL = 86400;

    /** Longest TTL a caller may request. */
    private const MAX_TTL = 604800;

    /** Largest single clip. */
    private const DEFAULT_MAX_BYTES = 1048576;

    /** Bytes of head+tail preview in an automatic offload receipt. */
    private const DEFAULT_PREVIEW = 2000;

    /** Bytes returned by one get() when the caller does not ask for a window. */
    private const DEFAULT_MAX_CHARS = 20000;

    /** Ceiling for a single get() — the flood guard. */
    private const HARD_MAX_CHARS = 100000;

    /** Longest label kept (bytes). */
    private const MAX_LABEL = 120;

    /** Bytes of entropy in a clip id (8 bytes => 16 hex chars). */
    private const ID_BYTES = 8;

    /** Microseconds per second — timestamps are stored at this resolution. */
    private const MICROS = 1000000;

    private const REASON_EVICTED = 'evicted';
    private const REASON_EXPIRED = 'expired';
    private const REASON_DELETED = 'deleted';

    private PDO $pdo;

    /** @var array<string, mixed> the `clipboard` block of config/config.php */
    private array $config;

    public function __construct(?string $dbPath = null, ?array $config = null) {
        $dbPath ??= dirname(__DIR__, 2) . '/data/memory.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->config = $config ?? self::globalConfig();

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Unlike the graph and document stores, the clipboard is written from
        // the tools/call funnel, so two concurrent HTTP requests can contend for
        // the write lock. Wait for it rather than failing the tool call.
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        $this->createSchema();
    }

    private function createSchema(): void {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS memory_clips (
                id           TEXT NOT NULL,
                username     TEXT NOT NULL,
                label        TEXT NOT NULL DEFAULT '',
                source       TEXT NOT NULL DEFAULT '',
                content      TEXT NOT NULL,
                bytes        INTEGER NOT NULL,
                sha256       TEXT NOT NULL DEFAULT '',
                encoding     TEXT NOT NULL DEFAULT 'utf8',
                pinned       INTEGER NOT NULL DEFAULT 0,
                access_count INTEGER NOT NULL DEFAULT 0,
                created_at   INTEGER NOT NULL,
                last_access  INTEGER NOT NULL,
                expires_at   INTEGER,
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_clips_lru ON memory_clips(username, pinned, last_access);
            CREATE INDEX IF NOT EXISTS idx_memory_clips_sha ON memory_clips(username, sha256);

            -- Metadata only, never content: the whole point of eviction is that
            -- the bytes are gone. Bounded to MAX_TOMBSTONES per user by trimTombstones().
            CREATE TABLE IF NOT EXISTS memory_clip_tombstones (
                id         TEXT NOT NULL,
                username   TEXT NOT NULL,
                reason     TEXT NOT NULL,
                label      TEXT NOT NULL DEFAULT '',
                source     TEXT NOT NULL DEFAULT '',
                bytes      INTEGER NOT NULL DEFAULT 0,
                gone_at    INTEGER NOT NULL,
                evicted_by TEXT NOT NULL DEFAULT '',
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_clip_tombstones_gone ON memory_clip_tombstones(username, gone_at);
            SQL);
    }

    // -----------------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> the `clipboard` block of config/config.php, or [] */
    private static function globalConfig(): array {
        $path = dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) && is_array($config['clipboard'] ?? null) ? $config['clipboard'] : [];
    }

    /** A configured int, clamped to [$min, $max], falling back to $default. */
    private function setting(string $key, int $default, int $min, int $max): int {
        $value = $this->config[$key] ?? null;
        if (!is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, (int) $value));
    }

    public function maxEntries(): int {
        return $this->setting('max_entries', self::DEFAULT_MAX_ENTRIES, 1, self::HARD_MAX_ENTRIES);
    }

    public function defaultMaxChars(): int {
        return $this->setting('default_max_chars', self::DEFAULT_MAX_CHARS, 1, self::HARD_MAX_CHARS);
    }

    public function hardMaxChars(): int {
        return $this->setting('hard_max_chars', self::HARD_MAX_CHARS, 1, 10000000);
    }

    private function maxEntryBytes(): int {
        return $this->setting('max_entry_bytes', self::DEFAULT_MAX_BYTES, 1, PHP_INT_MAX);
    }

    private function previewBudget(): int {
        return $this->setting('preview_bytes', self::DEFAULT_PREVIEW, 0, self::HARD_MAX_CHARS);
    }

    private function defaultTtl(): int {
        return $this->setting('default_ttl_seconds', self::DEFAULT_TTL, 0, self::MAX_TTL);
    }

    private function maxTtl(): int {
        return $this->setting('max_ttl_seconds', self::MAX_TTL, 0, PHP_INT_MAX);
    }

    private function revealForeignIds(): bool {
        return (bool) ($this->config['reveal_foreign_ids'] ?? true);
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Store content as a clip, evicting least-recently-used clips to stay within
     * the entry limit. A clip the user already holds byte-identically is reused
     * rather than duplicated, so an accidentally repeated tool call does not burn
     * a second slot of a 10-slot budget.
     *
     * @return array<string, mixed> the stored clip plus eviction detail, or {error}[, reason]
     */
    public function put(
        string $username,
        string $content,
        string $label = '',
        string $source = 'clipboard',
        bool $pin = false,
        ?int $ttlSeconds = null,
    ): array {
        if (trim($content) === '') {
            return ['error' => "'content' must be a non-empty string."];
        }

        $maxBytes = $this->maxEntryBytes();
        if (strlen($content) > $maxBytes) {
            return [
                'error' => 'content is ' . number_format(strlen($content)) . ' bytes; the clipboard stores at most '
                    . number_format($maxBytes) . ' bytes per clip. Use ingest_document for large texts, or split the payload.',
                'reason' => 'too_large',
            ];
        }

        $ttl = $ttlSeconds ?? $this->defaultTtl();
        if ($ttl < 0) {
            return ['error' => "'ttl_seconds' must be >= 0."];
        }
        $ttlClamped = false;
        $maxTtl = $this->maxTtl();
        if ($ttl > $maxTtl) {
            $ttl = $maxTtl;
            $ttlClamped = true;
        }

        $label = self::utf8Head($label, self::MAX_LABEL);
        $source = $source !== '' ? self::utf8Head($source, self::MAX_LABEL) : 'clipboard';

        $now = self::now();
        $expiresAt = $ttl > 0 ? $now + ($ttl * self::MICROS) : null;
        $sha = hash('sha256', $content);
        $encoding = preg_match('//u', $content) === 1 ? 'utf8' : 'base64';
        $stored = $encoding === 'base64' ? base64_encode($content) : $content;

        $evicted = [];

        try {
            $this->pdo->beginTransaction();

            // Sweep first, inside the same transaction as the cap check, so
            // expired clips are physically gone before anything is counted.
            $this->sweepExpired($username, $now);

            $existing = $this->findByHash($username, $sha);
            if ($existing !== null) {
                $this->refreshOnReuse($username, (string) $existing['id'], $expiresAt, $label, $source, $now);
                $this->pdo->commit();
                return $this->status($username, (string) $existing['id'], $now) + [
                    'reused' => true,
                    'sha256' => $sha,
                    'ttlClamped' => $ttlClamped,
                    'evicted' => [],
                ];
            }

            $id = $this->freshId($username);
            $this->pdo->prepare(
                'INSERT INTO memory_clips
                    (id, username, label, source, content, bytes, sha256, encoding, pinned, access_count, created_at, last_access, expires_at)
                 VALUES
                    (:id, :username, :label, :source, :content, :bytes, :sha256, :encoding, :pinned, 1, :created_at, :last_access, :expires_at)'
            )->execute([
                ':id' => $id,
                ':username' => $username,
                ':label' => $label,
                ':source' => $source,
                ':content' => $stored,
                ':bytes' => strlen($stored),
                ':sha256' => $sha,
                ':encoding' => $encoding,
                ':pinned' => $pin ? 1 : 0,
                ':created_at' => $now,
                ':last_access' => $now,
                ':expires_at' => $expiresAt,
            ]);

            $maxEntries = $this->maxEntries();
            $live = $this->liveCount($username, $now);
            if ($live > $maxEntries) {
                $evicted = $this->evictExcess($username, $id, $live - $maxEntries, $now);
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBackQuietly();
            if (self::isBusy($e)) {
                return ['error' => 'clipboard is busy (SQLite lock on data/memory.sqlite); retry the call.'];
            }
            throw $e;
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }

        return $this->status($username, $id, $now) + [
            'reused' => false,
            'sha256' => $sha,
            'ttlClamped' => $ttlClamped,
            'evicted' => $evicted,
        ];
    }

    /** Flip a clip's pin flag. Pinned clips are exempt from eviction, not from deletion. */
    public function setPinned(string $username, string $id, bool $pinned): array {
        $now = self::now();
        $row = $this->findRow($username, $id, false);
        if ($row === null || self::isExpired($row, $now)) {
            return ['error' => $this->resolveGone($username, $id, $row, $now)];
        }

        $this->pdo->prepare(
            'UPDATE memory_clips
             SET pinned = :pinned, last_access = :now, access_count = access_count + 1
             WHERE username = :username AND id = :id'
        )->execute([
            ':pinned' => $pinned ? 1 : 0,
            ':now' => $now,
            ':username' => $username,
            ':id' => $id,
        ]);

        return ['id' => $id, 'pinned' => $pinned, 'label' => (string) $row['label']];
    }

    /** Delete a clip and record a tombstone. Idempotent: a miss is a warning, not an error. */
    public function delete(string $username, string $id): array {
        $now = self::now();
        $row = $this->findRow($username, $id, false);
        if ($row === null) {
            return ['deleted' => false, 'warning' => "clip $id is not in this account's clipboard."];
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare('DELETE FROM memory_clips WHERE username = :username AND id = :id')
                ->execute([':username' => $username, ':id' => $id]);
            $this->recordTombstone($username, $id, self::REASON_DELETED, $row, '', $now);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBackQuietly();
            if (self::isBusy($e)) {
                return ['deleted' => false, 'warning' => 'clipboard is busy (SQLite lock); retry the call.'];
            }
            throw $e;
        }

        return [
            'deleted' => true,
            'id' => $id,
            'label' => (string) $row['label'],
            'bytes' => (int) $row['bytes'],
        ];
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Metadata for every clip this user holds — id, label, source, size,
     * timestamps, pin state, access count — and never the content, so a full
     * clipboard costs a couple of KB rather than a couple of MB.
     *
     * Deliberately does NOT touch last_access. Listing is a table-of-contents
     * read, and refreshing all ten rows would stamp them with one shared
     * instant, collapsing the LRU order for exactly the caller that lists
     * most. This is intentional, not an oversight.
     *
     * @return array<string, mixed>
     */
    public function listClips(string $username): array {
        $now = self::now();
        $this->sweep($username, $now);

        $stmt = $this->pdo->prepare(
            'SELECT id, label, source, bytes, encoding, pinned, access_count, created_at, last_access, expires_at
             FROM memory_clips
             WHERE username = :username AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY created_at, id'
        );
        $stmt->execute([':username' => $username, ':now' => $now]);

        $clips = [];
        foreach ($stmt->fetchAll() as $row) {
            $clips[] = $this->metadata($row);
        }

        $maxEntries = $this->maxEntries();
        return [
            'clips' => $clips,
            'total' => count($clips),
            'maxEntries' => $maxEntries,
            'pinnedCount' => count(array_filter($clips, static fn(array $c): bool => $c['pinned'] === true)),
            'overLimit' => count($clips) > $maxEntries,
        ];
    }

    /**
     * A byte window of one clip's content.
     *
     * `maxChars` is a byte budget despite the name: a byte cut is UTF-8-safe and
     * errs conservative for multi-byte text, and it matches the `bytes` metadata.
     * Reading — even a 300-byte peek — refreshes recency, because "least recently
     * used" means used: any other rule would let a cheap peek silently push a clip
     * toward eviction.
     *
     * @return array<string, mixed> metadata plus `content`, or {error}
     */
    public function get(
        string $username,
        string $id,
        int $offset = 0,
        int $maxChars = self::DEFAULT_MAX_CHARS,
    ): array {
        $now = self::now();
        $row = $this->findRow($username, $id, true);
        if ($row === null || self::isExpired($row, $now)) {
            return ['error' => $this->resolveGone($username, $id, $row, $now)];
        }

        $stored = (string) $row['content'];
        $total = strlen($stored);

        $offset = max(0, $offset);
        $hardMax = $this->hardMaxChars();
        $requested = $maxChars > 0 ? $maxChars : $this->defaultMaxChars();
        $clamped = $requested > $hardMax;
        $limit = min($requested, $hardMax);

        $window = self::utf8Fix(substr($stored, $offset, $limit), $offset);
        $returned = strlen($window);
        $truncated = $offset + $returned < $total;
        $this->touchRecency($username, $id, $now);

        return $this->metadata($row) + [
            'offset' => $offset,
            'maxChars' => $limit,
            'clamped' => $clamped,
            'returned' => $returned,
            'total' => $total,
            'truncated' => $truncated,
            'nextOffset' => $truncated ? $offset + $returned : null,
            'content' => $window,
        ];
    }

    /**
     * The full content of a clip, for tools that accept a `clip_id` instead of
     * inline data (see ingest_document). Refreshes recency — a clip being fed
     * into another tool is the definition of "in use".
     *
     * @return array{content: string}|array{error: string}
     */
    public function readContent(string $username, string $id): array {
        $now = self::now();
        $row = $this->findRow($username, $id, true);
        if ($row === null || self::isExpired($row, $now)) {
            return ['error' => $this->resolveGone($username, $id, $row, $now)];
        }

        $content = (string) $row['content'];
        if (($row['encoding'] ?? 'utf8') === 'base64') {
            $decoded = base64_decode($content, true);
            if (is_string($decoded)) {
                $content = $decoded;
            }
        }

        $this->touchRecency($username, $id, $now);
        return ['content' => $content];
    }

    // -----------------------------------------------------------------------
    // Receipts
    // -----------------------------------------------------------------------

    /**
     * The receipt block that replaces an offloaded tool result.
     *
     * Unlike the explicit `clipboard action=put` reply, this one carries a
     * preview: the caller has never seen these bytes, and without a preview it
     * would have to spend a second round-trip before it could answer anything at
     * all. The reply names the exact next call, because a handle the caller does
     * not know how to use is worse than no handle.
     *
     * @param array<string, mixed> $stored the array returned by put()
     * @return array<string, string>|null null when the receipt cannot be encoded
     */
    public function offloadReceipt(string $toolName, array $stored, string $text, int $threshold): ?array {
        $bytes = strlen($text);
        $budget = $this->previewBudget();
        $expiry = ($stored['expiresAt'] ?? null) === null
            ? 'never expires'
            : 'expires ' . $stored['expiresAt'];

        $lines = [
            '[offloaded] ' . $toolName . ' produced ' . number_format($bytes) . ' bytes of text, above the '
                . number_format($threshold) . '-byte inline limit for this tool.',
            'Stored as clip ' . $stored['id'] . ' — source ' . $toolName . ', ' . $expiry . '.',
            'The preview below is partial. Read the whole clip with: clipboard action=get id=' . $stored['id']
                . '  (' . number_format($this->defaultMaxChars()) . ' bytes per call by default; page with offset,'
                . ' or raise max_chars up to ' . number_format($this->hardMaxChars()) . ')',
        ];

        if ($budget > 0) {
            $half = intdiv($budget, 2);
            $lines[] = 'Preview (first ' . number_format(min($half, $bytes)) . ' + last '
                . number_format(min($half, max(0, $bytes - $half))) . ' of ' . number_format($bytes) . ' bytes):';
            $lines[] = '-----8<-----';
            $lines[] = self::preview($text, $budget);
            $lines[] = '----->8-----';
        }

        $block = ['type' => 'text', 'text' => implode("\n", $lines)];
        return json_encode($block) === false ? null : $block;
    }

    /**
     * A UTF-8-safe head+tail preview of at most $budget bytes.
     *
     * Both ends matter. Tool output concentrates meaning at the start (JSON
     * metadata, the first records) and at the end (totals, closing braces) —
     * read_graph, for instance, emits its whole pagination contract before the
     * entities. A head-only preview would systematically hide the single most
     * information-dense line of many results, at identical token cost.
     */
    public static function preview(string $text, int $budget = self::DEFAULT_PREVIEW): string {
        if ($budget <= 0) {
            return '';
        }
        $bytes = strlen($text);
        if ($bytes <= $budget) {
            return self::utf8Head($text, $bytes);
        }

        $half = intdiv($budget, 2);
        $head = self::utf8Head($text, $half);
        $tail = self::utf8Tail($text, $half);
        $omitted = $bytes - strlen($head) - strlen($tail);

        return $head . "\n[... " . number_format($omitted) . " bytes omitted ...]\n" . $tail;
    }

    // -----------------------------------------------------------------------
    // Expiry, eviction and tombstones
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> the expired rows that were removed */
    private function sweepExpired(string $username, int $now): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, label, source, bytes FROM memory_clips
             WHERE username = :username AND expires_at IS NOT NULL AND expires_at <= :now'
        );
        $stmt->execute([':username' => $username, ':now' => $now]);
        $expired = $stmt->fetchAll();
        if ($expired === []) {
            return [];
        }

        $del = $this->pdo->prepare('DELETE FROM memory_clips WHERE username = :username AND id = :id');
        foreach ($expired as $row) {
            $del->execute([':username' => $username, ':id' => $row['id']]);
            $this->recordTombstone($username, (string) $row['id'], self::REASON_EXPIRED, $row, '', $now);
        }
        return $expired;
    }

    /** Run the lazy TTL sweep in its own transaction (the read paths' entry point). */
    private function sweep(string $username, int $now): void {
        try {
            $this->pdo->beginTransaction();
            $this->sweepExpired($username, $now);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBackQuietly();
            if (!self::isBusy($e)) {
                throw $e;
            }
            // A sweep that loses a lock race is harmless: the next call retries.
        }
    }

    /**
     * Delete up to $count least-recently-used unpinned clips (never $keepId),
     * recording a tombstone for each.
     *
     * The `rowid` tiebreak is load-bearing, not decorative: a turn that writes
     * several clips can land two or more on the same `last_access` microsecond,
     * so the tiebreak is a normal path rather than a rare one. It must be
     * `rowid` (insertion order) and not `id`: clip ids are random, so ordering
     * by them would make eviction effectively arbitrary within a burst.
     *
     * @return array<int, array<string, mixed>> the evicted rows, with lastAccess formatted
     */
    private function evictExcess(string $username, string $keepId, int $count, int $now): array {
        if ($count <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, label, source, bytes, last_access, created_at FROM memory_clips
             WHERE username = :username AND pinned = 0 AND id != :keep_id
             ORDER BY last_access, rowid
             LIMIT :n'
        );
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':keep_id', $keepId);
        $stmt->bindValue(':n', $count, PDO::PARAM_INT);
        $stmt->execute();
        $victims = $stmt->fetchAll();

        $evicted = [];
        $del = $this->pdo->prepare('DELETE FROM memory_clips WHERE username = :username AND id = :id');
        foreach ($victims as $victim) {
            $del->execute([':username' => $username, ':id' => $victim['id']]);
            $this->recordTombstone($username, (string) $victim['id'], self::REASON_EVICTED, $victim, $keepId, $now);
            $victim['lastAccess'] = $this->formatTime($victim['last_access']);
            $evicted[] = $victim;
        }
        return $evicted;
    }

    /** @param array<string, mixed> $row */
    private function recordTombstone(string $username, string $id, string $reason, array $row, string $evictedBy, int $now): void {
        $this->pdo->prepare(
            'INSERT INTO memory_clip_tombstones (id, username, reason, label, source, bytes, gone_at, evicted_by)
             VALUES (:id, :username, :reason, :label, :source, :bytes, :gone_at, :evicted_by)
             ON CONFLICT (username, id) DO UPDATE SET
                 reason = excluded.reason,
                 label = excluded.label,
                 source = excluded.source,
                 bytes = excluded.bytes,
                 gone_at = excluded.gone_at,
                 evicted_by = excluded.evicted_by'
        )->execute([
            ':id' => $id,
            ':username' => $username,
            ':reason' => $reason,
            ':label' => (string) ($row['label'] ?? ''),
            ':source' => (string) ($row['source'] ?? ''),
            ':bytes' => (int) ($row['bytes'] ?? 0),
            ':gone_at' => $now,
            ':evicted_by' => $evictedBy,
        ]);

        $this->trimTombstones($username);
    }

    /** Keep only the newest MAX_TOMBSTONES tombstones for this user. */
    private function trimTombstones(string $username): void {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM memory_clip_tombstones WHERE username = :username ORDER BY gone_at DESC, id DESC'
        );
        $stmt->execute([':username' => $username]);
        $excess = array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), self::MAX_TOMBSTONES);
        if ($excess === []) {
            return;
        }

        $del = $this->pdo->prepare('DELETE FROM memory_clip_tombstones WHERE username = :username AND id = :id');
        foreach ($excess as $id) {
            $del->execute([':username' => $username, ':id' => $id]);
        }
    }

    // -----------------------------------------------------------------------
    // Row access
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function findRow(string $username, string $id, bool $withContent): ?array {
        $columns = 'id, label, source, bytes, sha256, encoding, pinned, access_count, created_at, last_access, expires_at';
        if ($withContent) {
            $columns = 'id, label, source, content, bytes, sha256, encoding, pinned, access_count, created_at, last_access, expires_at';
        }
        $stmt = $this->pdo->prepare("SELECT $columns FROM memory_clips WHERE username = :username AND id = :id LIMIT 1");
        $stmt->execute([':username' => $username, ':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function findByHash(string $username, string $sha): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, label, source, bytes, sha256, encoding, pinned, access_count, created_at, last_access, expires_at
             FROM memory_clips WHERE username = :username AND sha256 = :sha
             ORDER BY last_access DESC LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':sha' => $sha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function liveCount(string $username, int $now): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memory_clips
             WHERE username = :username AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':username' => $username, ':now' => $now]);
        return (int) $stmt->fetchColumn();
    }

    /** An unused clip id. */
    private function freshId(string $username): string {
        $stmt = $this->pdo->prepare('SELECT 1 FROM memory_clips WHERE username = :username AND id = :id');
        do {
            $id = bin2hex(random_bytes(self::ID_BYTES));
            $stmt->execute([':username' => $username, ':id' => $id]);
        } while ($stmt->fetchColumn() !== false);
        return $id;
    }

    /** Refresh recency only, leaving label, source and expiry alone. */
    private function touchRecency(string $username, string $id, int $now): void {
        $this->pdo->prepare(
            'UPDATE memory_clips SET last_access = :now, access_count = access_count + 1
             WHERE username = :username AND id = :id'
        )->execute([':now' => $now, ':username' => $username, ':id' => $id]);
    }

    /**
     * Refresh recency and expiry for a reused clip, adopting a fresh non-empty
     * label/source (later intent wins) but keeping the original created_at.
     */
    private function refreshOnReuse(
        string $username,
        string $id,
        ?int $expiresAt,
        string $label,
        string $source,
        int $now,
    ): void {
        $row = $this->findRow($username, $id, false);
        if ($row === null) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE memory_clips
             SET last_access = :now, access_count = access_count + 1, expires_at = :expires_at,
                 label = :label, source = :source
             WHERE username = :username AND id = :id'
        )->execute([
            ':now' => $now,
            ':expires_at' => $expiresAt,
            ':label' => $label !== '' ? $label : (string) $row['label'],
            ':source' => $source !== '' ? $source : (string) $row['source'],
            ':username' => $username,
            ':id' => $id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Shaping
    // -----------------------------------------------------------------------

    /**
     * The clip's metadata plus its current clipboard occupancy — everything a
     * receipt needs, and never the content.
     *
     * @return array<string, mixed>
     */
    private function status(string $username, string $id, int $now): array {
        $row = $this->findRow($username, $id, false);
        $maxEntries = $this->maxEntries();
        $live = $this->liveCount($username, $now);
        $expiresAt = $row['expires_at'] ?? null;

        return [
            'id' => $id,
            'label' => (string) ($row['label'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'encoding' => (string) ($row['encoding'] ?? 'utf8'),
            'createdAt' => $this->formatTime($row['created_at'] ?? null),
            'expiresAt' => $this->formatTime($expiresAt),
            'expiry' => $expiresAt === null
                ? 'This clip never expires.'
                : 'This clip expires ' . $this->formatTime($expiresAt) . '.',
            'live' => $live,
            'maxEntries' => $maxEntries,
            'pinnedCount' => $this->pinnedCount($username, $now),
            'overLimit' => $live > $maxEntries,
        ];
    }

    private function pinnedCount(string $username, int $now): int {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memory_clips
             WHERE username = :username AND pinned = 1 AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':username' => $username, ':now' => $now]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metadata(array $row): array {
        return [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'source' => (string) $row['source'],
            'bytes' => (int) $row['bytes'],
            'encoding' => (string) $row['encoding'],
            'pinned' => ((int) $row['pinned']) === 1,
            'accessCount' => (int) $row['access_count'],
            'createdAt' => $this->formatTime($row['created_at']),
            'lastAccess' => $this->formatTime($row['last_access']),
            'expiresAt' => $this->formatTime($row['expires_at']),
        ];
    }

    /**
     * Why a clip id resolves to nothing: deleted, evicted, expired, held by
     * another account, or never stored here.
     *
     * The distinction is the point. A caller told only "not found" for a clip it
     * did hold will try to reconstruct the data; told "evicted — re-run the tool
     * that produced it", it recovers. The final branch is deliberately honest
     * about its own uncertainty: a tombstone can itself age out of the ring, so
     * it must not claim the id was never seen.
     *
     * @param array<string, mixed>|null $row the live-row miss, when there was one
     */
    private function resolveGone(string $username, string $id, ?array $row, int $now): string {
        if ($row !== null) {
            return "Clip $id expired at " . (string) $this->formatTime($row['expires_at']) . ' (its TTL elapsed) and was swept.';
        }

        $stmt = $this->pdo->prepare(
            'SELECT reason, label, source, bytes, gone_at, evicted_by FROM memory_clip_tombstones
             WHERE username = :username AND id = :id LIMIT 1'
        );
        $stmt->execute([':username' => $username, ':id' => $id]);
        $tomb = $stmt->fetch();

        if ($tomb !== false) {
            $when = (string) $this->formatTime($tomb['gone_at']);
            $what = $this->describe($tomb['label'] ?? '', $tomb['source'] ?? '', (int) ($tomb['bytes'] ?? 0));

            return match ((string) $tomb['reason']) {
                self::REASON_DELETED => "Clip $id was deleted (clipboard action=delete) at $when. $what Its content is gone.",
                self::REASON_EVICTED => "Clip $id was evicted at $when by a later put (of clip {$tomb['evicted_by']}). $what "
                    . 'The clipboard keeps the ' . $this->maxEntries() . ' least-recently-used unpinned clips; this one was the least recently read'
                    . ' — re-run the tool that produced it if you still need it.',
                default => "Clip $id expired at $when (its TTL elapsed) and was swept.",
            };
        }

        if ($this->revealForeignIds() && $this->existsForOtherUser($id, $username)) {
            return "Clip $id is not in this account's clipboard — it exists under a different account. Use the id with the account that created it.";
        }

        return "No such clip: $id. It was never stored under this account, or it was evicted long enough ago that its record has been dropped "
            . '(the clipboard remembers the last ' . self::MAX_TOMBSTONES . ' gone clips per user).';
    }

    /** Describe a departed clip for a tombstone message: label, size, provenance. */
    private function describe(mixed $label, mixed $source, int $bytes): string {
        $parts = [];
        $label = (string) $label;
        if ($label !== '') {
            $parts[] = '"' . $label . '"';
        }
        $parts[] = number_format($bytes) . ' bytes';
        $source = (string) $source;
        if ($source !== '') {
            $parts[] = 'from ' . $source;
        }
        return 'It held ' . implode(', ', $parts) . '.';
    }

    /** Cheap existence probe restricted to *other* users, for the foreign-id hint. */
    private function existsForOtherUser(string $id, string $username): bool {
        foreach (['memory_clips', 'memory_clip_tombstones'] as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM $table WHERE id = :id AND username != :username");
            $stmt->execute([':id' => $id, ':username' => $username]);
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------------

    /**
     * The current time in microseconds.
     *
     * Timestamps are microsecond-resolution rather than second-resolution, and
     * that is a correctness requirement, not a flourish: LRU breaks ties by
     * recency, and a single turn routinely writes several clips *and* reads one
     * back inside the same second. At second granularity a read cannot move its
     * clip in front of a sibling written moments earlier, so "least recently
     * used" silently degrades to plain insertion order for exactly the burst of
     * activity this whole feature exists to manage.
     */
    private static function now(): int {
        return (int) round(microtime(true) * self::MICROS);
    }

    /** @param array<string, mixed> $row */
    private static function isExpired(array $row, int $now): bool {
        $expires = $row['expires_at'] ?? null;
        return $expires !== null && $expires !== '' && (int) $expires <= $now;
    }

    /** True when a PDO failure is SQLite lock contention (retryable), not a real fault. */
    private static function isBusy(PDOException $e): bool {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'locked') || str_contains($message, 'busy');
    }

    private function rollBackQuietly(): void {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Nothing useful to do — the original error is what matters.
        }
    }

    /**
     * A UTF-8-safe head of at most $bytes bytes, dropping an incomplete trailing
     * character. Slicing mid-sequence would make json_encode() return false and
     * corrupt the whole response, not just the block.
     *
     * The trailing character is complete exactly when the bytes kept from its
     * lead byte onward cover the length that lead byte declares. Note that
     * "drop trailing bytes until the last one is not a continuation byte" would
     * be wrong: it also destroys a perfectly complete multi-byte character whose
     * final byte happens to sit at the cut.
     */
    private static function utf8Head(string $s, int $bytes): string {
        if ($bytes <= 0) {
            return '';
        }
        $head = substr($s, 0, $bytes);

        $i = strlen($head) - 1;
        $continuations = 0;
        while ($i >= 0 && (ord($head[$i]) & 0xC0) === 0x80 && $continuations < 3) {
            $i--;
            $continuations++;
        }
        if ($i < 0) {
            return '';
        }

        $lead = ord($head[$i]);
        $needed = match (true) {
            $lead < 0x80 => 1,
            ($lead & 0xE0) === 0xC0 => 2,
            ($lead & 0xF0) === 0xE0 => 3,
            ($lead & 0xF8) === 0xF0 => 4,
            default => 1,
        };

        return ($continuations + 1) >= $needed ? $head : substr($head, 0, $i);
    }

    /**
     * A UTF-8-safe tail of at most $bytes bytes. A suffix of a string can only
     * lose the lead byte of its first character, so dropping leading
     * continuation bytes is sufficient — everything after them is complete.
     */
    private static function utf8Tail(string $s, int $bytes): string {
        if ($bytes <= 0) {
            return '';
        }
        $t = substr($s, -$bytes);
        while ($t !== '' && (ord($t[0]) & 0xC0) === 0x80) {
            $t = substr($t, 1);
        }
        return $t;
    }

    /**
     * Repair the edges of a byte-offset slice that landed inside a multi-byte
     * sequence: drop a leading fragment only when the caller skipped into the
     * middle of a character, then drop an incomplete trailing one.
     */
    private static function utf8Fix(string $slice, int $offset): string {
        if ($slice === '') {
            return '';
        }
        if ($offset > 0) {
            while ($slice !== '' && (ord($slice[0]) & 0xC0) === 0x80) {
                $slice = substr($slice, 1);
            }
        }
        return self::utf8Head($slice, strlen($slice));
    }

    /** Render a microsecond timestamp as an ISO-8601 string, or null when absent. */
    public function formatTime(mixed $ts): ?string {
        if ($ts === null || $ts === '') {
            return null;
        }
        return gmdate('c', intdiv((int) $ts, self::MICROS));
    }
}
