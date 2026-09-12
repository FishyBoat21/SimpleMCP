---
name: knowledge-graph-first
description: >-
  Enforces a Knowledge-Graph-First workflow for researching, navigating, and updating
  the project knowledge base using SimpleMCP graph tools before inspecting code or answering.
  Use this skill whenever working on tasks in this repository to orient on the project subgraph,
  deep-research files/classes, observe codebase changes, and proactively persist facts.
---

# Knowledge-Graph-First Assistant

This project uses a persistent knowledge-graph (KG) MCP toolset provided by `SimpleMCP`. All work follows the rules below: proactively persist valuable facts to the KG, and always consult the KG **before** any other information source.

## Tools Reference

Available via MCP (`SimpleMCP`):
- `read_graph`: Subgraph & index exploration (`root`, `depth`, `entity_id`, `include_observations`, `limit`, `offset`).
- `search_graph`: Hybrid/keyword/semantic entity lookup with relation expansion (`query`, `search_type`: `"keyword"|"semantic"|"hybrid"`, `top_k`, `hops`, `include_relations`).
- `create_entities`: Create new nodes (`entities: [{ name, entityType, observations }]`).
- `create_relations`: Create directed edges (`relations: [{ from, to, relationType }]`).
- `add_observations`: Append new facts to existing entities (`observations: [{ entityName, contents }]`).
- `invalidate_entity` / `invalidate_relation`: Mark entities or relations as invalid with timestamps.
- `delete_entities` / `delete_relations`: Permanently remove entities or relations.
- `merge_entities`: Consolidate duplicate entities (`keep_name`, `absorb_name`).
- `search_relations`: Search/filter edges directly (`relation_type`, `entity`, `direction`).
- `graph_summary`: High-level summary of node/edge counts, duplicate groups, and orphan counts.

---

## 0. Current-Project Deep Research (Highest Priority)

Throughout this skill, `<project>` denotes the KG entity (type `project`) for the repository you are currently working in. Resolve it at the start of a session by searching for the repo/project name (e.g. `search_graph(query="SimpleMCP", search_type="keyword", top_k=3)` or `read_graph()` and pick the matching `project` node) — **never hardcode it**.

The KG holds a rich subgraph of this very project — the `<project>` entity plus its classes, tools, files, config, and architecture decisions. **Observing and deeply researching the current project from the knowledge base is the first move for every task**, ahead of reading code or searching the web.

### Observe the Project (Research from the KB, in order)

1. **Orient on the project subgraph** — `read_graph(root='<project>', depth=2)` to load the current project's entity cluster: what files, classes, and tools exist and how they relate (e.g. `entry_point`, `core_component`, `contains`, `configured_by`, `uses`, `registers`, `exposes`). This read is compact (no observations) by design so it stays small even on a large graph; observation detail comes from step 2's per-file search, not from this orientation call.
2. **Deep-dive the targets** — for each file, class, or tool the task touches, run `search_graph(query='<name>', search_type='keyword', top_k=5, hops=1)` to pull every recorded observation and neighbor. Use the KG as the map *before* opening the code.
3. **Reconcile, then read** — read the actual files to confirm or refine the KG picture. Treat KG facts as authoritative for intent and history; treat the code as authoritative for current shape. Where they diverge, the KG entry is stale → update it (below).
4. **Cite the source** — answers grounded in project observations get `[KG]`.

### Save Observation Results (Whenever you observe project files)

Any task that involves reading/observing project files MUST persist what was learned:

| Observation outcome | Action |
|---------------------|--------|
| File/class/tool already in KG | `add_observations` — append the new facts (path, methods, deps, purpose). Never replace or duplicate. |
| File/class/tool NOT yet in KG | `create_entities` (type `file`/`class`/`tool`) + observations, then `create_relations` to link it to `<project>` (`contains`/`core_component`/`uses`/`entry_point`) |
| Architecture/decision discovered | `create_entities` (type `decision`) + relation to the project |
| Observation supersedes a fact | `search_graph` → `invalidate_entity` / `invalidate_relation`, then save the corrected fact |

After every observation round, report what you persisted:

```text
📦 Observed & saved:
  • <SomeClass> (class) — src/SomeClass.php, <purpose>
  • <project> → core_component → <SomeClass>
```

---

## 1. Priority Chain (Never skip tiers)

| Tier | Source | Rule |
|------|--------|------|
| **1 — Knowledge Graph** | `search_graph`, `read_graph` | MUST check first. No exceptions. |
| **2 — Web Search** | web tools | Only if Tier 1 is insufficient. Explain *why* the KG was insufficient. |
| **3 — Parametric knowledge** | training data | Last resort. Prefix with: `⚠️ From training data (cutoff: [date]), may be outdated:` |

Cite the tier in every answer: `[KG]`, `[WEB]`, or `[PARAM]`.

> When the topic is the current project, Tier 1 starts with its own subgraph — `read_graph(root='<project>', depth=2)` + per-file `search_graph` — see Section 0.

---

## 2. Before Answering Protocol

Run this before any substantive response:

1. **Work on this project?** → Orient on its subgraph first (`read_graph(root='<project>', depth=2)`), then deep-research the files/classes you'll touch with `search_graph(query='<name>', search_type='keyword', hops=1)`. Save the observations (Section 0).
2. If the question involves a previously discussed topic, entity, decision, preference, or architecture → `search_graph(query='<query>', search_type='hybrid', top_k=5, hops=1)`.
3. If this is the start of a session with no KG context loaded → `read_graph()` to orient.
4. If `search_graph` returns nothing useful → retry with a different query or `hops=2`.
5. Still nothing → proceed to Tier 2 (web search), with justification.

---

## 3. Active Saving Rules

### When to save (same turn)

| Trigger | Example | Action |
|---------|---------|--------|
| Names an entity | "My project is called Helios" | `create_entities` |
| States a decision | "We'll use PostgreSQL 16" | `create_entities` + relation to project |
| States a preference | "I prefer composition over inheritance" | `create_entities` (concept) + `add_observations` |
| Shares a constraint | "Must run on arm64 only" | `add_observations` to project |
| Shares a location | "Config is in src/Config/App.php" | `create_entities` (file) + relation |
| Corrects previous info | "Actually it's Python 3.12" | `search_graph` → update / invalidate |
| Explicitly asks | "Remember this" | Follow instructions |

### Save in batch (natural boundaries)

- A topic concludes with 3+ unsaved facts
- User is about to switch subjects
- End of a long technical discussion

### Pre-save checklist (in order)

1. **Signal check** — reusable fact or chitchat? Heuristic: *"Would this be useful in a conversation one month from now?"* If no, skip.
2. **Dedup check** — `search_graph(query='<candidate>', search_type='keyword', top_k=3)`. Entity exists? Use `add_observations` — **never** create duplicates. Duplicate detection is **root-aware**: the same `name` + `entityType` in *different* projects is *not* a duplicate. For genuine same-project forks already in the graph, collapse them with `merge_entities(keep_name='...', absorb_name='...')` rather than hand-merging.
3. **Granularity check** —
   - Single standalone fact → `create_entities` (entity only)
   - Two connected entities → `create_entities` (both) + `create_relations`
   - Detail about existing entity → `add_observations`
   - Cluster of related facts → batch `create_entities` + `create_relations`
4. **Type check** — assign one of: `person | project | tool | technology | file | class | decision | preference | constraint | concept | pattern | organization`

### Signal vs noise

| ✅ Save | ❌ Skip |
|---------|---------|
| Named entities (people, projects, tools, files, classes) | Greetings, chitchat |
| Technical decisions, architecture choices | Speculation, "maybe" |
| Explicit preferences and constraints | Temporary context ("this current bug") |
| Relationships between entities | Redundant restatements |
| Domain-specific terminology | Filler words, process talk |

### Post-save transparency

Always report what you persisted:

```text
📦 Saved:
  • Helios (project) — user's main project
  • PostgreSQL 16 (technology) — database choice
  • Helios → uses → PostgreSQL 16
```

Single item: `📦 Saved: [entity] ([type]) — [one-line summary]`

---

## 4. Loading Strategy

| Situation | Tool & Parameters | Why |
|-----------|-------------------|-----|
| Work on this project | `read_graph(root='<project>', depth=2)` | Orient on the project subgraph first (Section 0) |
| First message of a session | `read_graph()` | Orient via the compact index (id/name/type/relation count); drill into what matters with `read_graph(entity_id=...)` |
| Specific topic | `search_graph(query='...', search_type='hybrid', hops=1)` | Targeted search + neighbors |
| Relationship exploration | `search_graph(query='...', search_type='hybrid', hops=2)` | Two-hop context |
| Exact entity lookup | `search_graph(query='<name>', search_type='keyword', hops=0)` | Exact match, no noise |
| Fuzzy / typo-tolerant | `search_graph(query='<concept>', search_type='semantic', hops=1)` | N-gram similarity |
| Unsure | `search_graph(query='...', search_type='hybrid', hops=1)` | RRF fusion |
| KG returns nothing | Retry different query, else `read_graph()` | New angle |
| Quick health / spot duplicates | `graph_summary()` | Node/edge counts, relation-type histogram, duplicate groups (same name+type **and** same project), orphan count — cheap, no traversal |
| Search edges directly | `search_relations(relation_type='...', entity='...', direction='...')` | `search_graph` only matches entities; this finds and filters edges |
| Consolidate duplicates | `merge_entities(keep_name='...', absorb_name='...')` | Collapse duplicate entities into one node (merges observations, re-points relations) |

**Anti-patterns:** no `read_graph()` per message; no `hops=4` for a single fact; don't re-load data already in conversation context.

---

## 5. Conflict Resolution

KG facts are explicitly stored by the user — they are more recent, specific, and authoritative than training data. **KG always wins.**

> Respond: `[KG] The knowledge graph indicates X. (Note: my training data suggests Y, but KG takes priority.)`

---

## 6. Anti-Patterns — Never Do This

- Answer without checking KG first
- Modify a project file without first researching its KG entity and neighbors (Section 0)
- Observe a project file but skip saving the observation result (or save silently)
- Assume training data matches user's reality
- Create duplicate entities (dedup first; consolidate existing forks with `merge_entities`)
- Save greetings, chitchat, or temporary context
- Save silently (always report saves)
- Skip to web search when KG has partial info (use it, flag gaps, then fall back)
- Overwrite correct observations (use `add_observations` to append, never replace)
- Fake a KG lookup you didn't perform — be honest about sources

---

## 7. Session Startup

On the first message of each session:

1. Resolve `<project>` (Section 0), then call `read_graph()` — or the scoped `read_graph(root='<project>', depth=2)` when the session is focused on this repo — to load the current KG.
2. Report what you found: `📚 Loaded knowledge graph: [N] entities, [N] relations.`
3. Note any stale or outdated-looking entries.
4. Proceed with the user's request using KG context.
5. Throughout the session, save new facts as they emerge.
