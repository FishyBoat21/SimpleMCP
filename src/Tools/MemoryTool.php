<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\Auth\MemoryStore;
use McpServer\UserContext;

/**
 * Persistent knowledge-graph memory, following the standard MCP memory server
 * interface (create_entities / create_relations / add_observations /
 * read_graph) plus search_graph and the temporal invalidate_* tools.
 *
 * Storage is a per-user slice of the SQLite database (src/Auth/MemoryStore.php):
 * every entity and relation row carries the calling user's username, so each
 * account's memory is fully isolated. In stdio mode the injected user is the
 * trusted `local` user.
 *
 * Access: every tool here requires the caller to hold the `user` or `admin`
 * role, so only logged-in accounts can list or call them — anonymous HTTP
 * callers see none of these tools and get a -32001 access-denied on direct
 * calls.
 *
 * Every fact is temporal: it carries created/updated timestamps plus a
 * validity window (validFrom / validTo). invalidate_entity / invalidate_relation
 * close the window instead of deleting, so history survives and read_graph /
 * search_graph accept an `as_of` instant to look back in time. read_graph is
 * lazy: it returns a compact paginated index (id/name/type/relation count) by
 * default and loads full observations on demand — pass `entity_id` for one
 * entity, or `root` (an entity id or name) plus `depth` for the neighborhood
 * within that many relation hops.
 */
readonly class MemoryTool {
    /**
     * The memory tools require an authenticated account: the caller must hold
     * the `user` or `admin` role. Anonymous (unauthenticated) HTTP callers are
     * hidden from tools/list and rejected at tools/call with -32001. In stdio
     * mode the injected `local` user holds the `*` role, so access still works.
     */
    private const REQUIRED_ROLES = ['user', 'admin'];

    #[McpFunction(
        name: 'create_entities',
        roles: self::REQUIRED_ROLES,
        description: 'Create new entities in the knowledge graph. Adds new objects, persons, or concepts to the persistent graph. Idempotent: re-creating an existing id replaces that entity.',
        schema: [
            'type' => 'object',
            'properties' => [
                'entities' => [
                    'type' => 'array',
                    'description' => 'Entities to create or update',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Stable identifier, e.g. "alice" or "acme"'],
                            'name' => ['type' => 'string', 'description' => 'Human-readable name'],
                            'entityType' => ['type' => 'string', 'description' => 'Kind of entity, e.g. person, organization, place, concept'],
                            'observations' => [
                                'type' => 'array',
                                'description' => 'Initial facts about the entity',
                                'items' => ['type' => 'string'],
                            ],
                            'validFrom' => ['description' => 'Optional: when the fact becomes valid — Unix timestamp or ISO-8601 datetime. Defaults to now.'],
                        ],
                        'required' => ['id', 'name', 'entityType'],
                    ],
                ],
            ],
            'required' => ['entities'],
        ]
    )]
    public function createEntities(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $entities = $arguments['entities'] ?? null;
        if (!is_array($entities) || $entities === []) {
            return [['type' => 'text', 'text' => "Error: 'entities' must be a non-empty array."]];
        }

        $ids = (new MemoryStore())->createEntities($user->username, $entities);
        $label = count($ids) === 1 ? 'entity' : 'entities';

        return [
            [
                'type' => 'text',
                'text' => "Successfully created or updated " . count($ids) . " $label: " . implode(', ', $ids),
            ],
        ];
    }

    #[McpFunction(
        name: 'create_relations',
        roles: self::REQUIRED_ROLES,
        description: 'Create relations between existing entities. Connects different entities together with directional links, e.g. "alice works_at acme". Both endpoints must already exist; duplicate relations are ignored.',
        schema: [
            'type' => 'object',
            'properties' => [
                'relations' => [
                    'type' => 'array',
                    'description' => 'Relations to create',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string', 'description' => 'id of the source entity'],
                            'to' => ['type' => 'string', 'description' => 'id of the target entity'],
                            'relationType' => ['type' => 'string', 'description' => 'Label for the directed link, e.g. "works_at"'],
                            'validFrom' => ['description' => 'Optional: when the fact becomes valid — Unix timestamp or ISO-8601 datetime. Defaults to now.'],
                        ],
                        'required' => ['from', 'to', 'relationType'],
                    ],
                ],
            ],
            'required' => ['relations'],
        ]
    )]
    public function createRelations(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $relations = $arguments['relations'] ?? null;
        if (!is_array($relations) || $relations === []) {
            return [['type' => 'text', 'text' => "Error: 'relations' must be a non-empty array."]];
        }

        $result = (new MemoryStore())->createRelations($user->username, $relations);

        $lines = [];
        if ($result['relations'] !== []) {
            $label = count($result['relations']) === 1 ? 'relation' : 'relations';
            $lines[] = "Successfully created " . count($result['relations']) . " $label:\n" . implode("\n", $result['relations']);
        }
        foreach ($result['errors'] as $error) {
            $lines[] = "Warning: $error";
        }
        if ($lines === []) {
            $lines[] = 'No relations created (all were duplicates or missing endpoints).';
        }

        return [['type' => 'text', 'text' => implode("\n", $lines)]];
    }

    #[McpFunction(
        name: 'add_observations',
        roles: self::REQUIRED_ROLES,
        description: 'Add new observations to existing entities. Appends new facts or details to an entity; the entity is matched by name, then by id. Duplicate observations are deduplicated.',
        schema: [
            'type' => 'object',
            'properties' => [
                'observations' => [
                    'type' => 'array',
                    'description' => 'Observations to append',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'entityName' => ['type' => 'string', 'description' => 'Name (or id) of the entity to augment'],
                            'contents' => [
                                'type' => 'array',
                                'description' => 'New facts to append',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['entityName', 'contents'],
                    ],
                ],
            ],
            'required' => ['observations'],
        ]
    )]
    public function addObservations(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $observations = $arguments['observations'] ?? null;
        if (!is_array($observations) || $observations === []) {
            return [['type' => 'text', 'text' => "Error: 'observations' must be a non-empty array."]];
        }

        $result = (new MemoryStore())->addObservations($user->username, $observations);

        $lines = [];
        foreach ($result['updates'] as $name => $counts) {
            $lines[] = "Added {$counts['added']} observation(s) to '$name' (now {$counts['total']} total).";
        }
        foreach ($result['errors'] as $error) {
            $lines[] = "Warning: $error";
        }
        if ($lines === []) {
            $lines[] = 'No observations added.';
        }

        return [['type' => 'text', 'text' => implode("\n", $lines)]];
    }

    #[McpFunction(
        name: 'delete_entities',
        roles: self::REQUIRED_ROLES,
        description: 'Delete entities from the knowledge graph by id (or name — matched by name first, then id). Any relations pointing to or from a deleted entity are removed as well. Idempotent: missing entities are reported as warnings.',
        schema: [
            'type' => 'object',
            'properties' => [
                'entityIds' => [
                    'type' => 'array',
                    'description' => 'Ids (or names) of the entities to delete',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['entityIds'],
        ]
    )]
    public function deleteEntities(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $identifiers = $arguments['entityIds'] ?? null;
        if (!is_array($identifiers) || $identifiers === []) {
            return [['type' => 'text', 'text' => "Error: 'entityIds' must be a non-empty array."]];
        }

        $result = (new MemoryStore())->deleteEntities($user->username, $identifiers);

        $lines = [];
        if ($result['deleted'] !== []) {
            $label = count($result['deleted']) === 1 ? 'entity' : 'entities';
            $relLabel = $result['relationsRemoved'] === 1 ? 'relation' : 'relations';
            $lines[] = 'Deleted ' . count($result['deleted']) . " $label: " . implode(', ', $result['deleted'])
                . ' (cascade-removed ' . $result['relationsRemoved'] . " $relLabel).";
        }
        foreach ($result['errors'] as $error) {
            $lines[] = "Warning: $error";
        }
        if ($lines === []) {
            $lines[] = 'No entities deleted.';
        }

        return [['type' => 'text', 'text' => implode("\n", $lines)]];
    }

    #[McpFunction(
        name: 'delete_relations',
        roles: self::REQUIRED_ROLES,
        description: 'Delete relations from the knowledge graph. Each spec must include from, to, and relationType, matching the schema used by create_relations. Idempotent: missing relations are reported as warnings.',
        schema: [
            'type' => 'object',
            'properties' => [
                'relations' => [
                    'type' => 'array',
                    'description' => 'Relations to delete',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from' => ['type' => 'string', 'description' => 'id of the source entity'],
                            'to' => ['type' => 'string', 'description' => 'id of the target entity'],
                            'relationType' => ['type' => 'string', 'description' => 'Label for the directed link, e.g. "works_at"'],
                        ],
                        'required' => ['from', 'to', 'relationType'],
                    ],
                ],
            ],
            'required' => ['relations'],
        ]
    )]
    public function deleteRelations(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $relations = $arguments['relations'] ?? null;
        if (!is_array($relations) || $relations === []) {
            return [['type' => 'text', 'text' => "Error: 'relations' must be a non-empty array."]];
        }

        $result = (new MemoryStore())->deleteRelations($user->username, $relations);

        $lines = [];
        if ($result['deleted'] !== []) {
            $label = count($result['deleted']) === 1 ? 'relation' : 'relations';
            $lines[] = 'Successfully deleted ' . count($result['deleted']) . " $label:\n" . implode("\n", $result['deleted']);
        }
        foreach ($result['errors'] as $error) {
            $lines[] = "Warning: $error";
        }
        if ($lines === []) {
            $lines[] = 'No relations deleted.';
        }

        return [['type' => 'text', 'text' => implode("\n", $lines)]];
    }

    #[McpFunction(
        name: 'read_graph',
        roles: self::REQUIRED_ROLES,
        description: 'Read the knowledge graph for the current user. Every mode returns compact entries by default (id, name, type, relation count — no observations) so routine reads stay small. Load full observations on demand: pass `entity_id` for a single entity (observations + its relations), or `root` + `depth` for the subgraph around an entity (each entity carries its distance), optionally with `include_observations: true` to also return observations. The no-arg form returns a paginated index (`limit`/`offset`). All modes respect `as_of` (facts valid at that time; default now) and `includeInvalid` (also show historical/invalidated facts).',
        schema: [
            'type' => 'object',
            'properties' => [
                'as_of' => ['description' => 'Optional: Unix timestamp or ISO-8601 datetime. Only facts valid at that time are returned. Defaults to now.'],
                'includeInvalid' => [
                    'type' => 'boolean',
                    'description' => 'When true, invalidated/historical facts are included as well (they show a validTo value).',
                ],
                'entity_id' => [
                    'type' => 'string',
                    'description' => 'Optional: entity id (or name — matched by name first, then id) to load in full: observations, timestamps, and every relation touching it. Takes precedence over `root` and the index.',
                ],
                'root' => [
                    'type' => 'string',
                    'description' => 'Optional: root entity id (or name — matched by name first, then id). When set, the full subgraph within `depth` hops of this entity is returned. Takes precedence over the index.',
                ],
                'depth' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                    'maximum' => 8,
                    'description' => 'Maximum undirected relation hops from the root entity to include; 0 returns just the root. Requires `root`; ignored otherwise.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 100,
                    'minimum' => 1,
                    'maximum' => 1000,
                    'description' => 'Index mode only: maximum number of index entries to return (pagination). Default 100.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                    'description' => 'Index mode only: number of index entries to skip (pagination). Default 0.',
                ],
                'include_observations' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Subgraph mode only: when true, each subgraph entity also carries its full observations (and creation/validity timestamps). Off by default so routine subgraph reads stay compact; prefer `entity_id` or search_graph for targeted observation detail.',
                ],
            ],
        ]
    )]
    public function readGraph(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $root = $arguments['root'] ?? null;
        if (!is_string($root) || $root === '') {
            $root = null;
        }
        $entityId = $arguments['entity_id'] ?? null;
        if (!is_string($entityId) || $entityId === '') {
            $entityId = null;
        }
        $graph = (new MemoryStore())->readGraph(
            $user->username,
            $arguments['as_of'] ?? null,
            (bool) ($arguments['includeInvalid'] ?? false),
            $root,
            (int) ($arguments['depth'] ?? 0),
            $entityId,
            (int) ($arguments['limit'] ?? 100),
            (int) ($arguments['offset'] ?? 0),
            (bool) ($arguments['include_observations'] ?? false),
        );

        return [
            [
                'type' => 'text',
                'text' => json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    #[McpFunction(
        name: 'search_graph',
        roles: self::REQUIRED_ROLES,
        description: 'Search the knowledge graph. keyword = BM25 via a built-in SQLite FTS5 index; semantic = fuzzy character n-gram similarity (a zero-dependency stand-in for embeddings, resilient to typos and CJK text); hybrid = both fused with Reciprocal Rank Fusion. When hops > 0, results are expanded by breadth-first traversal through up to `hops` relation hops from the matched entities. Only facts valid at `as_of` are searched.',
        schema: [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search text, e.g. "alice" or "works at acme"'],
                'search_type' => [
                    'type' => 'string',
                    'enum' => ['keyword', 'semantic', 'hybrid'],
                    'default' => 'keyword',
                    'description' => 'Retrieval strategy: keyword, semantic, or hybrid (RRF fusion of both plus graph expansion).',
                ],
                'top_k' => [
                    'type' => 'integer',
                    'default' => 10,
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Maximum number of results to return.',
                ],
                'hops' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 0,
                    'maximum' => 4,
                    'description' => 'Breadth-first traversal depth over relations from matched entities; 0 disables graph expansion.',
                ],
                'as_of' => ['description' => 'Optional: Unix timestamp or ISO-8601 datetime. Only facts valid at that time are searched. Defaults to now.'],
            ],
            'required' => ['query'],
        ]
    )]
    public function searchGraph(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $result = (new MemoryStore())->searchGraph(
            $user->username,
            (string) ($arguments['query'] ?? ''),
            (string) ($arguments['search_type'] ?? 'keyword'),
            (int) ($arguments['top_k'] ?? 10),
            (int) ($arguments['hops'] ?? 1),
            $arguments['as_of'] ?? null,
        );

        return [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]];
    }

    #[McpFunction(
        name: 'invalidate_entity',
        roles: self::REQUIRED_ROLES,
        description: 'Mark an entity (matched by name first, then id) as no longer valid from a point in time, preserving history instead of deleting it. Sets the entity\'s validTo; it disappears from default read_graph/search_graph results but remains visible via as_of lookups or includeInvalid. Any relation touching the entity is invalidated at the same time.',
        schema: [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Entity id (or name) to invalidate'],
                'invalid_at' => ['description' => 'Optional: when the fact stops being valid — Unix timestamp or ISO-8601 datetime. Defaults to now.'],
            ],
            'required' => ['id'],
        ]
    )]
    public function invalidateEntity(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $identifier = (string) ($arguments['id'] ?? '');
        if ($identifier === '') {
            return [['type' => 'text', 'text' => "Error: 'id' must be a non-empty string."]];
        }

        $result = (new MemoryStore())->invalidateEntity($user->username, $identifier, $arguments['invalid_at'] ?? null);
        if (($result['invalidated'] ?? false) === false) {
            return [['type' => 'text', 'text' => 'Warning: ' . ($result['error'] ?? 'unknown error')]];
        }

        $text = "Invalidated entity '{$result['id']}' (valid until {$result['validTo']}).";
        if ($result['relationsInvalidated'] > 0) {
            $label = $result['relationsInvalidated'] === 1 ? 'relation' : 'relations';
            $text .= " Also invalidated {$result['relationsInvalidated']} $label touching it.";
        }
        return [['type' => 'text', 'text' => $text]];
    }

    #[McpFunction(
        name: 'invalidate_relation',
        roles: self::REQUIRED_ROLES,
        description: 'Mark a directed relation as no longer valid from a point in time, preserving history instead of deleting it. Takes from/to/relationType exactly like create_relations. The relation disappears from default read_graph/search_graph results but remains visible via as_of lookups or includeInvalid.',
        schema: [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'id of the source entity'],
                'to' => ['type' => 'string', 'description' => 'id of the target entity'],
                'relationType' => ['type' => 'string', 'description' => 'Label for the directed link, e.g. "works_at"'],
                'invalid_at' => ['description' => 'Optional: when the fact stops being valid — Unix timestamp or ISO-8601 datetime. Defaults to now.'],
            ],
            'required' => ['from', 'to', 'relationType'],
        ]
    )]
    public function invalidateRelation(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $from = (string) ($arguments['from'] ?? '');
        $to = (string) ($arguments['to'] ?? '');
        $type = (string) ($arguments['relationType'] ?? '');
        if ($from === '' || $to === '' || $type === '') {
            return [['type' => 'text', 'text' => "Error: 'from', 'to' and 'relationType' must all be non-empty strings."]];
        }

        $result = (new MemoryStore())->invalidateRelation($user->username, $from, $to, $type, $arguments['invalid_at'] ?? null);
        if (($result['invalidated'] ?? false) === false) {
            return [['type' => 'text', 'text' => 'Warning: ' . ($result['error'] ?? 'unknown error')]];
        }

        return [
            [
                'type' => 'text',
                'text' => "Invalidated relation '{$result['from']} -> {$result['to']} ({$result['relationType']})' (valid until {$result['validTo']}).",
            ],
        ];
    }
}
