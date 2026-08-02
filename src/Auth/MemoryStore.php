<?php

declare(strict_types=1);

namespace McpServer\Auth;

use PDO;

/**
 * SQLite-backed knowledge graph backing the memory MCP tool.
 *
 * The graph lives in its own database file (data/memory.sqlite) — separate
 * from the auth database — and is created idempotently on first use.
 *
 * Every row is scoped to a username, so each user gets an isolated subgraph:
 * memory created under one account is never listed, linked, or mutated by
 * another. In stdio mode the injected user is the trusted `local` user.
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
                PRIMARY KEY (username, id)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_entities_username ON memory_entities(username);

            CREATE TABLE IF NOT EXISTS memory_relations (
                username      TEXT NOT NULL,
                from_entity   TEXT NOT NULL,
                to_entity     TEXT NOT NULL,
                relation_type TEXT NOT NULL,
                created_at    INTEGER NOT NULL,
                PRIMARY KEY (username, from_entity, to_entity, relation_type)
            );

            CREATE INDEX IF NOT EXISTS idx_memory_relations_username ON memory_relations(username);
            SQL);
    }

    /**
     * Insert or update entities, upserting on (username, id). Re-creating an
     * existing id replaces name, type, and observations entirely.
     *
     * @param string $username owner of the new entities
     * @param array<int, array<string, mixed>> $entities
     * @return string[] ids that were created or updated
     */
    public function createEntities(string $username, array $entities): array {
        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO memory_entities (id, username, name, entity_type, observations, created_at, updated_at)
             VALUES (:id, :username, :name, :entity_type, :observations, :created_at, :updated_at)
             ON CONFLICT (username, id) DO UPDATE SET
                 name = excluded.name,
                 entity_type = excluded.entity_type,
                 observations = excluded.observations,
                 updated_at = excluded.updated_at'
        );

        $ids = [];
        foreach ($entities as $entity) {
            $id = (string) ($entity['id'] ?? '');
            $name = (string) ($entity['name'] ?? '');
            if ($id === '' || $name === '') {
                continue;
            }

            $stmt->execute([
                ':id' => $id,
                ':username' => $username,
                ':name' => $name,
                ':entity_type' => (string) ($entity['entityType'] ?? ''),
                ':observations' => json_encode($this->stringList($entity['observations'] ?? []), JSON_UNESCAPED_SLASHES),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Create directed relations between existing entities. Relations that
     * already exist (same from/to/type) are left untouched.
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
            'INSERT INTO memory_relations (username, from_entity, to_entity, relation_type, created_at)
             VALUES (:username, :from, :to, :relation_type, :created_at)
             ON CONFLICT (username, from_entity, to_entity, relation_type) DO NOTHING'
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

            $stmt->execute([
                ':username' => $username,
                ':from' => $from,
                ':to' => $to,
                ':relation_type' => $type,
                ':created_at' => $now,
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
     * The full graph for a user: every entity with its observations, plus all
     * directed relations.
     *
     * @param string $username owner of the graph
     * @return array{entities: list<array{id: string, name: string, entityType: string, observations: string[]}>, relations: list<array{from: string, to: string, relationType: string}>}
     */
    public function readGraph(string $username): array {
        $entities = [];
        foreach ($this->fetchEntities($username) as $entity) {
            $observations = json_decode((string) ($entity['observations'] ?? '[]'), true);
            $entities[] = [
                'id' => $entity['id'],
                'name' => $entity['name'],
                'entityType' => $entity['entity_type'],
                'observations' => is_array($observations) ? $observations : [],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT from_entity, to_entity, relation_type
             FROM memory_relations
             WHERE username = :username
             ORDER BY created_at'
        );
        $stmt->execute([':username' => $username]);
        $relations = array_map(
            static fn(array $row): array => [
                'from' => $row['from_entity'],
                'to' => $row['to_entity'],
                'relationType' => $row['relation_type'],
            ],
            $stmt->fetchAll()
        );

        return ['entities' => $entities, 'relations' => $relations];
    }

    /** @return array<int, array<string, string>> */
    private function fetchEntities(string $username): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, entity_type, observations
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
    }

    /** @return string[] */
    private function stringList(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(static fn(mixed $v): string => (string) $v, $value));
    }
}
