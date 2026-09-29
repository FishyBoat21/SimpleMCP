/**
 * SimpleMCP Standalone 3D Memory Visualizer
 * Powered by Three.js, OrbitControls, and SQLite WebAssembly.
 */

(function () {
    'use strict';

    // --- COLOR PALETTE & CONFIG ---
    const TYPE_COLORS = {
        project: 0x00f0ff,
        class: 0xa855f7,
        tool: 0x10b981,
        file: 0x38bdf8,
        config: 0xf59e0b,
        decision: 0xf43f5e,
        concept: 0x84cc16,
        endpoint: 0xff7849,
        preference: 0xec4899,
        default: 0x94a3b8
    };

    function getNodeColor(type) {
        const key = (type || '').toLowerCase();
        return TYPE_COLORS[key] || TYPE_COLORS.default;
    }

    function hexToCss(hex) {
        return '#' + hex.toString(16).padStart(6, '0');
    }

    // --- MAIN APPLICATION CLASS ---
    class MemoryVisualizer3D {
        constructor() {
            this.container = document.getElementById('viewport-container');
            this.canvas = document.getElementById('three-canvas');
            this.tooltip = document.getElementById('node-tooltip');

            // Three.js Core
            this.scene = null;
            this.camera = null;
            this.renderer = null;
            this.controls = null;
            this.raycaster = new THREE.Raycaster();
            this.mouse = new THREE.Vector2();

            // Scene Groups
            this.nodeGroup = new THREE.Group();
            this.edgeGroup = new THREE.Group();
            this.particleGroup = new THREE.Group();
            this.orbitGroup = new THREE.Group();
            this.starfield = null;

            // Data State
            this.loader = new SQLiteMemoryLoader();
            this.nodes = [];
            this.edges = [];
            this.nodeMap = new Map();
            this.activeFilters = {
                types: new Set(),
                relationTypes: new Set(),
                timelineTimestamp: null,
                isolatedSubtreeRoot: null
            };

            // Physics & Layout
            this.simulationRunning = true;
            this.currentLayout = 'force'; // 'force' | 'galaxy' | 'sphere'
            this.physicsParams = {
                repulsion: 1200,
                springLength: 70,
                springStrength: 0.045,
                gravity: 0.015,
                damping: 0.86
            };

            // Energy Particles
            this.edgeParticles = [];
            this.maxParticles = 140;

            // Interaction State
            this.selectedNode = null;
            this.hoveredNode = null;
            this.draggedNode = null;
            this.dragPlane = new THREE.Plane();
            this.planeIntersect = new THREE.Vector3();
            this.isDragging = false;
            this.autoRotate = false;
            this.showLabels = 'hubs'; // 'all' | 'hubs' | 'none'

            // Camera Fly-To Animation
            this.cameraAnimation = null;

            // Galaxy Layout State
            this.galaxy = null;
            this.orbitSpeed = 1;
            this.lastFrameTime = performance.now();
            this.tmpVec = new THREE.Vector3();

            // HUD idle-dim state (C4)
            this.lastHudActivity = performance.now();

            // Bind UI & Init
            this.initThree();
            this.initStarfield();
            this.initEvents();
            this.initUI();
            this.animate = this.animate.bind(this);
            requestAnimationFrame(this.animate);

            // Auto-load default dataset if available
            this.bootstrapData();
        }

        /**
         * Initialize Three.js Viewport
         */
        initThree() {
            const width = this.container.clientWidth || window.innerWidth;
            const height = this.container.clientHeight || window.innerHeight;

            // Scene
            this.scene = new THREE.Scene();
            this.scene.background = new THREE.Color(0x07090e);
            this.scene.fog = new THREE.FogExp2(0x07090e, 0.0008);

            // Camera
            this.camera = new THREE.PerspectiveCamera(55, width / height, 1, 6000);
            this.camera.position.set(0, 350, 750);

            // Renderer
            this.renderer = new THREE.WebGLRenderer({
                canvas: this.canvas,
                antialias: true,
                powerPreference: 'high-performance'
            });
            this.renderer.setSize(width, height);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

            // Orbit Controls
            this.controls = new THREE.OrbitControls(this.camera, this.renderer.domElement);
            this.controls.enableDamping = true;
            this.controls.dampingFactor = 0.06;
            this.controls.maxDistance = 3500;
            this.controls.minDistance = 30;

            // Lighting
            const ambientLight = new THREE.AmbientLight(0xffffff, 0.7);
            this.scene.add(ambientLight);

            const dirLight1 = new THREE.DirectionalLight(0x00f0ff, 0.8);
            dirLight1.position.set(400, 600, 500);
            this.scene.add(dirLight1);

            const dirLight2 = new THREE.DirectionalLight(0xa855f7, 0.6);
            dirLight2.position.set(-400, -300, -500);
            this.scene.add(dirLight2);

            // Add Groups
            this.scene.add(this.edgeGroup);
            this.scene.add(this.nodeGroup);
            this.scene.add(this.particleGroup);
            this.scene.add(this.orbitGroup);
        }

        /**
         * Ambient cosmic starfield
         */
        initStarfield() {
            const starCount = 2000;
            const geometry = new THREE.BufferGeometry();
            const positions = new Float32Array(starCount * 3);
            const colors = new Float32Array(starCount * 3);

            for (let i = 0; i < starCount; i++) {
                const r = 1800 + Math.random() * 1200;
                const theta = Math.random() * Math.PI * 2;
                const phi = Math.acos(2 * Math.random() - 1);

                positions[i * 3] = r * Math.sin(phi) * Math.cos(theta);
                positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
                positions[i * 3 + 2] = r * Math.cos(phi);

                // Subtle cyan/purple tinting
                const tint = Math.random();
                colors[i * 3] = tint > 0.5 ? 0.7 : 0.9;
                colors[i * 3 + 1] = tint > 0.5 ? 0.85 : 0.9;
                colors[i * 3 + 2] = 1.0;
            }

            geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

            const material = new THREE.PointsMaterial({
                size: 2.2,
                vertexColors: true,
                transparent: true,
                opacity: 0.75
            });

            this.starfield = new THREE.Points(geometry, material);
            this.scene.add(this.starfield);
        }

        /**
         * Global Window & Mouse Events
         */
        initEvents() {
            window.addEventListener('resize', () => {
                const w = window.innerWidth;
                const h = window.innerHeight;
                this.camera.aspect = w / h;
                this.camera.updateProjectionMatrix();
                this.renderer.setSize(w, h);
            });

            // Mouse Move & Raycast Hover
            this.canvas.addEventListener('mousemove', (e) => {
                const rect = this.canvas.getBoundingClientRect();
                this.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                this.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

                // Handle 3D Dragging
                if (this.isDragging && this.draggedNode) {
                    this.raycaster.setFromCamera(this.mouse, this.camera);
                    if (this.raycaster.ray.intersectPlane(this.dragPlane, this.planeIntersect)) {
                        this.draggedNode.position.copy(this.planeIntersect);
                        this.draggedNode.vx = 0;
                        this.draggedNode.vy = 0;
                        this.draggedNode.vz = 0;
                    }
                    return;
                }

                // Raycast Hover
                this.raycaster.setFromCamera(this.mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.nodeGroup.children);

                if (intersects.length > 0) {
                    const hitMesh = intersects[0].object;
                    const node = hitMesh.userData.node;
                    if (node && node !== this.hoveredNode) {
                        this.setHoveredNode(node);
                    }
                    this.tooltip.style.display = 'block';
                    this.tooltip.style.left = `${e.clientX}px`;
                    this.tooltip.style.top = `${e.clientY}px`;
                    this.tooltip.innerHTML = `<strong>${node.name}</strong> <span style="opacity:0.7">(${node.entityType})</span><br><small style="color:#00f0ff">${node.degree} connections • Double-click to view</small>`;
                    this.container.style.cursor = 'pointer';
                } else {
                    if (this.hoveredNode) {
                        this.setHoveredNode(null);
                    }
                    this.tooltip.style.display = 'none';
                    this.container.style.cursor = 'grab';
                }
            });

            // Pointer Down for Dragging
            this.canvas.addEventListener('mousedown', (e) => {
                if (e.button !== 0) return; // Left click only
                if (this.currentLayout === 'galaxy') return; // Orbits are analytical — no dragging

                this.raycaster.setFromCamera(this.mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.nodeGroup.children);
                if (intersects.length > 0) {
                    const hit = intersects[0].object;
                    this.draggedNode = hit.userData.node;
                    this.isDragging = true;
                    this.controls.enabled = false;

                    // Plane perpendicular to camera direction through node
                    const normal = new THREE.Vector3();
                    this.camera.getWorldDirection(normal).negate();
                    this.dragPlane.setFromNormalAndCoplanarPoint(normal, this.draggedNode.position);
                }
            });

            // Pointer Up & Selection Click
            window.addEventListener('mouseup', (e) => {
                if (this.isDragging) {
                    this.isDragging = false;
                    this.draggedNode = null;
                    this.controls.enabled = true;
                    return;
                }

                // If not dragging, process click selection
                this.raycaster.setFromCamera(this.mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.nodeGroup.children);
                if (intersects.length > 0) {
                    const hit = intersects[0].object;
                    this.selectNode(hit.userData.node);
                }
            });

            // Double Click Handler to View Node (Focus, Fly-To, and Pulse)
            this.canvas.addEventListener('dblclick', (e) => {
                const rect = this.canvas.getBoundingClientRect();
                this.mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                this.mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

                this.raycaster.setFromCamera(this.mouse, this.camera);
                const intersects = this.raycaster.intersectObjects(this.nodeGroup.children);

                if (intersects.length > 0) {
                    const hit = intersects[0].object;
                    const node = hit.userData.node;
                    if (node) {
                        this.viewNode(node);
                    }
                } else {
                    // Double-clicking empty space resets view
                    this.resetView();
                }
            });

            // Drag and Drop File Loader
            const dropOverlay = document.getElementById('drop-overlay');
            window.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropOverlay.classList.add('active');
            });
            window.addEventListener('dragleave', (e) => {
                if (e.relatedTarget === null) {
                    dropOverlay.classList.remove('active');
                }
            });
            window.addEventListener('drop', async (e) => {
                e.preventDefault();
                dropOverlay.classList.remove('active');

                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    const file = e.dataTransfer.files[0];
                    await this.loadFile(file);
                }
            });

            // Keyboard Shortcuts
            window.addEventListener('keydown', (e) => {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                if (e.code === 'Space') {
                    e.preventDefault();
                    this.togglePhysics();
                } else if (e.key === 'r' || e.key === 'R') {
                    this.resetView();
                } else if (e.key === 'Escape') {
                    this.deselectNode();
                } else if (e.key === '/') {
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                }
            });

            // HUD idle-dim (C4): any input wakes the chrome back up
            const wakeHud = () => {
                this.lastHudActivity = performance.now();
                document.body.classList.remove('hud-dim');
            };
            window.addEventListener('pointermove', wakeHud);
            window.addEventListener('pointerdown', wakeHud);
            window.addEventListener('keydown', wakeHud);
        }

        /**
         * Initialize HUD UI and controls
         */
        initUI() {
            // File input button
            const fileInput = document.getElementById('file-input');
            const openBtn = document.getElementById('open-db-btn');
            openBtn.addEventListener('click', () => fileInput.click());
            fileInput.addEventListener('change', async (e) => {
                if (e.target.files && e.target.files.length > 0) {
                    await this.loadFile(e.target.files[0]);
                }
            });

            // Load default database button
            const loadDefaultBtn = document.getElementById('load-default-btn');
            loadDefaultBtn.addEventListener('click', () => this.bootstrapData());

            // User dropdown selector
            const userSelect = document.getElementById('user-select');
            userSelect.addEventListener('change', (e) => {
                const username = e.target.value;
                if (username) {
                    const data = this.loader.loadGraphForUser(username);
                    this.loadGraphData(data);
                }
            });

            // Search input
            const searchInput = document.getElementById('search-input');
            const searchDropdown = document.getElementById('search-dropdown');

            searchInput.addEventListener('input', (e) => {
                const q = e.target.value.trim().toLowerCase();
                if (!q) {
                    searchDropdown.style.display = 'none';
                    return;
                }

                const matches = this.nodes
                    .filter(n => n.name.toLowerCase().includes(q) || n.id.toLowerCase().includes(q) || n.entityType.toLowerCase().includes(q))
                    .slice(0, 10);

                if (matches.length === 0) {
                    searchDropdown.style.display = 'none';
                    return;
                }

                searchDropdown.innerHTML = matches.map(m => `
                    <div class="search-result-item" data-id="${m.id}">
                        <span class="search-result-name">${m.name}</span>
                        <span class="search-result-type" style="background:${hexToCss(getNodeColor(m.entityType))}25; color:${hexToCss(getNodeColor(m.entityType))}">${m.entityType}</span>
                    </div>
                `).join('');
                searchDropdown.style.display = 'block';
            });

            searchDropdown.addEventListener('click', (e) => {
                const item = e.target.closest('.search-result-item');
                if (item) {
                    const id = item.getAttribute('data-id');
                    const node = this.nodeMap.get(id);
                    if (node) {
                        this.selectNode(node);
                        this.flyToNode(node);
                    }
                    searchDropdown.style.display = 'none';
                    searchInput.value = node ? node.name : '';
                }
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-box')) {
                    searchDropdown.style.display = 'none';
                }
            });

            // Layout Segmented Buttons
            document.querySelectorAll('.segment-btn[data-layout]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.segment-btn[data-layout]').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    this.setLayout(btn.getAttribute('data-layout'));
                });
            });

            // Left Dock Toggle Button
            const leftDock = document.getElementById('left-dock');
            const leftDockToggle = document.getElementById('left-dock-toggle');
            leftDockToggle.addEventListener('click', () => {
                leftDock.classList.toggle('collapsed');
                leftDockToggle.innerHTML = leftDock.classList.contains('collapsed') ? '&#9654;' : '&#9664;';
            });

            // Inspector Close Button
            const inspectorClose = document.getElementById('inspector-close');
            inspectorClose.addEventListener('click', () => this.deselectNode());

            // Inspector Action Buttons
            document.getElementById('action-focus-subgraph').addEventListener('click', () => {
                if (this.selectedNode) {
                    this.focusSubgraph(this.selectedNode);
                }
            });

            document.getElementById('action-fly-to').addEventListener('click', () => {
                if (this.selectedNode) {
                    this.flyToNode(this.selectedNode);
                }
            });

            document.getElementById('action-reset-focus').addEventListener('click', () => {
                this.resetSubgraphFocus();
            });

            // Auto-Rotate Toggle
            const autoRotateSwitch = document.getElementById('toggle-auto-rotate');
            autoRotateSwitch.addEventListener('change', (e) => {
                this.autoRotate = e.target.checked;
            });

            // Labels Toggle
            const labelsSelect = document.getElementById('labels-select');
            labelsSelect.addEventListener('change', (e) => {
                this.showLabels = e.target.value;
                this.updateLabelsVisibility();
            });

            // Physics Sliders
            document.getElementById('slider-repulsion').addEventListener('input', (e) => {
                this.physicsParams.repulsion = Number(e.target.value);
                document.getElementById('val-repulsion').textContent = e.target.value;
            });
            document.getElementById('slider-spring').addEventListener('input', (e) => {
                this.physicsParams.springStrength = Number(e.target.value) / 1000;
                document.getElementById('val-spring').textContent = e.target.value;
            });
            document.getElementById('slider-gravity').addEventListener('input', (e) => {
                this.physicsParams.gravity = Number(e.target.value) / 1000;
                document.getElementById('val-gravity').textContent = e.target.value;
            });

            // Galaxy Rotation Speed
            document.getElementById('slider-orbit-speed').addEventListener('input', (e) => {
                this.orbitSpeed = Number(e.target.value);
                document.getElementById('val-orbit-speed').textContent = e.target.value + '×';
            });

            // Reset Camera View Button
            document.getElementById('reset-view-btn').addEventListener('click', () => this.resetView());

            // Play / Pause Simulation Button
            const playSimBtn = document.getElementById('toggle-physics-btn');
            playSimBtn.addEventListener('click', () => this.togglePhysics());

            // Timeline Scrubber
            const timelineSlider = document.getElementById('timeline-slider');
            const timelineDate = document.getElementById('timeline-date');
            timelineSlider.addEventListener('input', (e) => {
                const ts = Number(e.target.value);
                this.activeFilters.timelineTimestamp = ts;
                const d = new Date(ts * 1000);
                timelineDate.textContent = d.toISOString().slice(0, 10);
                this.applyFilters();
            });
        }

        /**
         * Load database from File object
         */
        async loadFile(file) {
            try {
                this.updateStatus(`Loading file ${file.name}...`);
                let graphData;
                if (file.name.endsWith('.json')) {
                    const text = await file.text();
                    const json = JSON.parse(text);
                    graphData = this.loader.loadFromJson(json, file.name);
                } else {
                    graphData = await this.loader.loadFromFile(file);
                }
                this.loadGraphData(graphData);
            } catch (err) {
                alert(`Failed to load database: ${err.message}`);
                console.error(err);
            }
        }

        /**
         * Attempt to load preloaded default sqlite file or fallback json
         */
        async bootstrapData() {
            try {
                this.updateStatus('Checking for local dataset...');
                // Try sqlite file first
                const data = await this.loader.loadFromUrl('./data/memory.sqlite', 'memory.sqlite');
                this.loadGraphData(data);
            } catch (sqliteErr) {
                console.warn('Could not fetch ./data/memory.sqlite, falling back to ./data/sample_graph.json', sqliteErr);
                try {
                    const res = await fetch('./data/sample_graph.json');
                    if (res.ok) {
                        const json = await res.json();
                        const data = this.loader.loadFromJson(json, 'sample_graph.json');
                        this.loadGraphData(data);
                    }
                } catch (jsonErr) {
                    this.updateStatus('Ready. Drop any memory.sqlite file here to explore!');
                }
            }
        }

        /**
         * Build 3D Scene from Loaded Graph Data
         */
        loadGraphData(graphData) {
            this.updateStatus('Constructing 3D scene...');

            // Clear existing scene elements.
            // NOTE: label sprites are children of the node meshes — dispose their
            // material + canvas texture here (their geometry is a Three.js-wide
            // shared sprite quad and must NOT be disposed).
            while (this.nodeGroup.children.length > 0) {
                const obj = this.nodeGroup.children[0];
                obj.children.forEach(child => {
                    if (child.material) {
                        if (child.material.map) child.material.map.dispose();
                        child.material.dispose();
                    }
                });
                this.nodeGroup.remove(obj);
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material) obj.material.dispose();
            }
            while (this.edgeGroup.children.length > 0) {
                const obj = this.edgeGroup.children[0];
                this.edgeGroup.remove(obj);
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material) obj.material.dispose();
            }
            while (this.particleGroup.children.length > 0) {
                const obj = this.particleGroup.children[0];
                this.particleGroup.remove(obj);
                if (obj.geometry) obj.geometry.dispose();
                if (obj.material) obj.material.dispose();
            }

            this.nodes = [];
            this.edges = [];
            this.nodeMap.clear();
            this.edgeParticles = [];

            // Update user selector dropdown
            const userSelect = document.getElementById('user-select');
            userSelect.innerHTML = graphData.users.map(u =>
                `<option value="${u}" ${u === graphData.username ? 'selected' : ''}>👤 ${u}</option>`
            ).join('');

            // Build Node Objects
            const sharedSphereGeo = new THREE.SphereGeometry(1, 16, 16);

            graphData.entities.forEach(entity => {
                // Sizing based on degree
                const radius = Math.max(3.5, Math.min(18, 3.5 + Math.sqrt(entity.degree || 0) * 2.2));
                const colorHex = getNodeColor(entity.entityType);

                const material = new THREE.MeshStandardMaterial({
                    color: colorHex,
                    emissive: colorHex,
                    emissiveIntensity: entity.degree > 10 ? 0.45 : 0.25,
                    roughness: 0.35,
                    metalness: 0.2
                });

                const mesh = new THREE.Mesh(sharedSphereGeo, material);
                mesh.scale.set(radius, radius, radius);

                // Initial 3D position (spherical cloud)
                const phi = Math.acos(-1 + (2 * Math.random()));
                const theta = Math.sqrt(graphData.entities.length * Math.PI) * phi;
                const dist = 100 + Math.random() * 320;

                const node = {
                    ...entity,
                    radius: radius,
                    colorHex: colorHex,
                    mesh: mesh,
                    position: new THREE.Vector3(
                        dist * Math.cos(theta) * Math.sin(phi),
                        dist * Math.sin(theta) * Math.sin(phi),
                        dist * Math.cos(phi)
                    ),
                    vx: 0,
                    vy: 0,
                    vz: 0,
                    visible: true,
                    labelSprite: null
                };

                mesh.position.copy(node.position);
                mesh.userData = { node: node };
                this.nodeGroup.add(mesh);

                // Create Billboard Text Sprite
                const labelSprite = this.createTextSprite(node.name, colorHex);
                labelSprite.position.set(0, radius + 5, 0);
                labelSprite.visible = this.showLabels === 'all' || (this.showLabels === 'hubs' && node.degree >= 5);
                mesh.add(labelSprite);
                node.labelSprite = labelSprite;

                this.nodes.push(node);
                this.nodeMap.set(node.id, node);
            });

            // Build Edges
            const linePositions = [];
            const lineColors = [];

            graphData.relations.forEach(rel => {
                const sourceNode = this.nodeMap.get(rel.source);
                const targetNode = this.nodeMap.get(rel.target);

                if (sourceNode && targetNode) {
                    const edge = {
                        ...rel,
                        sourceNode: sourceNode,
                        targetNode: targetNode,
                        visible: true
                    };
                    this.edges.push(edge);
                }
            });

            // Edge geometry via LineSegments for 60 FPS performance
            const edgeGeo = new THREE.BufferGeometry();
            const positions = new Float32Array(this.edges.length * 6);
            const colors = new Float32Array(this.edges.length * 6);

            for (let i = 0; i < this.edges.length; i++) {
                const edge = this.edges[i];
                const c1 = new THREE.Color(edge.sourceNode.colorHex);
                const c2 = new THREE.Color(edge.targetNode.colorHex);

                colors[i * 6] = c1.r * 0.7;
                colors[i * 6 + 1] = c1.g * 0.7;
                colors[i * 6 + 2] = c1.b * 0.7;
                colors[i * 6 + 3] = c2.r * 0.7;
                colors[i * 6 + 4] = c2.g * 0.7;
                colors[i * 6 + 5] = c2.b * 0.7;
            }

            edgeGeo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
            edgeGeo.setAttribute('color', new THREE.BufferAttribute(colors, 3));

            const edgeMat = new THREE.LineBasicMaterial({
                vertexColors: true,
                transparent: true,
                opacity: 0.35,
                depthWrite: false
            });

            this.edgeLines = new THREE.LineSegments(edgeGeo, edgeMat);
            this.edgeGroup.add(this.edgeLines);

            // Create Animated Energy Particles
            this.initEnergyParticles();

            // Populate Entity Type Filter List in UI
            this.buildFilterUI(graphData);

            // Configure Timeline Bounds
            const timelineSlider = document.getElementById('timeline-slider');
            const timelineDate = document.getElementById('timeline-date');
            timelineSlider.min = graphData.minTimestamp;
            timelineSlider.max = graphData.maxTimestamp;
            timelineSlider.value = graphData.maxTimestamp;
            this.activeFilters.timelineTimestamp = graphData.maxTimestamp;
            timelineDate.textContent = new Date(graphData.maxTimestamp * 1000).toISOString().slice(0, 10);

            // Update Header Stats
            document.getElementById('status-node-count').textContent = `${graphData.summary.totalEntities} NODES`;
            document.getElementById('status-edge-count').textContent = `${graphData.summary.totalRelations} LINKS`;
            document.getElementById('current-db-name').textContent = graphData.databaseName || 'memory.sqlite';

            this.updateStatus(`Visualizing ${this.nodes.length} nodes and ${this.edges.length} links.`);
            this.applyCurrentLayout();
        }

        /**
         * Billboard Text Sprite Generator
         */
        createTextSprite(text, colorHex) {
            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 64;
            const ctx = canvas.getContext('2d');

            ctx.font = 'bold 24px Inter, -apple-system, sans-serif';
            ctx.fillStyle = hexToCss(colorHex);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            // Glow / shadow
            ctx.shadowColor = 'rgba(0,0,0,0.8)';
            ctx.shadowBlur = 6;
            ctx.fillText(text.length > 20 ? text.slice(0, 18) + '…' : text, 128, 32);

            const texture = new THREE.CanvasTexture(canvas);
            texture.minFilter = THREE.LinearFilter;

            const material = new THREE.SpriteMaterial({
                map: texture,
                transparent: true,
                opacity: 0.85,
                depthWrite: false
            });

            const sprite = new THREE.Sprite(material);
            sprite.scale.set(38, 9.5, 1);
            return sprite;
        }

        /**
         * Animated Energy Pulses traversing links
         */
        initEnergyParticles() {
            const particleCount = Math.min(this.edges.length, this.maxParticles);
            this.edgeParticles = [];

            const pGeo = new THREE.BufferGeometry();
            const pPositions = new Float32Array(particleCount * 3);
            const pColors = new Float32Array(particleCount * 3);

            for (let i = 0; i < particleCount; i++) {
                const edge = this.edges[Math.floor(Math.random() * this.edges.length)];
                this.edgeParticles.push({
                    edge: edge,
                    progress: Math.random(),
                    speed: 0.003 + Math.random() * 0.006
                });

                const c = new THREE.Color(edge.sourceNode.colorHex);
                pColors[i * 3] = c.r;
                pColors[i * 3 + 1] = c.g;
                pColors[i * 3 + 2] = c.b;
            }

            pGeo.setAttribute('position', new THREE.BufferAttribute(pPositions, 3));
            pGeo.setAttribute('color', new THREE.BufferAttribute(pColors, 3));

            const pMat = new THREE.PointsMaterial({
                size: 3.5,
                vertexColors: true,
                transparent: true,
                opacity: 0.9,
                blending: THREE.AdditiveBlending
            });

            this.particlePoints = new THREE.Points(pGeo, pMat);
            this.particleGroup.add(this.particlePoints);
        }

        /**
         * Populate Left Filter Dock UI
         */
        buildFilterUI(graphData) {
            const filterContainer = document.getElementById('entity-type-filters');
            filterContainer.innerHTML = '';
            this.activeFilters.types.clear();

            Object.entries(graphData.entityTypeCounts)
                .sort((a, b) => b[1] - a[1])
                .forEach(([type, count]) => {
                    this.activeFilters.types.add(type);
                    const color = hexToCss(getNodeColor(type));

                    const item = document.createElement('div');
                    item.className = 'type-filter-item active';
                    item.innerHTML = `
                        <div class="type-filter-left">
                            <span class="color-dot" style="background:${color}; box-shadow:0 0 6px ${color}"></span>
                            <span class="type-name">${type}</span>
                        </div>
                        <span class="type-count">${count}</span>
                    `;

                    item.addEventListener('click', () => {
                        if (this.activeFilters.types.has(type)) {
                            this.activeFilters.types.delete(type);
                            item.classList.remove('active');
                            item.style.opacity = '0.4';
                        } else {
                            this.activeFilters.types.add(type);
                            item.classList.add('active');
                            item.style.opacity = '1';
                        }
                        this.applyFilters();
                    });

                    filterContainer.appendChild(item);
                });
        }

        /**
         * Apply Active Filters (Types, Subgraph, Timeline)
         */
        applyFilters() {
            const targetTime = this.activeFilters.timelineTimestamp;
            const isolatedRoot = this.activeFilters.isolatedSubtreeRoot;

            // Set of allowed nodes based on isolated subtree if active
            let allowedNodeIds = null;
            if (isolatedRoot) {
                allowedNodeIds = new Set();
                allowedNodeIds.add(isolatedRoot.id);
                isolatedRoot.neighbors.forEach(n => allowedNodeIds.add(n.id));
            }

            this.nodes.forEach(node => {
                let visible = this.activeFilters.types.has(node.entityType);

                // Temporal validity
                if (targetTime && node.validFrom && node.validFrom > targetTime) {
                    visible = false;
                }
                if (targetTime && node.validTo && node.validTo <= targetTime) {
                    visible = false;
                }

                // Subgraph isolation
                if (allowedNodeIds && !allowedNodeIds.has(node.id)) {
                    visible = false;
                }

                node.visible = visible;
                node.mesh.visible = visible;
            });

            // Galaxy star layer: additive black == invisible, so zero hidden stars' colors
            if (this.galaxy && this.galaxy.starPts) {
                const colArr = this.galaxy.starPts.geometry.attributes.color.array;
                this.nodes.forEach(n => {
                    if (n.galIdx === null || n.galIdx === undefined) return;
                    const i3 = n.galIdx * 3;
                    const c = n.visible && n.galColor ? n.galColor : null;
                    colArr[i3] = c ? c[0] : 0;
                    colArr[i3 + 1] = c ? c[1] : 0;
                    colArr[i3 + 2] = c ? c[2] : 0;
                });
                this.galaxy.starPts.geometry.attributes.color.needsUpdate = true;
            }

            // Update Edges
            const linePositions = this.edgeLines.geometry.attributes.position.array;
            let pIdx = 0;

            for (let i = 0; i < this.edges.length; i++) {
                const edge = this.edges[i];
                const isEdgeVisible = edge.sourceNode.visible && edge.targetNode.visible;
                edge.visible = isEdgeVisible;

                if (isEdgeVisible) {
                    linePositions[pIdx++] = edge.sourceNode.position.x;
                    linePositions[pIdx++] = edge.sourceNode.position.y;
                    linePositions[pIdx++] = edge.sourceNode.position.z;
                    linePositions[pIdx++] = edge.targetNode.position.x;
                    linePositions[pIdx++] = edge.targetNode.position.y;
                    linePositions[pIdx++] = edge.targetNode.position.z;
                } else {
                    linePositions[pIdx++] = 0;
                    linePositions[pIdx++] = 0;
                    linePositions[pIdx++] = 0;
                    linePositions[pIdx++] = 0;
                    linePositions[pIdx++] = 0;
                    linePositions[pIdx++] = 0;
                }
            }

            this.edgeLines.geometry.attributes.position.needsUpdate = true;
        }

        /**
         * 3D Physics Simulation (Force-Directed)
         */
        stepPhysics() {
            if (!this.simulationRunning || this.currentLayout !== 'force') return;

            const nodes = this.nodes;
            const nodeCount = nodes.length;
            const p = this.physicsParams;

            // 1. Center Gravity
            for (let i = 0; i < nodeCount; i++) {
                const n = nodes[i];
                if (!n.visible) continue;
                n.vx -= n.position.x * p.gravity;
                n.vy -= n.position.y * p.gravity;
                n.vz -= n.position.z * p.gravity;
            }

            // 2. Node-Node Repulsion (Coulomb)
            for (let i = 0; i < nodeCount; i++) {
                const n1 = nodes[i];
                if (!n1.visible) continue;

                for (let j = i + 1; j < nodeCount; j++) {
                    const n2 = nodes[j];
                    if (!n2.visible) continue;

                    let dx = n1.position.x - n2.position.x;
                    let dy = n1.position.y - n2.position.y;
                    let dz = n1.position.z - n2.position.z;
                    let distSq = dx * dx + dy * dy + dz * dz;

                    if (distSq < 1) distSq = 1;
                    if (distSq < 120000) {
                        const dist = Math.sqrt(distSq);
                        const force = (p.repulsion / distSq);

                        const fx = (dx / dist) * force;
                        const fy = (dy / dist) * force;
                        const fz = (dz / dist) * force;

                        n1.vx += fx;
                        n1.vy += fy;
                        n1.vz += fz;
                        n2.vx -= fx;
                        n2.vy -= fy;
                        n2.vz -= fz;
                    }
                }
            }

            // 3. Link Spring Attraction (Hooke)
            const edges = this.edges;
            const edgeCount = edges.length;
            for (let i = 0; i < edgeCount; i++) {
                const edge = edges[i];
                if (!edge.visible) continue;

                const n1 = edge.sourceNode;
                const n2 = edge.targetNode;

                let dx = n2.position.x - n1.position.x;
                let dy = n2.position.y - n1.position.y;
                let dz = n2.position.z - n1.position.z;
                const dist = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;

                const delta = dist - p.springLength;
                const force = delta * p.springStrength;

                const fx = (dx / dist) * force;
                const fy = (dy / dist) * force;
                const fz = (dz / dist) * force;

                n1.vx += fx;
                n1.vy += fy;
                n1.vz += fz;
                n2.vx -= fx;
                n2.vy -= fy;
                n2.vz -= fz;
            }

            // 4. Update Positions & Damping
            for (let i = 0; i < nodeCount; i++) {
                const n = nodes[i];
                if (!n.visible || n === this.draggedNode) continue;

                n.vx *= p.damping;
                n.vy *= p.damping;
                n.vz *= p.damping;

                n.position.x += n.vx;
                n.position.y += n.vy;
                n.position.z += n.vz;

                n.mesh.position.copy(n.position);
            }

            // 5. Update Edge Lines
            const posArray = this.edgeLines.geometry.attributes.position.array;
            let idx = 0;
            for (let i = 0; i < edgeCount; i++) {
                const edge = edges[i];
                if (edge.visible) {
                    posArray[idx++] = edge.sourceNode.position.x;
                    posArray[idx++] = edge.sourceNode.position.y;
                    posArray[idx++] = edge.sourceNode.position.z;
                    posArray[idx++] = edge.targetNode.position.x;
                    posArray[idx++] = edge.targetNode.position.y;
                    posArray[idx++] = edge.targetNode.position.z;
                } else {
                    idx += 6;
                }
            }
            this.edgeLines.geometry.attributes.position.needsUpdate = true;
        }

        /**
         * Update Energy Particles Flow
         */
        updateParticles() {
            if (!this.particlePoints || this.edgeParticles.length === 0) return;

            const pPositions = this.particlePoints.geometry.attributes.position.array;
            for (let i = 0; i < this.edgeParticles.length; i++) {
                const p = this.edgeParticles[i];
                if (!p.edge.visible) {
                    pPositions[i * 3] = 0;
                    pPositions[i * 3 + 1] = 0;
                    pPositions[i * 3 + 2] = 0;
                    continue;
                }

                p.progress += p.speed;
                if (p.progress > 1) p.progress = 0;

                const p1 = p.edge.sourceNode.position;
                const p2 = p.edge.targetNode.position;

                pPositions[i * 3] = p1.x + (p2.x - p1.x) * p.progress;
                pPositions[i * 3 + 1] = p1.y + (p2.y - p1.y) * p.progress;
                pPositions[i * 3 + 2] = p1.z + (p2.z - p1.z) * p.progress;
            }
            this.particlePoints.geometry.attributes.position.needsUpdate = true;
        }

        /**
         * Apply Alternative Layout Presets (contextual controls: show only what acts)
         */
        setLayout(layoutType) {
            const prev = this.currentLayout;
            this.currentLayout = layoutType;

            const speedGroup = document.getElementById('orbit-speed-group');
            if (speedGroup) speedGroup.style.display = layoutType === 'galaxy' ? '' : 'none';

            // The physics sliders only act on the force layout — hide dead controls
            const physicsDetails = document.getElementById('physics-details');
            if (physicsDetails) physicsDetails.hidden = layoutType !== 'force';

            const labelsSelect = document.getElementById('labels-select');
            if (layoutType === 'galaxy' && prev !== 'galaxy') {
                // Cleanest first impression of the galaxy is label-free; remember the choice
                this._preGalaxyLabels = this.showLabels;
                this.showLabels = 'none';
                if (labelsSelect) labelsSelect.value = 'none';
                this.frameCamera(0, 560, 620); // ~42° elevation: the flattened disk needs altitude
            } else if (prev === 'galaxy' && layoutType !== 'galaxy') {
                // Restore the pre-galaxy choice only if the user didn't change it meanwhile
                if (this._preGalaxyLabels && this.showLabels === 'none') {
                    this.showLabels = this._preGalaxyLabels;
                    if (labelsSelect) labelsSelect.value = this._preGalaxyLabels;
                }
                this.frameCamera(0, 350, 750);
            }
            this.updateLabelsVisibility();

            this.applyCurrentLayout();
        }

        /** Fly the camera to a framing position with the origin as target */
        frameCamera(x, y, z) {
            this.cameraAnimation = {
                startTime: performance.now(),
                duration: 1400,
                startPos: this.camera.position.clone(),
                endPos: new THREE.Vector3(x, y, z),
                startTarget: this.controls.target.clone(),
                endTarget: new THREE.Vector3(0, 0, 0)
            };
        }

        applyCurrentLayout() {
            // Relation lines are structural guides elsewhere, faint gas filaments here
            if (this.edgeLines) {
                this.edgeLines.material.opacity = this.currentLayout === 'galaxy' ? 0.10 : 0.35;
            }

            if (this.currentLayout === 'force') {
                this.clearGalaxy();
                this.simulationRunning = true;
                return;
            }

            if (this.currentLayout === 'galaxy') {
                // Live galaxy: root project as the core, hierarchy as spiral-arm stars
                this.buildGalaxy();
                this.simulationRunning = true;
                this.syncEdgePositions();
                return;
            }

            this.clearGalaxy();
            this.simulationRunning = false;
            const nodes = this.nodes;
            const nodeCount = nodes.length;

            if (this.currentLayout === 'sphere') {
                // Spherical Geodesic Layout
                const radius = 380;
                for (let i = 0; i < nodeCount; i++) {
                    const phi = Math.acos(-1 + (2 * i) / nodeCount);
                    const theta = Math.sqrt(nodeCount * Math.PI) * phi;

                    nodes[i].position.set(
                        radius * Math.cos(theta) * Math.sin(phi),
                        radius * Math.sin(theta) * Math.sin(phi),
                        radius * Math.cos(phi)
                    );
                    nodes[i].mesh.position.copy(nodes[i].position);
                }
            }

            // Sync edge positions
            this.syncEdgePositions();
        }

        /**
         * Build the Galaxy layout: the root project burns at the origin as the
         * galactic core (warm glow bulge + point light) and every other node is a
         * star riding one of 2-3 logarithmic spiral arms derived from its BFS parent.
         * The disk is tilted and turns under near-flat rotation (omega = V0/(r+R_CORE))
         * so the arms shear gently but never wind themselves into mush.
         */
        buildGalaxy() {
            this.clearGalaxy();
            const nodes = this.nodes;
            if (nodes.length === 0) return;

            // --- Spiral constants ---
            const ARMS = nodes.length >= 80 ? 3 : 2;
            const WIND = 1.9;      // arm pitch: radians of twist per ln(1 + r / R0)
            const R0 = 130;        // log-spiral scale radius
            const R_CORE = 700;    // rotation core radius: large = near-flat omega profile
            const V0 = 80;         // angular speed factor: omega = V0 / (r + R_CORE)
            const TILT = new THREE.Quaternion().setFromEuler(new THREE.Euler(0.38, 0.25, 0.12));
            const gauss = () => Math.random() + Math.random() + Math.random() - 1.5; // ~N(0, 0.5)

            // 1. Pick the core — the dominant `project` node (root project), else the top hub
            const projects = nodes.filter(n => n.entityType === 'project');
            const pickMaxDegree = arr => arr.reduce((a, b) => (b.degree > a.degree ? b : a));
            const core = projects.length > 0 ? pickMaxDegree(projects) : pickMaxDegree(nodes);

            // 2. BFS from the core to give each node a depth + parent (arm assignment
            //    follows this skeleton so a family clumps along one arm)
            const adj = new Map(nodes.map(n => [n.id, []]));
            this.edges.forEach(e => {
                if (adj.has(e.sourceNode.id) && adj.has(e.targetNode.id)) {
                    adj.get(e.sourceNode.id).push(e.targetNode);
                    adj.get(e.targetNode.id).push(e.sourceNode);
                }
            });

            const dist = new Map([[core.id, 0]]);
            const parentOf = new Map();
            const queue = [core];
            while (queue.length) {
                const cur = queue.shift();
                const d = dist.get(cur.id);
                for (const nb of adj.get(cur.id)) {
                    if (!dist.has(nb.id)) {
                        dist.set(nb.id, d + 1);
                        parentOf.set(nb.id, cur);
                        queue.push(nb);
                    }
                }
            }

            // 3. Assign every node an arm, a galactocentric radius, an azimuth and y.
            //    The tilt is baked per-position at step time (NOT on a parent group)
            //    because edge lines are rebuilt from world-space node positions.
            const assign = (node, r, arm) => {
                node.gal = {
                    r: r,
                    phi: arm * (Math.PI * 2 / ARMS) + WIND * Math.log(1 + r / R0) + gauss() * (0.30 + 0.25 * (r / 600)),
                    y: gauss() * (52 / (1 + r / 150)),   // thick bulge, thin outer disk
                    omega: V0 / (r + R_CORE),
                    arm: arm
                };
            };

            // Inner arm stars: direct neighbors of the core, biggest hubs closest in
            const inner = nodes
                .filter(n => dist.get(n.id) === 1)
                .sort((a, b) => b.degree - a.degree);
            inner.forEach((n, i) => {
                const r = inner.length > 1 ? 140 + (i / (inner.length - 1)) * 400 : 170;
                assign(n, r + gauss() * 14, i % ARMS);
            });

            // Deeper stars inherit their BFS parent's arm and sit a little further out
            const rest = nodes
                .filter(n => dist.has(n.id) && dist.get(n.id) >= 2)
                .sort((a, b) => dist.get(a.id) - dist.get(b.id));
            rest.forEach(n => {
                const par = parentOf.get(n.id);
                const pr = par.gal ? par.gal.r : 60;
                const arm = (par.gal && par.gal.arm >= 0) ? par.gal.arm : Math.floor(Math.random() * ARMS);
                assign(n, Math.min(pr + 62 + Math.abs(gauss()) * 30, 660), arm);
            });

            // Orphan nodes drift in a dim outer ring on spread-out arms
            let beltIdx = 0;
            nodes.forEach(n => {
                if (n === core || dist.has(n.id)) return;
                assign(n, 560 + Math.random() * 120, beltIdx % ARMS);
                beltIdx++;
            });

            // 4. Core treatment: enlarged warm hub, breathing glow bulge, point light
            const coreRadius = Math.max(22, core.radius * 1.6);
            const glow = new THREE.Sprite(new THREE.SpriteMaterial({
                map: this.makeGlowTexture(),
                transparent: true,
                blending: THREE.AdditiveBlending,
                depthWrite: false
            }));
            glow.scale.set(coreRadius * 6, coreRadius * 6, 1);
            this.orbitGroup.add(glow);

            const coreLight = new THREE.PointLight(0xffd9a0, 1.5, 2400, 1);
            this.orbitGroup.add(coreLight);

            core.position.set(0, 0, 0);
            core.mesh.position.set(0, 0, 0);
            core.mesh.scale.set(coreRadius, coreRadius, coreRadius);
            core.mesh.material.emissiveIntensity = 1.0;
            if (core.labelSprite) core.labelSprite.position.set(0, coreRadius + 9, 0);
            core.gal = null;

            // 5. Star layer — ALL node glows in ONE shared-texture Points draw call.
            //    Filtering hides stars by zeroing their additive color (see applyFilters).
            const starNodes = nodes.filter(n => n !== core);
            const sPos = new Float32Array(starNodes.length * 3);
            const sCol = new Float32Array(starNodes.length * 3);
            starNodes.forEach((n, i) => {
                n.galIdx = i;
                const c = new THREE.Color(n.colorHex);
                const b = 0.6 + Math.min(1, (n.degree || 0) / 12) * 0.9; // hubs burn brighter
                n.galColor = [c.r * b, c.g * b, c.b * b];
                // Respect filters active at build time (applyFilters re-syncs later)
                const vis = n.visible !== false;
                sCol[i * 3] = vis ? n.galColor[0] : 0;
                sCol[i * 3 + 1] = vis ? n.galColor[1] : 0;
                sCol[i * 3 + 2] = vis ? n.galColor[2] : 0;
            });
            const starGeo = new THREE.BufferGeometry();
            starGeo.setAttribute('position', new THREE.BufferAttribute(sPos, 3));
            starGeo.setAttribute('color', new THREE.BufferAttribute(sCol, 3));
            const starPts = new THREE.Points(starGeo, new THREE.PointsMaterial({
                size: 26,
                map: this.makeStarTexture(),
                vertexColors: true,
                transparent: true,
                blending: THREE.AdditiveBlending,
                depthWrite: false
            }));
            this.orbitGroup.add(starPts);

            // 6. Dust haze — faint static-ish stars seeded from the same arm function,
            //    selling disk volume for one extra draw call.
            const DUST = 1400;
            const dust = [];
            const dPos = new Float32Array(DUST * 3);
            const dCol = new Float32Array(DUST * 3);
            for (let i = 0; i < DUST; i++) {
                const r = 55 + Math.sqrt(Math.random()) * 625;
                const arm = Math.floor(Math.random() * ARMS);
                let phi = arm * (Math.PI * 2 / ARMS) + WIND * Math.log(1 + r / R0) + gauss() * 0.5;
                if (Math.random() < 0.3) phi = Math.random() * Math.PI * 2; // inter-arm haze
                dust.push({ r: r, phi: phi, y: gauss() * (70 / (1 + r / 160)), omega: V0 / (r + R_CORE) });
                const t = Math.random();
                const b = 0.10 + Math.random() * 0.16;
                dCol[i * 3] = (0.6 + t * 0.4) * b;      // blue-white to faint violet
                dCol[i * 3 + 1] = (0.7 + t * 0.2) * b;
                dCol[i * 3 + 2] = 1.0 * b;
            }
            const dustGeo = new THREE.BufferGeometry();
            dustGeo.setAttribute('position', new THREE.BufferAttribute(dPos, 3));
            dustGeo.setAttribute('color', new THREE.BufferAttribute(dCol, 3));
            const dustPts = new THREE.Points(dustGeo, new THREE.PointsMaterial({
                size: 9,
                map: this.makeStarTexture(),
                vertexColors: true,
                transparent: true,
                blending: THREE.AdditiveBlending,
                depthWrite: false
            }));
            this.orbitGroup.add(dustPts);

            // 7. Shrink node meshes under their star glow so the disk reads as stars
            starNodes.forEach(n => n.mesh.scale.setScalar(n.radius * 0.55));

            this.galaxy = {
                core: core, order: starNodes, starPts: starPts, dustPts: dustPts, dust: dust,
                glow: glow, light: coreLight, tiltQ: TILT, glowBase: coreRadius * 6, pulse: 0
            };

            this.stepGalaxy(0); // first frame already shows the galaxy, no pop-in
        }

        clearGalaxy() {
            if (!this.galaxy) return;
            const g = this.galaxy;

            [g.starPts, g.dustPts].forEach(p => {
                this.orbitGroup.remove(p);
                p.geometry.dispose();
                p.material.dispose(); // map is the SHARED cached texture — never disposed here
            });
            this.orbitGroup.remove(g.glow);
            g.glow.material.dispose(); // glow map is cached too — keep it
            this.orbitGroup.remove(g.light);

            // Undo every visual mutation on ALL nodes (core was enlarged, the rest shrunk)
            this.nodes.forEach(n => {
                n.gal = null;
                n.galIdx = null;
                n.galColor = null;
                n.mesh.scale.setScalar(n.radius);
                if (n.mesh.material) {
                    n.mesh.material.emissiveIntensity = n.degree > 10 ? 0.45 : 0.25;
                }
                if (n.labelSprite) n.labelSprite.position.set(0, n.radius + 5, 0);
            });

            this.galaxy = null;
        }

        /**
         * Advance the galaxy one tick (dt in seconds). Positions are analytical —
         * every star computes its own tilted-disk coordinate, then edges re-sync.
         */
        stepGalaxy(dt) {
            const g = this.galaxy;
            if (!g) return;
            const t = dt * this.orbitSpeed;
            const tmp = this.tmpVec;

            const posArr = g.starPts.geometry.attributes.position.array;
            for (const node of g.order) {
                const a = node.gal;
                if (!a) continue;
                a.phi += a.omega * t;
                tmp.set(Math.cos(a.phi) * a.r, a.y, Math.sin(a.phi) * a.r).applyQuaternion(g.tiltQ);
                node.position.copy(tmp);
                node.mesh.position.copy(tmp);
                const i3 = node.galIdx * 3;
                posArr[i3] = tmp.x;
                posArr[i3 + 1] = tmp.y;
                posArr[i3 + 2] = tmp.z;
            }
            g.starPts.geometry.attributes.position.needsUpdate = true;

            const dArr = g.dustPts.geometry.attributes.position.array;
            for (let i = 0; i < g.dust.length; i++) {
                const d = g.dust[i];
                d.phi += d.omega * t;
                tmp.set(Math.cos(d.phi) * d.r, d.y, Math.sin(d.phi) * d.r).applyQuaternion(g.tiltQ);
                const i3 = i * 3;
                dArr[i3] = tmp.x;
                dArr[i3 + 1] = tmp.y;
                dArr[i3 + 2] = tmp.z;
            }
            g.dustPts.geometry.attributes.position.needsUpdate = true;

            // Core breathing halo + slow axial spin
            g.pulse += t;
            const k = 1 + 0.05 * Math.sin(g.pulse * 1.5);
            g.glow.scale.set(g.glowBase * k, g.glowBase * k, 1);
            g.core.mesh.rotation.y += t * 0.06;

            this.syncEdgePositions();
        }

        /**
         * Radial gradient texture for the galactic core's glow halo.
         * Cached — clearGalaxy() must never dispose this map.
         */
        makeGlowTexture() {
            if (this._glowTex) return this._glowTex;
            const size = 256;
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            const grad = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
            grad.addColorStop(0.0, 'rgba(255, 244, 214, 0.95)');
            grad.addColorStop(0.22, 'rgba(255, 206, 128, 0.55)');
            grad.addColorStop(0.55, 'rgba(110, 160, 255, 0.16)');
            grad.addColorStop(1.0, 'rgba(0, 0, 0, 0)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, size, size);
            const tex = new THREE.CanvasTexture(canvas);
            tex.minFilter = THREE.LinearFilter;
            this._glowTex = tex;
            return tex;
        }

        /**
         * Soft white star dot shared by the galaxy's star + dust Points layers.
         * One texture, two draw calls; per-star tint comes from vertex colors.
         */
        makeStarTexture() {
            if (this._starTex) return this._starTex;
            const size = 128;
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            const grad = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
            grad.addColorStop(0.0, 'rgba(255, 255, 255, 1.0)');
            grad.addColorStop(0.25, 'rgba(255, 255, 255, 0.5)');
            grad.addColorStop(0.5, 'rgba(255, 255, 255, 0.12)');
            grad.addColorStop(1.0, 'rgba(255, 255, 255, 0)');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, size, size);
            const tex = new THREE.CanvasTexture(canvas);
            tex.minFilter = THREE.LinearFilter;
            this._starTex = tex;
            return tex;
        }

        syncEdgePositions() {
            const posArray = this.edgeLines.geometry.attributes.position.array;
            let idx = 0;
            for (let i = 0; i < this.edges.length; i++) {
                const edge = this.edges[i];
                if (edge.visible) {
                    posArray[idx++] = edge.sourceNode.position.x;
                    posArray[idx++] = edge.sourceNode.position.y;
                    posArray[idx++] = edge.sourceNode.position.z;
                    posArray[idx++] = edge.targetNode.position.x;
                    posArray[idx++] = edge.targetNode.position.y;
                    posArray[idx++] = edge.targetNode.position.z;
                } else {
                    // Collapse hidden edges to a degenerate point (no stale segments)
                    idx += 6;
                    for (let k = idx - 6; k < idx; k++) posArray[k] = 0;
                }
            }
            this.edgeLines.geometry.attributes.position.needsUpdate = true;
        }

        /**
         * Node Selection & Inspector Binding
         */
        selectNode(node) {
            this.selectedNode = node;

            // Highlight node mesh with glow
            this.nodes.forEach(n => {
                const isSelected = n === node;
                const isNeighbor = node.neighbors.some(neighbor => neighbor.id === n.id);

                if (isSelected) {
                    n.mesh.material.emissiveIntensity = 0.9;
                } else if (isNeighbor) {
                    n.mesh.material.emissiveIntensity = 0.5;
                } else {
                    n.mesh.material.emissiveIntensity = 0.15;
                }
            });

            // Populate Inspector Drawer
            const drawer = document.getElementById('inspector-drawer');
            drawer.classList.add('open');

            document.getElementById('inspector-node-name').textContent = node.name;
            const badge = document.getElementById('inspector-node-type');
            badge.textContent = node.entityType;
            badge.style.background = `${hexToCss(node.colorHex)}25`;
            badge.style.color = hexToCss(node.colorHex);

            document.getElementById('inspector-degree').textContent = node.degree;
            document.getElementById('inspector-valid-from').textContent = node.validFrom ? new Date(node.validFrom * 1000).toISOString().slice(0, 10) : '—';
            document.getElementById('inspector-valid-to').textContent = node.validTo ? new Date(node.validTo * 1000).toISOString().slice(0, 10) : 'Active';

            // Neighbors List
            const neighborList = document.getElementById('inspector-neighbors');
            neighborList.innerHTML = node.neighbors.map(n => `
                <div class="neighbor-item" data-id="${n.id}">
                    <span class="neighbor-name">${n.name}</span>
                    <span class="neighbor-relation">${n.relation} (${n.direction === 'outgoing' ? '→' : '←'})</span>
                </div>
            `).join('') || '<p style="color:var(--text-muted)">No direct connections</p>';

            neighborList.querySelectorAll('.neighbor-item').forEach(item => {
                item.addEventListener('click', () => {
                    const neighborId = item.getAttribute('data-id');
                    const neighborNode = this.nodeMap.get(neighborId);
                    if (neighborNode) {
                        this.viewNode(neighborNode);
                    }
                });
            });

            // Observations List
            const obsList = document.getElementById('inspector-observations');
            if (node.observations && node.observations.length > 0) {
                obsList.innerHTML = node.observations.map(obs => `
                    <div class="obs-card">${this.escapeHtml(obs)}</div>
                `).join('');
            } else {
                obsList.innerHTML = '<p style="color:var(--text-muted)">No recorded observations</p>';
            }
        }

        deselectNode() {
            this.selectedNode = null;
            document.getElementById('inspector-drawer').classList.remove('open');
            this.nodes.forEach(n => {
                const isCore = this.galaxy && this.galaxy.core === n;
                n.mesh.material.emissiveIntensity = isCore ? 1.0 : (n.degree > 10 ? 0.45 : 0.25);
            });
        }

        setHoveredNode(node) {
            this.hoveredNode = node;
        }

        /**
         * View Node (Called on Double Click or Selection)
         * Focuses, opens inspector, flies camera close, and plays visual pulse
         */
        viewNode(node) {
            if (!node) return;
            this.selectNode(node);
            this.flyToNode(node);
            this.pulseNode(node);
            this.updateStatus(`Viewing node: ${node.name} (${node.entityType})`);
        }

        /**
         * Visual Feedback Pulse Animation for Activated Node
         */
        pulseNode(node) {
            if (!node || !node.mesh) return;
            const originalScale = node.mesh.scale.x; // current scale (core is enlarged in galaxy layout)
            const startTime = performance.now();
            const duration = 650;

            const pulseAnim = () => {
                const elapsed = performance.now() - startTime;
                const progress = Math.min(1, elapsed / duration);
                // Pulse sine wave: 1 -> 1.4 -> 1
                const scaleFactor = 1 + 0.4 * Math.sin(progress * Math.PI);
                const s = originalScale * scaleFactor;
                node.mesh.scale.set(s, s, s);

                // Emissive flash
                if (node.mesh.material) {
                    node.mesh.material.emissiveIntensity = 0.9 + 0.5 * Math.sin(progress * Math.PI);
                }

                if (progress < 1) {
                    requestAnimationFrame(pulseAnim);
                } else {
                    node.mesh.scale.set(originalScale, originalScale, originalScale);
                    if (node.mesh.material) {
                        node.mesh.material.emissiveIntensity = 0.9;
                    }
                }
            };
            requestAnimationFrame(pulseAnim);
        }

        /**
         * Smooth Camera Fly-To Animation
         */
        flyToNode(node) {
            const targetPos = node.position.clone();
            const startPos = this.camera.position.clone();
            const startTarget = this.controls.target.clone();

            // Offset camera back along view vector
            const offset = startPos.clone().sub(startTarget).normalize().multiplyScalar(160);
            const endPos = targetPos.clone().add(offset);

            const duration = 1200;
            const startTime = performance.now();

            this.cameraAnimation = {
                startTime,
                duration,
                startPos,
                endPos,
                startTarget,
                endTarget: targetPos
            };
        }

        updateCameraAnimation() {
            if (!this.cameraAnimation) return;

            const now = performance.now();
            const elapsed = now - this.cameraAnimation.startTime;
            const progress = Math.min(1, elapsed / this.cameraAnimation.duration);

            // Smooth cubic easing
            const t = progress < 0.5 ? 4 * progress * progress * progress : 1 - Math.pow(-2 * progress + 2, 3) / 2;

            this.camera.position.lerpVectors(this.cameraAnimation.startPos, this.cameraAnimation.endPos, t);
            this.controls.target.lerpVectors(this.cameraAnimation.startTarget, this.cameraAnimation.endTarget, t);

            if (progress >= 1) {
                this.cameraAnimation = null;
            }
        }

        /**
         * Subgraph Isolation & Focus
         */
        focusSubgraph(rootNode) {
            this.activeFilters.isolatedSubtreeRoot = rootNode;
            this.applyFilters();
            this.flyToNode(rootNode);
            document.getElementById('action-reset-focus').style.display = 'inline-flex';
        }

        resetSubgraphFocus() {
            this.activeFilters.isolatedSubtreeRoot = null;
            this.applyFilters();
            document.getElementById('action-reset-focus').style.display = 'none';
        }

        /**
         * Reset View to the framing appropriate for the active layout
         */
        resetView() {
            if (this.currentLayout === 'galaxy') {
                this.frameCamera(0, 560, 620);
            } else {
                this.frameCamera(0, 350, 750);
            }
        }

        togglePhysics() {
            this.simulationRunning = !this.simulationRunning;
            const btn = document.getElementById('toggle-physics-btn');
            btn.innerHTML = this.simulationRunning ? '⏸ Pause Simulation' : '▶ Run Simulation';
        }

        updateLabelsVisibility() {
            this.nodes.forEach(n => {
                if (!n.labelSprite) return;
                if (this.showLabels === 'all') {
                    n.labelSprite.visible = true;
                } else if (this.showLabels === 'hubs') {
                    n.labelSprite.visible = n.degree >= 5;
                } else {
                    n.labelSprite.visible = false;
                }
            });
        }

        updateStatus(msg) {
            const el = document.getElementById('app-status-msg');
            if (el) el.textContent = msg;
            console.log(`[Visualizer] ${msg}`);
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Main Render Loop
         */
        animate() {
            requestAnimationFrame(this.animate);

            const now = performance.now();
            const dt = Math.min(0.05, (now - this.lastFrameTime) / 1000);
            this.lastFrameTime = now;

            // After 6s of no input the HUD fades and the galaxy owns the screen
            if (now - this.lastHudActivity > 6000) {
                document.body.classList.add('hud-dim');
            }

            // Step Physics (force-directed) or the galaxy's differential rotation
            this.stepPhysics();
            if (this.currentLayout === 'galaxy' && this.galaxy && this.simulationRunning) {
                this.stepGalaxy(dt);
            }

            // Step Particles
            this.updateParticles();

            // Step Camera Animation
            this.updateCameraAnimation();

            // Auto-Rotate Mode
            if (this.autoRotate && !this.cameraAnimation) {
                this.controls.autoRotate = true;
                this.controls.autoRotateSpeed = 0.8;
            } else {
                this.controls.autoRotate = false;
            }

            // Slowly rotate cosmic starfield
            if (this.starfield) {
                this.starfield.rotation.y += 0.00015;
            }

            this.controls.update();
            this.renderer.render(this.scene, this.camera);
        }
    }

    // Launch on DOM ready
    window.addEventListener('DOMContentLoaded', () => {
        window.visualizer = new MemoryVisualizer3D();
    });

})();
