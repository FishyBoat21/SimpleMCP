<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\Auth\MemoryStore;
use McpServer\UserContext;

/**
 * Persistent knowledge-graph memory, following the standard MCP memory server
 * interface (create_entities / create_relations / add_observations /
 * read_graph).
 *
 * Storage is a per-user slice of the SQLite database (src/Auth/MemoryStore.php):
 * every entity and relation row carries the calling user's username, so each
 * account's memory is fully isolated. In stdio mode the injected user is the
 * trusted `local` user.
 */
readonly class MemoryTool {
    #[McpFunction(
        name: 'create_entities',
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
        description: 'Read the entire knowledge graph. Retrieves the full network of stored memories and connections for the current user.',
        schema: ['type' => 'object', 'properties' => new \stdClass()]
    )]
    public function readGraph(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $graph = (new MemoryStore())->readGraph($user->username);

        return [
            [
                'type' => 'text',
                'text' => json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        ];
    }
}
