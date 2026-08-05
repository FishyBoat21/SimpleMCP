<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;
use SplQueue;

/**
 * SQLite-backed knowledge graph backing the memory MCP tool.
 *
 * The graph lives in its own database file (data/memory.sqlite) — separate
 * from the auth database — and is created idempotently on first use.
 *
 * Every row is scoped to a username, so each user gets an isolated subgraph:
 * memory created under one account is never listed, linked, or mutated by
 * another. In stdio mode the injected user is the trusted `local` user.
 *
 * Temporal model: every fact carries a transaction timeline (created_at /
 * updated_at — when the fact was recorded) and a validity timeline
 * (valid_from / valid_to — when the fact holds). A NULL valid_to means the
 * fact is currently valid; invalidating marks valid_to instead of deleting,
 * so historical state stays queryable via `as_of`.
 *
 * Entities are additionally mirrored into an FTS5 table (memory_entities_fts)
 * for zero-dependency BM25 keyword search, and search_graph fuses that with a
 * character n-gram similarity pass (a dependency-free stand-in for embeddings)
 * and breadth-first graph traversal using Reciprocal Rank Fusion.
 */
final class MemoryStore {
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
            CREATE TABLE IF NOT EXISTS memory_entities (
                id           TEXT NOT NULL,
                username     TEXT NOT NULL,
                name         TEXT NOT NULL,
                entity_type  TEXT NOT NULL DEFAULT '',
                observations TEXT NOT NULL DEFAULT '[]',
                created_at   INTEGER NOT NULL,
                updated_at   INTEGER NOT NULL,
                valid_from   INTEGER,
                valid_to     INTEGER,
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_entities_username ON memory_entities(username);

            CREATE TABLE IF NOT EXISTS memory_relations (
                username      TEXT NOT NULL,
                from_entity   TEXT NOT NULL,
                to_entity     TEXT NOT NULL,
                relation_type TEXT NOT NULL,
                created_at    INTEGER NOT NULL,
                updated_at    INTEGER,
                valid_from    INTEGER,
                valid_to      INTEGER,
                PRIMARY KEY (username, from_entity, to_entity, relation_type)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_relations_username ON memory_relations(username);
            SQL);

        $this->migrateColumns();
        $this->createFtsTable();
    }

    /**
     * Add columns that post-date the originally shipped schema, for databases
     * created before the temporal/FTS model existed. Runs as part of the
     * idempotent bootstrap, so it is a no-op on fresh databases.
     */
    private function migrateColumns(): void {
        $entityCols = array_column($this->pdo->query('PRAGMA table_info(memory_entities)')->fetchAll(), 'name');
        foreach (['valid_from', 'valid_to'] as $column) {
            if (!in_array($column, $entityCols, true)) {
                $this->pdo->exec("ALTER TABLE memory_entities ADD COLUMN $column INTEGER");
            }
        }

        $relationCols = array_column($this->pdo->query('PRAGMA table_info(memory_relations)')->fetchAll(), 'name');
        foreach (['updated_at', 'valid_from', 'valid_to'] as $column) {
            if (!in_array($column, $relationCols, true)) {
                $this->pdo->exec("ALTER TABLE memory_relations ADD COLUMN $column INTEGER");
            }
        }

        // Rows written before these columns existed are valid from creation onward.
        $this->pdo->exec('UPDATE memory_entities SET valid_from = created_at WHERE valid_from IS NULL');
        $this->pdo->exec('UPDATE memory_relations SET valid_from = created_at WHERE valid_from IS NULL');
        $this->pdo->exec('UPDATE memory_relations SET updated_at = created_at WHERE updated_at IS NULL');
    }

    private function createFtsTable(): void {
        $this->pdo->exec(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS memory_entities_fts USING fts5(
                username     UNINDEXED,
                entity_id    UNINDEXED,
                name,
                entity_type,
                observations
            );
            SQL);

        // A database created before the FTS index existed has rows but no
        // index entries: rebuild once so keyword search covers pre-existing data.
        $indexed = (int) $this->pdo->query('SELECT COUNT(*) FROM memory_entities_fts')->fetchColumn();
        $entities = (int) $this->pdo->query('SELECT COUNT(*) FROM memory_entities')->fetchColumn();
        if ($indexed === 0 && $entities > 0) {
            $this->rebuildFts();
        }
    }

    private function rebuildFts(): void {
        $rows = $this->pdo->query('SELECT username, id FROM memory_entities')->fetchAll();
        foreach ($rows as $row) {
            $this->syncFtsRow($row['username'], $row['id']);
        }
    }

    /**
     * Insert or update entities, upserting on (username, id). Re-creating an
     * existing id replaces name, type, and observations entirely and makes the
     * entity valid again.
     *
     * @param string $username owner of the new entities
     * @param array<int, array<string, mixed>> $entities
     * @return string[] ids that were created or updated
     */
    public function createEntities(string $username, array $entities): array {
        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO memory_entities (id, username, name, entity_type, observations, created_at, updated_at, valid_from)
             VALUES (:id, :username, :name, :entity_type, :observations, :created_at, :updated_at, :valid_from)
             ON CONFLICT (username, id) DO UPDATE SET
                 name = excluded.name,
                 entity_type = excluded.entity_type,
                 observations = excluded.observations,
                 valid_from = excluded.valid_from,
                 valid_to = NULL,
                 updated_at = excluded.updated_at'
        );

        $ids = [];
        foreach ($entities as $entity) {
            $id = (string) ($entity['id'] ?? '');
            $name = (string) ($entity['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }

            $validFrom = $this->toTimestamp($entity['validFrom'] ?? null) ?? $now;
            $stmt->execute([
                ':id' => $id,
                ':username' => $username,
                ':name' => $name,
                ':entity_type' => (string) ($entity['entityType'] ?? ''),
                ':observations' => json_encode($this->stringList($entity['observations'] ?? []), JSON_UNESCAPED_SLASHES),
                ':created_at' => $now,
                ':updated_at' => $now,
                ':valid_from' => $validFrom,
            ]);
            $this->syncFtsRow($username, $id);
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Create directed relations between existing entities. An active duplicate
     * (same from/to/type) is left untouched; an invalidated one is re-activated.
     *
     * @param string $username owner of the graph
     * @param array<int, array<string, mixed>> $relations
     * @return array{relations: string[], errors: string[]}
     */
    public function createRelations(string $username, array $relations): array {
        $known = [];
        foreach ($this->fetchEntities($username) as $entity) {
            $known[$entity['id']] = true;
        }

        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO memory_relations (username, from_entity, to_entity, relation_type, created_at, updated_at, valid_from)
             VALUES (:username, :from, :to, :relation_type, :created_at, :updated_at, :valid_from)'
        );
        $lookup = $this->pdo->prepare(
            'SELECT valid_to FROM memory_relations
             WHERE username = :username AND from_entity = :from AND to_entity = :to AND relation_type = :relation_type'
        );
        $reactivate = $this->pdo->prepare(
            'UPDATE memory_relations
             SET valid_to = NULL, valid_from = :valid_from, updated_at = :updated_at
             WHERE username = :username AND from_entity = :from AND to_entity = :to AND relation_type = :relation_type'
        );

        $created = [];
        $errors = [];
        foreach ($relations as $relation) {
            $from = (string) ($relation['from'] ?? '');
            $to = (string) ($relation['to'] ?? '');
            $type = (string) ($relation['relationType'] ?? '');

            if ($from === '' || $to === '' || $type === '') {
                $errors[] = 'Relation must include from, to and relationType: ' . json_encode($relation);
                continue;
            }
            if (!isset($known[$from], $known[$to])) {
                $errors[] = "Cannot link '$from' to '$to': one of the entities does not exist in this user's graph.";
                continue;
            }

            $validFrom = $this->toTimestamp($relation['validFrom'] ?? null) ?? $now;

            $lookup->execute([':username' => $username, ':from' => $from, ':to' => $to, ':relation_type' => $type]);
            $existing = $lookup->fetch();
            if ($existing !== false) {
                if ($existing['valid_to'] === null) {
                    continue; // duplicate of an active relation — ignored, as before
                }
                $reactivate->execute([
                    ':valid_from' => $validFrom,
                    ':updated_at' => $now,
                    ':username' => $username,
                    ':from' => $from,
                    ':to' => $to,
                    ':relation_type' => $type,
                ]);
                $created[] = "$from -> $to ($type) (re-activated)";
                continue;
            }

            $stmt->execute([
                ':username' => $username,
                ':from' => $from,
                ':to' => $to,
                ':relation_type' => $type,
                ':created_at' => $now,
                ':updated_at' => $now,
                ':valid_from' => $validFrom,
            ]);
            $created[] = "$from -> $to ($type)";
        }
        return ['relations' => $created, 'errors' => $errors];
    }

    /**
     * Append observations to existing entities. The entity is matched by name
     * first, then by id; duplicate observations are deduplicated.
     *
     * @param string $username owner of the graph
     * @param array<int, array<string, mixed>> $observations
     * @return array{updates: array<string, array{added: int, total: int}>, errors: string[]}
     */
    public function addObservations(string $username, array $observations): array {
        $updates = [];
        $errors = [];
        foreach ($observations as $observation) {
            $entityName = (string) ($observation['entityName'] ?? '');
            $contents = $this->stringList($observation['contents'] ?? []);
            if ($entityName === '' || $contents === []) {
                $errors[] = 'Observation must include entityName and non-empty contents: ' . json_encode($observation);
                continue;
            }

            $entity = $this->findEntity($username, $entityName);
            if ($entity === null) {
                $errors[] = "Entity '$entityName' not found in this user's graph.";
                continue;
            }

            $existing = json_decode((string) ($entity['observations'] ?? '[]'), true);
            if (!is_array($existing)) {
                $existing = [];
            }
            $merged = array_values(array_unique([...$existing, ...$contents]));
            $this->updateObservations($username, $entity['id'], $merged);
            $updates[$entityName] = ['added' => count($contents), 'total' => count($merged)];
        }
        return ['updates' => $updates, 'errors' => $errors];
    }

    /**
     * Delete entities by id (or name, matched like addObservations — name
     * first, then id). Any relation that references a deleted entity is removed
     * too, so the graph never keeps dangling links.
     *
     * @param string $username owner of the graph
     * @param string[] $identifiers ids or names of the entities to delete
     * @return array{deleted: string[], relationsRemoved: int, errors: string[]}
     */
    public function deleteEntities(string $username, array $identifiers): array {
        $deleted = [];
        $errors = [];
        $relationsRemoved = 0;

        foreach ($identifiers as $identifier) {
            $entity = $this->findEntity($username, $identifier);
            if ($entity === null) {
                $errors[] = "Entity '$identifier' not found in this user's graph.";
                continue;
            }

            $id = $entity['id'];
            if (in_array($id, $deleted, true)) {
                continue; // the same entity was referenced more than once
            }

            // Cascade: drop relations pointing to or from this entity.
            $stmt = $this->pdo->prepare(
                'DELETE FROM memory_relations WHERE username = :username AND (from_entity = :id OR to_entity = :id)'
            );
            $stmt->execute([':username' => $username, ':id' => $id]);
            $relationsRemoved += $stmt->rowCount();

            // Drop the FTS mirror before the source row, then the source row.
            $this->pdo->prepare('DELETE FROM memory_entities_fts WHERE username = :username AND entity_id = :id')
                ->execute([':username' => $username, ':id' => $id]);
            $this->pdo->prepare('DELETE FROM memory_entities WHERE username = :username AND id = :id')
                ->execute([':username' => $username, ':id' => $id]);
            $deleted[] = $id;
        }

        return ['deleted' => $deleted, 'relationsRemoved' => $relationsRemoved, 'errors' => $errors];
    }

    /**
     * Delete directed relations matching the given from/to/relationType specs.
     * All three fields are required for each spec, mirroring createRelations.
     *
     * @param string $username owner of the graph
     * @param array<int, array<string, string>> $relations
     * @return array{deleted: string[], errors: string[]}
     */
    public function deleteRelations(string $username, array $relations): array {
        $deleted = [];
        $errors = [];
        $stmt = $this->pdo->prepare(
            'DELETE FROM memory_relations
             WHERE username = :username AND from_entity = :from AND to_entity = :to AND relation_type = :relation_type'
        );

        foreach ($relations as $relation) {
            $from = (string) ($relation['from'] ?? '');
            $to = (string) ($relation['to'] ?? '');
            $type = (string) ($relation['relationType'] ?? '');

            if ($from === '' || $to === '' || $type === '') {
                $errors[] = 'Relation must include from, to and relationType: ' . json_encode($relation);
                continue;
            }

            $stmt->execute([
                ':username' => $username,
                ':from' => $from,
                ':to' => $to,
                ':relation_type' => $type,
            ]);
            if ($stmt->rowCount() > 0) {
                $deleted[] = "$from -> $to ($type)";
            } else {
                $errors[] = "Relation '$from -> $to ($type)' not found.";
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Read the graph for a user as of a point in time, in one of three modes.
     * By default only facts valid at `asOf` are returned; pass `includeInvalid`
     * to include historical state. `as_of` and `includeInvalid` apply to every
     * mode.
     *
     * Modes (precedence: entity > subgraph > index):
     *
     * - **entity** (`entityId` set): full detail for one entity, matched by
     *   name first then id — observations, timestamps, and every relation
     *   touching it. The on-demand counterpart to the index.
     * - **subgraph** (`root` set): the subgraph reachable from that entity
     *   through at most `depth` undirected relation hops (`depth` 0 = just the
     *   root), with full observations. Each entity carries its `distance` from
     *   the root, and only relations whose both endpoints are in the subgraph
     *   are returned.
     * - **index** (default): a compact, paginated table of contents — one entry
     *   per entity with id, name, type, and its relation count, but no
     *   observations and no relation list. Use `limit`/`offset` to page; load
     *   full observations on demand via the entity or subgraph modes.
     *
     * @param string $username owner of the graph
     * @param mixed $asOf Unix timestamp or ISO-8601 datetime (null = now)
     * @param bool $includeInvalid also return facts that have been invalidated
     * @param string|null $root optional root entity id or name to scope the read
     * @param int $depth max relation hops from the root (clamped 0..8)
     * @param string|null $entityId optional entity id or name to load in full
     * @param int $limit index mode: max index entries (clamped 1..1000)
     * @param int $offset index mode: index entries to skip
     * @param bool $includeObservations subgraph mode: also return each entity's
     *        observations. Off by default so routine subgraph reads stay compact —
     *        load observations on demand via the entity mode or search_graph
     *        instead.
     * @return array<string, mixed>
     */
    public function readGraph(
        string $username,
        mixed $asOf = null,
        bool $includeInvalid = false,
        ?string $root = null,
        int $depth = 0,
        ?string $entityId = null,
        int $limit = 100,
        int $offset = 0,
        bool $includeObservations = false,
    ): array {
        $t = $this->toTimestamp($asOf) ?? time();

        if ($entityId !== null && $entityId !== '') {
            return $this->readEntity($username, $entityId, $t, $includeInvalid);
        }

        if ($root !== null && $root !== '') {
            return $this->readSubgraph($username, $root, $depth, $t, $includeInvalid, $includeObservations);
        }

        return $this->readIndex($username, $t, $includeInvalid, $limit, $offset);
    }

    /**
     * The subgraph around a root entity: the root plus every entity reachable
     * through at most `depth` undirected relation hops, and the relations among
     * them. Entities carry their BFS `distance` from the root. Traversal and the
     * root itself respect `includeInvalid` (validity ignored when set); a root
     * that is missing, or invalid at the snapshot when `includeInvalid` is off,
     * yields an `error` instead of a partial result.
     *
     * By default the entries are compact (id/name/type/distance/relationCount,
     * no observations) and relations are topology-only (from/to/type) so a
     * routine subgraph read of a large neighborhood stays small — mirroring the
     * index. Pass `$includeObservations` to include each entity's full
     * observations (and its creation/validity timestamps) and each relation's
     * timestamps when the caller really wants the detail.
     *
     * @return array<string, mixed>
     */
    private function readSubgraph(string $username, string $rootNameOrId, int $depth, int $t, bool $includeInvalid, bool $includeObservations): array {
        $depth = max(0, min(8, $depth));

        $root = $this->findEntityFull($username, $rootNameOrId);
        if ($root === null) {
            return [
                'mode' => 'subgraph',
                'root' => $rootNameOrId,
                'depth' => $depth,
                'entities' => [],
                'relations' => [],
                'error' => "Root entity '$rootNameOrId' not found in this user's graph.",
            ];
        }
        if (!$includeInvalid && !$this->isValidAt($root, $t)) {
            return [
                'mode' => 'subgraph',
                'root' => $rootNameOrId,
                'depth' => $depth,
                'entities' => [],
                'relations' => [],
                'error' => "Root entity '$rootNameOrId' is not valid at the requested time.",
            ];
        }

        $distances = $this->subgraphDistances($username, [$root['id']], $depth, $t, $includeInvalid);
        $ids = array_keys($distances);

        // The induced subgraph: relations whose both endpoints made the cut.
        // Built first so each entity's `relationCount` reflects the relations
        // actually returned (the same convention as the entity mode).
        $included = array_fill_keys($ids, true);
        $stmt = $this->pdo->prepare(
            'SELECT from_entity, to_entity, relation_type, created_at, updated_at, valid_from, valid_to
             FROM memory_relations
             WHERE username = :username
             ORDER BY created_at'
        );
        $stmt->execute([':username' => $username]);
        $relationCounts = array_fill_keys($ids, 0);
        $relations = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($included[$row['from_entity']], $included[$row['to_entity']])) {
                continue;
            }
            if (!$includeInvalid && !$this->isValidAt($row, $t)) {
                continue;
            }
            $relationCounts[$row['from_entity']]++;
            $relationCounts[$row['to_entity']]++;
            $relations[] = $includeObservations
                ? [
                    'from' => $row['from_entity'],
                    'to' => $row['to_entity'],
                    'relationType' => $row['relation_type'],
                    'createdAt' => $this->formatTime($row['created_at']),
                    'updatedAt' => $this->formatTime($row['updated_at']),
                    'validFrom' => $this->formatTime($row['valid_from']),
                    'validTo' => $this->formatTime($row['valid_to']),
                ]
                : [
                    'from' => $row['from_entity'],
                    'to' => $row['to_entity'],
                    'relationType' => $row['relation_type'],
                ];
        }

        $entities = [];
        foreach ($this->fullEntitiesById($username, $ids) as $entity) {
            $entry = [
                'id' => $entity['id'],
                'name' => $entity['name'],
                'entityType' => $entity['entity_type'],
                'distance' => $distances[$entity['id']],
                'relationCount' => $relationCounts[$entity['id']],
                'validFrom' => $this->formatTime($entity['valid_from']),
                'validTo' => $this->formatTime($entity['valid_to']),
            ];
            if ($includeObservations) {
                $entry['observations'] = $this->decodeObservations($entity['observations']);
                $entry['createdAt'] = $this->formatTime($entity['created_at']);
                $entry['updatedAt'] = $this->formatTime($entity['updated_at']);
            }
            $entities[] = $entry;
        }

        return [
            'mode' => 'subgraph',
            'root' => $rootNameOrId,
            'rootId' => $root['id'],
            'depth' => $depth,
            'entities' => $entities,
            'relations' => $relations,
        ];
    }

    /**
     * Full detail for a single entity (matched by name first, then id): its
     * observations, timestamps, and every relation touching it. This is the
     * on-demand counterpart to the compact index — the client reads the index
     * first, then drills into the entities it cares about.
     *
     * @return array<string, mixed>
     */
    private function readEntity(string $username, string $nameOrId, int $t, bool $includeInvalid): array {
        $entity = $this->findEntityFull($username, $nameOrId);
        if ($entity === null) {
            return [
                'mode' => 'entity',
                'error' => "Entity '$nameOrId' not found in this user's graph.",
            ];
        }
        if (!$includeInvalid && !$this->isValidAt($entity, $t)) {
            return [
                'mode' => 'entity',
                'error' => "Entity '$nameOrId' is not valid at the requested time.",
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT from_entity, to_entity, relation_type, created_at, updated_at, valid_from, valid_to
             FROM memory_relations
             WHERE username = :username AND (from_entity = :id OR to_entity = :id)
             ORDER BY created_at'
        );
        $stmt->execute([':username' => $username, ':id' => $entity['id']]);
        $relations = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$includeInvalid && !$this->isValidAt($row, $t)) {
                continue;
            }
            $relations[] = [
                'from' => $row['from_entity'],
                'to' => $row['to_entity'],
                'relationType' => $row['relation_type'],
                'createdAt' => $this->formatTime($row['created_at']),
                'updatedAt' => $this->formatTime($row['updated_at']),
                'validFrom' => $this->formatTime($row['valid_from']),
                'validTo' => $this->formatTime($row['valid_to']),
            ];
        }

        return [
            'mode' => 'entity',
            'entity' => [
                'id' => $entity['id'],
                'name' => $entity['name'],
                'entityType' => $entity['entity_type'],
                'observations' => $this->decodeObservations($entity['observations']),
                'relationCount' => count($relations),
                'createdAt' => $this->formatTime($entity['created_at']),
                'updatedAt' => $this->formatTime($entity['updated_at']),
                'validFrom' => $this->formatTime($entity['valid_from']),
                'validTo' => $this->formatTime($entity['valid_to']),
            ],
            'relations' => $relations,
        ];
    }

    /**
     * Compact, paginated index of the graph — the default read. One entry per
     * entity with id, name, type, and its relation count (the number of
     * relations touching it, in or out, within the same snapshot), but no
     * observations and no relation list. This is the cheap table of contents:
     * full observations are loaded on demand via readEntity (one entity) or
     * readSubgraph (a neighborhood).
     *
     * @return array<string, mixed>
     */
    private function readIndex(string $username, int $t, bool $includeInvalid, int $limit, int $offset): array {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $totalStmt = $this->pdo->prepare(
            $includeInvalid
                ? 'SELECT COUNT(*) FROM memory_entities WHERE username = :username'
                : 'SELECT COUNT(*) FROM memory_entities
                   WHERE username = :username AND valid_from <= :asof AND (valid_to IS NULL OR valid_to > :asof)'
        );
        $totalStmt->execute($includeInvalid
            ? [':username' => $username]
            : [':username' => $username, ':asof' => $t]);
        $total = (int) $totalStmt->fetchColumn();

        $sql = 'SELECT e.id, e.name, e.entity_type, e.valid_from, e.valid_to,
                       COUNT(DISTINCT r.rowid) AS relation_count
                FROM memory_entities e
                LEFT JOIN memory_relations r
                  ON r.username = e.username
                 AND (r.from_entity = e.id OR r.to_entity = e.id)'
            . ($includeInvalid
                ? ''
                : ' AND r.valid_from <= :asof AND (r.valid_to IS NULL OR r.valid_to > :asof)')
            . '
                WHERE e.username = :username'
            . ($includeInvalid
                ? ''
                : ' AND e.valid_from <= :asof AND (e.valid_to IS NULL OR e.valid_to > :asof)')
            . '
                GROUP BY e.id, e.name, e.entity_type, e.created_at, e.valid_from, e.valid_to
                ORDER BY e.created_at, e.id
                LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':username', $username);
        if (!$includeInvalid) {
            $stmt->bindValue(':asof', $t);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $entities = [];
        foreach ($stmt->fetchAll() as $row) {
            $entities[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'entityType' => $row['entity_type'],
                'relationCount' => (int) $row['relation_count'],
                'validFrom' => $this->formatTime($row['valid_from']),
                'validTo' => $this->formatTime($row['valid_to']),
            ];
        }

        return [
            'mode' => 'index',
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'entities' => $entities,
        ];
    }

    /**
     * Search the graph. Retrieval strategies:
     *   - keyword:  BM25 over the FTS5 index (exact tokens, prefix-tolerant)
     *   - semantic: character n-gram (Dice) similarity — a zero-dependency
     *               stand-in for embeddings; resilient to typos and CJK text
     *   - hybrid:   Reciprocal Rank Fusion of keyword + semantic + graph lists
     * When `hops` > 0, breadth-first traversal expands the candidates through
     * up to `hops` relation hops and is fused into the ranking. Only facts
     * valid at `asOf` are searchable.
     *
     * @param string $username owner of the graph
     * @param string $query search text
     * @param string $searchType keyword|semantic|hybrid
     * @param int $topK max results (clamped 1..100)
     * @param int $hops BFS depth (clamped 0..4)
     * @param mixed $asOf Unix timestamp or ISO-8601 datetime (null = now)
     * @return array<string, mixed>
     */
    public function searchGraph(
        string $username,
        string $query,
        string $searchType = 'keyword',
        int $topK = 10,
        int $hops = 1,
        mixed $asOf = null,
    ): array {
        $topK = max(1, min(100, $topK));
        $hops = max(0, min(4, $hops));
        $query = trim($query);

        if ($query === '') {
            return ['query' => $query, 'searchType' => $searchType, 'total' => 0, 'results' => []];
        }
        if (!in_array($searchType, ['keyword', 'semantic', 'hybrid'], true)) {
            $searchType = 'keyword';
        }
        $t = $this->toTimestamp($asOf) ?? time();

        $keywordIds = $this->keywordSearch($username, $query, $t);
        $semanticIds = $this->semanticSearch($username, $query, $t);

        $lists = [];
        if ($searchType === 'keyword' || $searchType === 'hybrid') {
            $lists['keyword'] = $keywordIds;
        }
        if ($searchType === 'semantic' || $searchType === 'hybrid') {
            $lists['semantic'] = $semanticIds;
        }
        if ($hops > 0) {
            $seeds = array_values(array_unique(array_merge($keywordIds, $semanticIds)));
            $graphIds = $this->bfsExpand($username, $seeds, $hops, $t);
            if ($graphIds !== []) {
                $lists['graph'] = $graphIds;
            }
        }

        $ranked = $this->rrf($lists);
        $resultIds = array_slice(array_keys($ranked), 0, $topK);
        $byId = $this->entitiesById($username, $resultIds);

        $results = [];
        foreach ($resultIds as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $row = $byId[$id];
            $results[] = [
                'id' => $id,
                'name' => $row['name'],
                'entityType' => $row['entity_type'],
                'observations' => $this->decodeObservations($row['observations']),
                'score' => round($ranked[$id], 4),
                'matchedOn' => $this->matchedOn($row, $query),
            ];
        }

        return [
            'query' => $query,
            'searchType' => $searchType,
            'topK' => $topK,
            'hops' => $hops,
            'total' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Mark an entity (matched by name, then id) as invalid from a point in
     * time, preserving history instead of deleting it. Any relation touching
     * the entity is invalidated at the same instant, so the graph stays
     * consistent at every `as_of` snapshot.
     *
     * @return array{invalidated: bool, error?: string, id?: string, validTo?: ?string, relationsInvalidated?: int}
     */
    public function invalidateEntity(string $username, string $identifier, mixed $invalidAt = null): array {
        $entity = $this->findEntity($username, $identifier);
        if ($entity === null) {
            return ['invalidated' => false, 'error' => "Entity '$identifier' not found in this user's graph."];
        }

        $t = $this->toTimestamp($invalidAt) ?? time();

        $rels = $this->pdo->prepare(
            'UPDATE memory_relations SET valid_to = :t, updated_at = :t
             WHERE username = :username AND (from_entity = :id OR to_entity = :id)'
        );
        $rels->execute([':t' => $t, ':username' => $username, ':id' => $entity['id']]);

        $this->pdo->prepare(
            'UPDATE memory_entities SET valid_to = :t, updated_at = :t WHERE username = :username AND id = :id'
        )->execute([':t' => $t, ':username' => $username, ':id' => $entity['id']]);

        return [
            'invalidated' => true,
            'id' => $entity['id'],
            'validTo' => $this->formatTime($t),
            'relationsInvalidated' => $rels->rowCount(),
        ];
    }

    /**
     * Mark a directed relation as invalid from a point in time, preserving
     * history instead of deleting it.
     *
     * @return array{invalidated: bool, error?: string, from?: string, to?: string, relationType?: string, validTo?: ?string}
     */
    public function invalidateRelation(string $username, string $from, string $to, string $relationType, mixed $invalidAt = null): array {
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM memory_relations
             WHERE username = :username AND from_entity = :from AND to_entity = :to AND relation_type = :relation_type'
        );
        $exists->execute([':username' => $username, ':from' => $from, ':to' => $to, ':relation_type' => $relationType]);
        if ($exists->fetch() === false) {
            return ['invalidated' => false, 'error' => "Relation '$from -> $to ($relationType)' not found."];
        }

        $t = $this->toTimestamp($invalidAt) ?? time();
        $this->pdo->prepare(
            'UPDATE memory_relations SET valid_to = :t, updated_at = :t
             WHERE username = :username AND from_entity = :from AND to_entity = :to AND relation_type = :relation_type'
        )->execute([':t' => $t, ':username' => $username, ':from' => $from, ':to' => $to, ':relation_type' => $relationType]);

        return [
            'invalidated' => true,
            'from' => $from,
            'to' => $to,
            'relationType' => $relationType,
            'validTo' => $this->formatTime($t),
        ];
    }

    /** @return string[] ids of valid entities matching the FTS5 query, best first */
    private function keywordSearch(string $username, string $query, int $asOf): array {
        $match = $this->ftsMatchQuery($query);
        if ($match === '') {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT entity_id AS id
             FROM memory_entities_fts
             WHERE username = :username AND memory_entities_fts MATCH :match
             ORDER BY rank DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':username', $username);
        $stmt->bindValue(':match', $match);
        $stmt->bindValue(':limit', 200, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_column($stmt->fetchAll(), 'id');

        // FTS knows nothing about validity: keep only entities still valid at the snapshot.
        $valid = $this->pdo->prepare(
            'SELECT id FROM memory_entities
             WHERE username = :username AND valid_from <= :asof AND (valid_to IS NULL OR valid_to > :asof)'
        );
        $valid->execute([':username' => $username, ':asof' => $asOf]);
        $validIds = array_fill_keys(array_column($valid->fetchAll(), 'id'), true);

        return array_values(array_filter($ids, static fn(string $id): bool => isset($validIds[$id])));
    }

    /**
     * @return string[] ids of valid entities by semantic similarity, best first
     *
     * A zero-dependency stand-in for embeddings: scores each entity with the
     * query coverage of shared character trigrams, weighted by inverse document
     * frequency across the user's own corpus. Weighting matters — generic
     * trigrams like "ing"/"thi" occur everywhere and would otherwise match any
     * entity at random, while distinctive grams (and every CJK character,
     * which is exactly one UTF-8 trigram) dominate the score. The 0.25 floor
     * drops trigram-coincidence noise (a nonsense query scores ~0.1) while
     * genuine fuzzy matches stay well above it.
     */
    private function semanticSearch(string $username, string $query, int $asOf): array {
        $qGrams = $this->nGramSet($query);
        if ($qGrams === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, name, entity_type, observations
             FROM memory_entities
             WHERE username = :username AND valid_from <= :asof AND (valid_to IS NULL OR valid_to > :asof)'
        );
        $stmt->execute([':username' => $username, ':asof' => $asOf]);

        // Document frequency per gram (an entity is a document). Each entity's
        // gram set is the union over its name, type, and observations, so a
        // match inside a short observation is not diluted by long text elsewhere.
        $docs = [];
        $df = [];
        foreach ($stmt->fetchAll() as $row) {
            $grams = $this->nGramSet((string) $row['name'])
                + $this->nGramSet((string) $row['entity_type']);
            foreach ($this->decodeObservations($row['observations']) as $observation) {
                $grams += $this->nGramSet($observation);
            }
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
        return array_map(static fn(array $row): string => $row['id'], $scored);
    }

    /**
     * Breadth-first traversal over valid relations from the seed entity ids,
     * collecting reachable valid entities up to `hops` hops away. Returns ids
     * ordered by distance then id so RRF weights nearer neighbors higher.
     *
     * @param string $username owner of the graph
     * @param string[] $seeds starting entity ids
     * @return string[] reachable entity ids
     */
    private function bfsExpand(string $username, array $seeds, int $hops, int $asOf): array {
        if ($seeds === [] || $hops <= 0) {
            return [];
        }

        $distance = $this->subgraphDistances($username, $seeds, $hops, $asOf, false);
        uksort($distance, function (string $a, string $b) use ($distance): int {
            return $distance[$a] <=> $distance[$b] ?: strcmp($a, $b);
        });
        return array_keys($distance);
    }

    /**
     * Distance of every valid entity reachable from the seeds via at most
     * `maxDepth` undirected relation hops (the seeds themselves are distance 0).
     * This is the shared BFS core behind both search_graph's `hops` expansion
     * and read_graph's scoped root/depth read. When `includeInvalid` is set,
     * validity is ignored so the traversal also reaches historical facts.
     *
     * @param string $username owner of the graph
     * @param string[] $seeds starting entity ids
     * @param int $maxDepth maximum hops from a seed (0 = seeds only)
     * @param int $asOf snapshot timestamp used when not including invalid facts
     * @param bool $includeInvalid traverse regardless of validity
     * @return array<string, int> id => distance, nearest first
     */
    private function subgraphDistances(string $username, array $seeds, int $maxDepth, int $asOf, bool $includeInvalid): array {
        if ($seeds === [] || $maxDepth < 0) {
            return [];
        }

        $valid = $this->pdo->prepare(
            $includeInvalid
                ? 'SELECT id FROM memory_entities WHERE username = :username'
                : 'SELECT id FROM memory_entities
                   WHERE username = :username AND valid_from <= :asof AND (valid_to IS NULL OR valid_to > :asof)'
        );
        $valid->execute($includeInvalid
            ? [':username' => $username]
            : [':username' => $username, ':asof' => $asOf]);
        $validIds = array_fill_keys(array_column($valid->fetchAll(), 'id'), true);

        $rels = $this->pdo->prepare(
            $includeInvalid
                ? 'SELECT from_entity, to_entity FROM memory_relations WHERE username = :username'
                : 'SELECT from_entity, to_entity FROM memory_relations
                   WHERE username = :username AND valid_from <= :asof AND (valid_to IS NULL OR valid_to > :asof)'
        );
        $rels->execute($includeInvalid
            ? [':username' => $username]
            : [':username' => $username, ':asof' => $asOf]);
        $adjacency = [];
        foreach ($rels->fetchAll() as $row) {
            $adjacency[$row['from_entity']][] = $row['to_entity'];
            $adjacency[$row['to_entity']][] = $row['from_entity']; // traversed undirected
        }

        $distance = [];
        $queue = new SplQueue();
        foreach ($seeds as $seed) {
            if (!isset($validIds[$seed]) || isset($distance[$seed])) {
                continue;
            }
            $distance[$seed] = 0;
            $queue->enqueue($seed);
        }
        while (!$queue->isEmpty()) {
            $node = $queue->dequeue();
            $d = $distance[$node];
            if ($d >= $maxDepth) {
                continue;
            }
            foreach ($adjacency[$node] ?? [] as $neighbor) {
                if (isset($distance[$neighbor]) || !isset($validIds[$neighbor])) {
                    continue;
                }
                $distance[$neighbor] = $d + 1;
                $queue->enqueue($neighbor);
            }
        }

        return $distance;
    }

    /**
     * Reciprocal Rank Fusion over ranked id lists. Every candidate's score is
     * the sum of 1/(k + rank) over the lists it appears in (k = 60), so a
     * candidate ranked highly in several strategies outranks one ranked highly
     * in only one.
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

    /**
     * Turn a user query into an FTS5 MATCH expression: each alphanumeric run is
     * quoted and given a prefix `*`, joined with OR, so "alice acme" matches
     * any entity mentioning either token.
     */
    private function ftsMatchQuery(string $query): string {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?? [];
        $terms = [];
        foreach ($tokens as $token) {
            $terms[] = '"' . str_replace('"', '', $token) . '"*';
        }
        return implode(' OR ', $terms);
    }

    /**
     * Character trigrams (byte-level, case-folded) used as features. Trigrams
     * are more distinctive than bigrams (which over-match on noise like "in"),
     * and one CJK character is exactly one UTF-8 trigram, so this also works
     * for Chinese/Japanese text that FTS5's unicode61 tokenizer cannot split.
     * @return array<string, true>
     */
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
    private function matchedOn(array $row, string $query): array {
        $fields = [
            'name' => (string) $row['name'],
            'entityType' => (string) $row['entity_type'],
            'observations' => (string) $row['observations'],
        ];
        $hits = [];
        foreach ($fields as $field => $text) {
            if ($text !== '' && stripos($text, $query) !== false) {
                $hits[] = $field;
            }
        }
        return $hits === [] ? ['name'] : $hits;
    }

    /** @param string[] $ids @return array<string, array<string, mixed>> id => raw entity row */
    private function entitiesById(string $username, array $ids): array {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, entity_type, observations FROM memory_entities WHERE username = ? AND id IN ($placeholders)"
        );
        $stmt->execute([$username, ...$ids]);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Same as entitiesById but with the full row (creation/validity timestamps),
     * used by the scoped subgraph read.
     *
     * @param string[] $ids @return array<string, array<string, mixed>> id => full raw entity row
     */
    private function fullEntitiesById(string $username, array $ids): array {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, entity_type, observations, created_at, updated_at, valid_from, valid_to
             FROM memory_entities WHERE username = ? AND id IN ($placeholders)"
        );
        $stmt->execute([$username, ...$ids]);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['id']] = $row;
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchEntities(string $username): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, entity_type, observations, created_at, updated_at, valid_from, valid_to
             FROM memory_entities
             WHERE username = :username
             ORDER BY created_at'
        );
        $stmt->execute([':username' => $username]);
        return $stmt->fetchAll();
    }

    /** @return array{id: string, observations: string}|null */
    private function findEntity(string $username, string $nameOrId): ?array {
        $byName = $this->pdo->prepare(
            'SELECT id, observations FROM memory_entities WHERE username = :username AND name = :key LIMIT 1'
        );
        $byName->execute([':username' => $username, ':key' => $nameOrId]);
        $entity = $byName->fetch();
        if ($entity !== false) {
            return $entity;
        }

        $byId = $this->pdo->prepare(
            'SELECT id, observations FROM memory_entities WHERE username = :username AND id = :key LIMIT 1'
        );
        $byId->execute([':username' => $username, ':key' => $nameOrId]);
        $entity = $byId->fetch();
        return $entity !== false ? $entity : null;
    }

    /** @return array<string, mixed>|null full entity row, matched by name first, then id */
    private function findEntityFull(string $username, string $nameOrId): ?array {
        $columns = 'id, name, entity_type, observations, created_at, updated_at, valid_from, valid_to';
        $byName = $this->pdo->prepare(
            "SELECT $columns FROM memory_entities WHERE username = :username AND name = :key LIMIT 1"
        );
        $byName->execute([':username' => $username, ':key' => $nameOrId]);
        $entity = $byName->fetch();
        if ($entity !== false) {
            return $entity;
        }

        $byId = $this->pdo->prepare(
            "SELECT $columns FROM memory_entities WHERE username = :username AND id = :key LIMIT 1"
        );
        $byId->execute([':username' => $username, ':key' => $nameOrId]);
        $entity = $byId->fetch();
        return $entity !== false ? $entity : null;
    }

    /** @param string[] $observations */
    private function updateObservations(string $username, string $id, array $observations): void {
        $stmt = $this->pdo->prepare(
            'UPDATE memory_entities SET observations = :observations, updated_at = :updated_at
             WHERE username = :username AND id = :id'
        );
        $stmt->execute([
            ':observations' => json_encode($observations, JSON_UNESCAPED_SLASHES),
            ':updated_at' => time(),
            ':username' => $username,
            ':id' => $id,
        ]);
        $this->syncFtsRow($username, $id);
    }

    /** Refresh the FTS mirror for one entity, keeping it in lockstep with the source row. */
    private function syncFtsRow(string $username, string $id): void {
        $stmt = $this->pdo->prepare(
            'SELECT name, entity_type, observations FROM memory_entities WHERE username = :username AND id = :id'
        );
        $stmt->execute([':username' => $username, ':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $this->pdo->prepare('DELETE FROM memory_entities_fts WHERE username = :username AND entity_id = :id')
            ->execute([':username' => $username, ':id' => $id]);
        $this->pdo->prepare(
            'INSERT INTO memory_entities_fts (username, entity_id, name, entity_type, observations)
             VALUES (:username, :id, :name, :entity_type, :observations)'
        )->execute([
            ':username' => $username,
            ':id' => $id,
            ':name' => $row['name'],
            ':entity_type' => $row['entity_type'],
            ':observations' => $row['observations'],
        ]);
    }

    /** @param array<string, mixed> $row */
    private function isValidAt(array $row, int $t): bool {
        $from = (int) ($row['valid_from'] ?? 0);
        $to = $row['valid_to'] ?? null;
        return $from <= $t && ($to === null || (int) $to > $t);
    }

    /** @return string[] */
    private function decodeObservations(string $json): array {
        $observations = json_decode($json, true);
        return is_array($observations) ? $observations : [];
    }

    /**
     * Normalize a value to a Unix timestamp. Accepts an epoch integer/numeric
     * string or any strtotime-parseable string (ISO-8601 datetime, etc.).
     * Returns null for absent or unparseable input.
     */
    public function toTimestamp(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }

    /** Render a Unix timestamp as an ISO-8601 string, or null when absent. */
    public function formatTime(mixed $ts): ?string {
        if ($ts === null || $ts === '') {
            return null;
        }
        return gmdate('c', (int) $ts);
    }

    /** @return string[] */
    private function stringList(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn(mixed $v): string => (string) $v, $value));
    }
}
