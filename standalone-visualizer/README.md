# SimpleMCP Standalone 3D Memory Visualizer

An interactive 3D WebGL knowledge graph visualizer built with **Three.js** and **SQLite WebAssembly (`sql.js`)**. It operates completely client-side and standalone, enabling you to open and explore any SimpleMCP `memory.sqlite` file with zero server setup required.

---

## 🚀 Quick Start

### Option 1: Direct Browser Launch
1. Double click `launch.bat` (or run `php -S localhost:8765` inside this directory).
2. Open `http://localhost:8765` in your browser.
3. Click **"Open memory.sqlite"** or drag and drop any `memory.sqlite` database file directly into the window.

### Option 2: Drag & Drop Any `memory.sqlite`
You can drag and drop any SQLite file (`.sqlite`, `.db`) onto the screen at any time. The in-browser WebAssembly SQLite engine will parse the tables, calculate connections, and render the graph in real-time.

---

## ✨ Features

- **Direct SQLite Parsing**: Runs client-side SQL queries over `memory_entities` and `memory_relations` without any backend server.
- **Dynamic 3D Force Simulation**: Real-time Coulomb repulsion, Hooke spring tension, and gravity physics.
- **Alternative 3D Layouts**:
  - **3D Force**: Organic clustering based on relation topology.
  - **Galaxy**: The root project burns at the origin as a glowing galactic core (pulsing bulge sprite + warm point-light) while every other entity becomes a star on 2-3 logarithmic spiral arms derived from graph hierarchy — big hubs closest in, families clumped along their parent's arm, orphans drifting in an outer haze ring. A single shared-texture `THREE.Points` layer renders all star glows in one draw call, a 1,400-point dust haze sells the disk volume, and near-flat differential rotation (`ω = v₀/(r + r_core)`) lets the arms shear gently without winding up. Edges dim to faint gas filaments; adjustable via the Rotation Speed slider.
- **Simplified Dock**: Physics Tuning collapses into a `<details>` section and auto-hides outside the 3D Force layout (no dead controls); the HUD fades to 22% opacity after 6s of inactivity so the galaxy owns the screen — any mouse or key input brings it back.
  - **Sphere**: Geodesic spherical distribution.
- **Degree-Scaled Nodes**: Entities scale according to connection counts, with glowing emissive materials and cybernetic color-coding.
- **Animated Energy Flows**: Photons travel along relation edges in the direction of the relationship (`from` → `to`).
- **Interactive Inspector Drawer**: Click any node to inspect observations, connected neighbors, and temporal timestamps. Click neighbor items to smoothly fly the camera to them.
- **Search with Camera Fly-To**: Instant autocomplete search across names, IDs, and entity types.
- **Temporal Time-Travel Scrubber**: Scrub backwards in time to inspect knowledge graph states based on `valid_from` / `valid_to`.
- **Multi-User Support**: If a database contains multiple users (`local`, `alice`, `admin`), seamlessly switch graphs using the user selector.

---

## 🎨 Entity Color Palette

| Entity Type | Color | Hex |
| :--- | :--- | :--- |
| **Project** | Vibrant Cyan | `#00f0ff` |
| **Class** | Neon Violet | `#a855f7` |
| **Tool** | Bright Emerald | `#10b981` |
| **File** | Tech Azure | `#38bdf8` |
| **Config** | Amber Gold | `#f59e0b` |
| **Decision** | Rose Pink | `#f43f5e` |
| **Concept** | Electric Lime | `#84cc16` |
| **Endpoint** | Fiery Orange | `#ff7849` |
| **Preference**| Magenta | `#ec4899` |

---

## ⌨️ Mouse & Keyboard Controls

- **Double-click node**: View node (focuses, flies camera close, opens inspector, and pulses node)
- **Double-click background**: Reset camera view
- **Single-click node**: Select node and open inspector drawer
- **Click-and-drag node**: Move node in 3D space (3D Force layout only; positions are analytical in Galaxy)
- `Space`: Pause / Resume physics simulation
- `R`: Reset camera view
- `Escape`: Deselect node / Close inspector drawer
- `/`: Focus search bar
