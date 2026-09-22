/**
 * SimpleMCP Standalone 3D Visualizer - SQLite Loader
 * Loads and queries memory.sqlite directly in the browser via sql.js (WebAssembly).
 */

class SQLiteMemoryLoader {
    constructor() {
        this.sql = null;
        this.db = null;
        this.currentDatabaseBytes = null;
        this.databaseName = '';
        this.users = [];
        this.activeUser = null;
        this.graphData = null;
        this.onStatusCallback = null;
    }

    setStatusCallback(cb) {
        this.onStatusCallback = cb;
    }

    logStatus(message, isError = false) {
        if (this.onStatusCallback) {
            this.onStatusCallback(message, isError);
        }
        console.log(`[SQLiteLoader] ${message}`);
    }

    /**
     * Initialize sql.js WebAssembly engine
     */
    async init() {
        if (this.sql) return this.sql;

        this.logStatus('Initializing SQLite WebAssembly engine...');
        try {
            if (typeof initSqlJs !== 'function') {
                throw new Error('initSqlJs is not available. Ensure sql-wasm.js is loaded.');
            }

            // Configure WASM locator
            const config = {
                locateFile: (file) => {
                    // Try local vendor folder first, fallback to CDN if needed
                    return './vendor/' + file;
                }
            };

            this.sql = await initSqlJs(config);
            this.logStatus('SQLite WebAssembly engine ready.');
            return this.sql;
        } catch (err) {
            this.logStatus(`WebAssembly initialization failed: ${err.message}. Attempting CDN fallback...`, true);
            try {
                this.sql = await initSqlJs({
                    locateFile: (file) => `https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.12.0/${file}`
                });
                this.logStatus('SQLite engine initialized from CDN fallback.');
                return this.sql;
            } catch (fallbackErr) {
                this.logStatus(`Failed to initialize SQLite engine: ${fallbackErr.message}`, true);
                throw fallbackErr;
            }
        }
    }

    /**
     * Load an ArrayBuffer containing a SQLite database
     */
    async loadDatabaseFromBuffer(arrayBuffer, filename = 'memory.sqlite') {
        await this.init();

        this.logStatus(`Reading database '${filename}' (${(arrayBuffer.byteLength / 1024 / 1024).toFixed(2)} MB)...`);
        this.currentDatabaseBytes = new Uint8Array(arrayBuffer);
        this.databaseName = filename;

        try {
            if (this.db) {
                this.db.close();
            }
            this.db = new this.sql.Database(this.currentDatabaseBytes);

            // Verify tables exist
            const tablesRes = this.db.exec("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('memory_entities', 'memory_relations')");
            const foundTables = tablesRes.length > 0 ? tablesRes[0].values.map(v => v[0]) : [];

            if (!foundTables.includes('memory_entities')) {
                throw new Error(`Table 'memory_entities' not found in database. Found tables: ${foundTables.join(', ') || 'none'}`);
            }

            // Discover users
            const usersRes = this.db.exec("SELECT DISTINCT username FROM memory_entities ORDER BY username ASC");
            this.users = usersRes.length > 0 ? usersRes[0].values.map(v => String(v[0])) : ['local'];

            // Default to 'local' if available, otherwise first user
            this.activeUser = this.users.includes('local') ? 'local' : this.users[0];

            this.logStatus(`Database '${filename}' loaded successfully. Users found: ${this.users.join(', ')}`);
            return this.loadGraphForUser(this.activeUser);
        } catch (err) {
            this.logStatus(`Error reading SQLite database: ${err.message}`, true);
            throw err;
        }
    }

    /**
     * Query memory_entities and memory_relations for a specific username
     */
    loadGraphForUser(username) {
        if (!this.db) {
            throw new Error('Database not loaded yet.');
        }

        this.activeUser = username;
        this.logStatus(`Querying knowledge graph for user '${username}'...`);

        // Query entities
        const entityStmt = this.db.prepare(
            `SELECT id, username, name, entity_type, observations, created_at, updated_at, valid_from, valid_to
             FROM memory_entities
             WHERE username = :u`
        );
        entityStmt.bind({ ':u': username });

        const rawEntities = [];
        while (entityStmt.step()) {
            rawEntities.push(entityStmt.getAsObject());
        }
        entityStmt.free();

        // Query relations
        const relationStmt = this.db.prepare(
            `SELECT username, from_entity, to_entity, relation_type, created_at, updated_at, valid_from, valid_to
             FROM memory_relations
             WHERE username = :u`
        );
        relationStmt.bind({ ':u': username });

        const rawRelations = [];
        while (relationStmt.step()) {
            rawRelations.push(relationStmt.getAsObject());
        }
        relationStmt.free();

        // Process entities & build map
        const entityMap = new Map();
        const entityTypeCounts = {};
        let minTimestamp = Infinity;
        let maxTimestamp = -Infinity;

        rawEntities.forEach(e => {
            let observations = [];
            if (typeof e.observations === 'string') {
                try {
                    observations = JSON.parse(e.observations);
                    if (!Array.isArray(observations)) {
                        observations = [e.observations];
                    }
                } catch {
                    observations = e.observations ? [e.observations] : [];
                }
            } else if (Array.isArray(e.observations)) {
                observations = e.observations;
            }

            const type = (e.entity_type || 'untyped').trim().toLowerCase();
            entityTypeCounts[type] = (entityTypeCounts[type] || 0) + 1;

            const validFrom = e.valid_from ? Number(e.valid_from) : (e.created_at ? Number(e.created_at) : null);
            const validTo = e.valid_to ? Number(e.valid_to) : null;
            const createdAt = e.created_at ? Number(e.created_at) : null;
            const updatedAt = e.updated_at ? Number(e.updated_at) : null;

            if (validFrom && validFrom > 0 && validFrom < minTimestamp) minTimestamp = validFrom;
            if (validFrom && validFrom > maxTimestamp) maxTimestamp = validFrom;
            if (validTo && validTo > maxTimestamp) maxTimestamp = validTo;

            const entity = {
                id: String(e.id),
                name: String(e.name || e.id),
                entityType: type,
                observations: observations,
                validFrom: validFrom,
                validTo: validTo,
                createdAt: createdAt,
                updatedAt: updatedAt,
                inDegree: 0,
                outDegree: 0,
                degree: 0,
                connections: new Set(),
                neighbors: []
            };

            entityMap.set(entity.id, entity);
        });

        // Process relations
        const relationTypeCounts = {};
        const validRelations = [];

        rawRelations.forEach(r => {
            const fromId = String(r.from_entity);
            const toId = String(r.to_entity);
            const relType = String(r.relation_type || 'relates_to').trim();

            const fromNode = entityMap.get(fromId);
            const toNode = entityMap.get(toId);

            if (fromNode && toNode) {
                fromNode.outDegree++;
                fromNode.degree++;
                fromNode.connections.add(toId);

                toNode.inDegree++;
                toNode.degree++;
                toNode.connections.add(fromId);

                fromNode.neighbors.push({ id: toId, name: toNode.name, relation: relType, direction: 'outgoing' });
                toNode.neighbors.push({ id: fromId, name: fromNode.name, relation: relType, direction: 'incoming' });

                relationTypeCounts[relType] = (relationTypeCounts[relType] || 0) + 1;

                const validFrom = r.valid_from ? Number(r.valid_from) : (r.created_at ? Number(r.created_at) : null);
                const validTo = r.valid_to ? Number(r.valid_to) : null;

                validRelations.push({
                    source: fromId,
                    target: toId,
                    relationType: relType,
                    validFrom: validFrom,
                    validTo: validTo,
                    createdAt: r.created_at ? Number(r.created_at) : null,
                    updatedAt: r.updated_at ? Number(r.updated_at) : null
                });
            }
        });

        const entitiesList = Array.from(entityMap.values()).map(e => {
            // Convert Set to count
            const connCount = e.connections.size;
            delete e.connections;
            e.uniqueConnectionCount = connCount;
            return e;
        });

        if (minTimestamp === Infinity) minTimestamp = Math.floor(Date.now() / 1000) - 86400 * 30;
        if (maxTimestamp === -Infinity) maxTimestamp = Math.floor(Date.now() / 1000);

        this.graphData = {
            databaseName: this.databaseName,
            username: username,
            users: this.users,
            entities: entitiesList,
            relations: validRelations,
            entityTypeCounts: entityTypeCounts,
            relationTypeCounts: relationTypeCounts,
            minTimestamp: minTimestamp,
            maxTimestamp: maxTimestamp,
            summary: {
                totalEntities: entitiesList.length,
                totalRelations: validRelations.length,
                entityTypesCount: Object.keys(entityTypeCounts).length,
                relationTypesCount: Object.keys(relationTypeCounts).length
            }
        };

        this.logStatus(`Graph ready: ${entitiesList.length} entities, ${validRelations.length} relations.`);
        return this.graphData;
    }

    /**
     * Load from a File object (from <input> or drag-and-drop)
     */
    async loadFromFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = async () => {
                try {
                    const result = await this.loadDatabaseFromBuffer(reader.result, file.name);
                    resolve(result);
                } catch (err) {
                    reject(err);
                }
            };
            reader.onerror = () => reject(new Error('Failed to read file from disk.'));
            reader.readAsArrayBuffer(file);
        });
    }

    /**
     * Load from a URL (e.g. data/memory.sqlite or endpoint)
     */
    async loadFromUrl(url, filename = 'memory.sqlite') {
        this.logStatus(`Fetching database from ${url}...`);
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status} while fetching ${url}`);
        }
        const buffer = await response.arrayBuffer();
        return this.loadDatabaseFromBuffer(buffer, filename);
    }

    /**
     * Fallback: Load JSON snapshot (e.g. sample_graph.json)
     */
    loadFromJson(jsonData, name = 'JSON Snapshot') {
        this.databaseName = name;
        this.users = ['local'];
        this.activeUser = 'local';

        const rawEntities = jsonData.entities || [];
        const rawRelations = jsonData.relations || [];

        const entityMap = new Map();
        const entityTypeCounts = {};
        let minTimestamp = Infinity;
        let maxTimestamp = -Infinity;

        rawEntities.forEach(e => {
            const type = (e.entity_type || e.entityType || 'untyped').trim().toLowerCase();
            entityTypeCounts[type] = (entityTypeCounts[type] || 0) + 1;

            const validFrom = e.valid_from || e.validFrom ? Number(e.valid_from || e.validFrom) : null;
            const validTo = e.valid_to || e.validTo ? Number(e.valid_to || e.validTo) : null;
            if (validFrom && validFrom > 0 && validFrom < minTimestamp) minTimestamp = validFrom;
            if (validFrom && validFrom > maxTimestamp) maxTimestamp = validFrom;
            if (validTo && validTo > maxTimestamp) maxTimestamp = validTo;

            const entity = {
                id: String(e.id),
                name: String(e.name || e.id),
                entityType: type,
                observations: Array.isArray(e.observations) ? e.observations : [],
                validFrom: validFrom,
                validTo: validTo,
                createdAt: e.created_at ? Number(e.created_at) : null,
                updatedAt: e.updated_at ? Number(e.updated_at) : null,
                inDegree: 0,
                outDegree: 0,
                degree: 0,
                neighbors: []
            };
            entityMap.set(entity.id, entity);
        });

        const relationTypeCounts = {};
        const validRelations = [];

        rawRelations.forEach(r => {
            const fromId = String(r.from_entity || r.from || r.source);
            const toId = String(r.to_entity || r.to || r.target);
            const relType = String(r.relation_type || r.relationType || 'relates_to').trim();

            const fromNode = entityMap.get(fromId);
            const toNode = entityMap.get(toId);

            if (fromNode && toNode) {
                fromNode.outDegree++;
                fromNode.degree++;
                toNode.inDegree++;
                toNode.degree++;

                fromNode.neighbors.push({ id: toId, name: toNode.name, relation: relType, direction: 'outgoing' });
                toNode.neighbors.push({ id: fromId, name: fromNode.name, relation: relType, direction: 'incoming' });

                relationTypeCounts[relType] = (relationTypeCounts[relType] || 0) + 1;

                validRelations.push({
                    source: fromId,
                    target: toId,
                    relationType: relType,
                    validFrom: r.valid_from || r.validFrom ? Number(r.valid_from || r.validFrom) : null,
                    validTo: r.valid_to || r.validTo ? Number(r.valid_to || r.validTo) : null,
                    createdAt: r.created_at ? Number(r.created_at) : null,
                    updatedAt: r.updated_at ? Number(r.updated_at) : null
                });
            }
        });

        const entitiesList = Array.from(entityMap.values());
        if (minTimestamp === Infinity) minTimestamp = Math.floor(Date.now() / 1000) - 86400 * 30;
        if (maxTimestamp === -Infinity) maxTimestamp = Math.floor(Date.now() / 1000);

        this.graphData = {
            databaseName: name,
            username: 'local',
            users: ['local'],
            entities: entitiesList,
            relations: validRelations,
            entityTypeCounts: entityTypeCounts,
            relationTypeCounts: relationTypeCounts,
            minTimestamp: minTimestamp,
            maxTimestamp: maxTimestamp,
            summary: {
                totalEntities: entitiesList.length,
                totalRelations: validRelations.length,
                entityTypesCount: Object.keys(entityTypeCounts).length,
                relationTypesCount: Object.keys(relationTypeCounts).length
            }
        };

        this.logStatus(`JSON Graph loaded: ${entitiesList.length} entities, ${validRelations.length} relations.`);
        return this.graphData;
    }
}

window.SQLiteMemoryLoader = SQLiteMemoryLoader;
