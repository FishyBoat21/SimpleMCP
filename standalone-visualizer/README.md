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
  - **Solar System**: The root project becomes a glowing sun at the origin. Direct neighbors orbit it as planets (larger worlds closer in, Kepler-like speeds), deeper graph levels circle their parent as moons, and disconnected nodes drift in an outer belt. Includes orbit rings, a pulsing corona, a warm point-light, and an adjustable Orbit Speed slider.
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
- **Click-and-drag node**: Move node in 3D space (3D Force layout only; positions are analytical in Solar System)
- `Space`: Pause / Resume physics simulation
- `R`: Reset camera view
- `Escape`: Deselect node / Close inspector drawer
- `/`: Focus search bar
