# SimpleMCP

A minimal, zero-dependency **Model Context Protocol (MCP) server** written in PHP ≥ 8.4.
It speaks JSON-RPC 2.0 and exposes tools to MCP clients such as Claude Desktop, Cherry
Studio, or Open WebUI. In HTTP mode it also ships a self-hosted **OAuth 2.1
Authorization Server** (Authorization Code + PKCE, dynamic client registration, and
discovery metadata) so clients can log in with real user accounts.

```sh
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}\n' | php index.php
```

## Features

- **MCP protocol** — `initialize`, `tools/list`, `tools/call`, `notifications/initialized`
  (protocol version `2024-11-05`).
- **Two transports** from a single entry point:
  - **stdio** — line-delimited JSON-RPC over stdin/stdout, how MCP clients launch the server.
  - **HTTP** — Streamable HTTP transport with optional `Mcp-Session-Id` handling.
- **Attribute-based tools** — drop a class in [src/Tools/](src/Tools/) with `#[McpFunction]`
  methods and it is auto-discovered; no manual registration.
- **Per-tool access control** — tools declare required `roles` / `permissions`; unauthorized
  tools are hidden from `tools/list` and rejected at `tools/call` with JSON-RPC `-32001`.
- **User context** — the current `UserContext` is injected into any tool method that asks
  for it. In stdio mode every request runs as a trusted `local` user with full access.
- **Self-hosted OAuth 2.1** — interactive login page, token endpoint, RFC 7591 dynamic
  client registration, RFC 8414 / RFC 9728 discovery. Tokens are sha256-hashed at rest.
- **User management page** (`/account`) — login, public onboarding, change password, logout.
- **SQLite storage** — users, OAuth clients, and tokens in `data/app.sqlite` (gitignored),
  seeded from [config/users.php](config/users.php) on first run.
- **No dependencies** — `composer.json` declares only `php >= 8.4`.

## Requirements

- PHP **≥ 8.4** (uses `json_validate()`, `match`, readonly classes, and union types).

## Quick start

### stdio mode (MCP clients)

MCP clients launch the server directly. A typical client config:

```json
{
  "mcpServers": {
    "simplemcp": {
      "command": "php",
      "args": ["D:\\Project\\SimpleMCP\\index.php"]
    }
  }
}
```

Smoke test:

```sh
printf '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}\n{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}\n' | php index.php
```

### HTTP mode (OAuth + browser login)

```sh
php -S localhost:8000 index.php
```

`index.php` must be the router script so `data/` is never served statically. The server then
exposes the OAuth endpoints, the `/account` page, and the MCP JSON-RPC endpoint (everything
else).

## Included tools

### Sample tools

Small examples that demonstrate the server's capabilities (attribute registration,
access control, and `UserContext` injection):

| Tool | Description | Access |
|------|-------------|--------|
| `add_numbers` | Addition of two numbers | public |
| `subtract_numbers` | Subtraction of two numbers | public |
| `multiply_numbers` | Multiplication of two numbers | public |
| `divide_numbers` | Division of two numbers (rounded to 2 dp) | public |
| `power_numbers` | Base raised to an exponent | public |
| `calculate_loan_installment` | Fixed monthly loan payment (PMT formula), with total paid and interest | public |
| `get_system_time` | Current server time (ISO 8601) | public |
| `get_current_user` | The authenticated caller (username, name, roles, permissions) | public |
| `server_status` | Server runtime info | **admin** (`roles: ['admin']`) |

### Document & Image Conversion (Stirling-PDF) tools

Document and image conversion via a configured Stirling-PDF server — requires the `user` or `admin`
role. The tools are only **listed and callable when a Stirling-PDF endpoint is configured**
for the caller; with none set they are disabled entirely (hidden from `tools/list`, and a
direct call is answered with tool-not-found).

| Tool | What it does |
|------|--------------|
| `convert_markdown_to_pdf` | Render a Markdown file to a PDF via the Stirling-PDF `/api/v1/convert/markdown/pdf` endpoint. Accepts an input file path (`path`) and exports the resulting PDF to the same directory with the same name (`<name>.pdf`). |
| `convert_pdf_to_markdown` | Extract Markdown text from a PDF file via the `/api/v1/convert/pdf/markdown` endpoint. Accepts an input file path (`path`), exports the extracted Markdown to the same directory with the same name (`<name>.md`), and returns the Markdown in the tool result. |
| `convert_image_to_pdf` | Convert an image file (PNG, JPG, WEBP, GIF, BMP, TIFF, SVG) to a PDF via the Stirling-PDF `/api/v1/convert/img/pdf` endpoint. Accepts an input file path (`path`) and exports the resulting PDF to the same directory with the same name (`<name>.pdf`). |
| `convert_pdf_to_image` | Convert a PDF file to image(s) via the Stirling-PDF `/api/v1/convert/pdf/img` endpoint. Accepts an input file path (`path`) and exports the resulting image(s) directly to the source directory (`<dir>/<name>.<format>` or unzipped page images `<dir>/<name>_<n>.<format>`). |

## Markdown → PDF (Stirling-PDF)

[src/StirlingPdfClient.php](src/StirlingPdfClient.php) converts a Markdown file to a PDF by
reading the file at `path`, POSTing it — as a multipart form upload (field `fileInput`) — to
`{endpoint}/api/v1/convert/markdown/pdf` on a Stirling-PDF instance, with the optional API key
sent as an `X-API-KEY` header. The request uses PHP streams (`file_get_contents`), so no extra
extension is needed.

### Where the PDF goes

The generated PDF is saved directly to the **same directory with the same base name** as the input file:
**`<dir>/<name>.pdf`** (e.g. `path/to/notes.md` → `path/to/notes.pdf`).

Connection settings are transport-aware:

- **HTTP (streamable) mode** — the signed-in account's values, saved on the **`/account` page**
  ("PDF conversion (Stirling-PDF)" section → `user_settings` table in `data/app.sqlite`). The API
  key is stored once and never rendered back (only a trailing-4-char hint); leave the field blank
  to keep the stored key. A blank endpoint clears the override and falls back to the global
  default.
- **stdio mode** — the global defaults from **[config/config.php](config/config.php)**
  (`stirling_pdf.endpoint` / `stirling_pdf.api_key`). There is no account page over stdio, so a
  blank endpoint **disables the tools** — they vanish from `tools/list` and a direct call is
  answered with tool-not-found until an endpoint is set.

Any field left blank on the account page falls back to `config/config.php`, so a server-wide
default can be configured once and individual users may override just their endpoint or key.

Smoke test over stdio (uses `config/config.php`):

```sh
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"convert_markdown_to_pdf","arguments":{"path":"data/example.md"}}}' \
  | php index.php
```

By hand against Stirling-PDF the equivalent request is:

```sh
# Only include the X-API-KEY header if your Stirling-PDF instance requires it.
curl -X POST "$ENDPOINT/api/v1/convert/markdown/pdf" \
  -H "Accept: application/octet-stream" \
  -H "X-API-KEY: $API_KEY" \
  -F "fileInput=@document.md"
```

Notes:

- The tool requires the `user` or `admin` role, so only logged-in accounts can call it;
  anonymous HTTP callers never see it. In stdio mode the trusted `local` user passes.
- With no endpoint configured for the caller the tools are disabled (see above) — they are
  hidden from `tools/list` and a direct call returns tool-not-found, even for `admin`.
- The endpoint is used verbatim apart from trimming a trailing `/` — include any path prefix your
  Stirling-PDF deployment is served under. It must be reachable from the machine running SimpleMCP.
- The API key is stored in plaintext in `data/app.sqlite` (never in git — `data/` is gitignored).
  Treat it like other server credentials: it is the key your own Stirling-PDF deployment accepts.
- No extra PHP extensions are required: the request is made with PHP streams
  (`file_get_contents` + a hand-built `multipart/form-data` body), so `allow_url_fopen`
  (on by default) is the only prerequisite — the cURL extension is not needed.

## PDF → Markdown (Stirling-PDF)

The inverse direction, `convert_pdf_to_markdown`, POSTs a PDF — again as a multipart upload
(field `fileInput`) — to `{endpoint}/api/v1/convert/pdf/markdown`, writes the extracted Markdown
to the **same directory with the same base name** (`<dir>/<name>.md`), and returns the Markdown
**directly in the tool result**.

### Supplying the PDF

| Argument | Notes |
|----------|-------|
| `path` | Path of a PDF file to convert. The tool reads the file, exports the converted Markdown to `<dir>/<name>.md`, and returns the extracted text. |
| `input_file_path` | Alias for `path`. |

The input is rejected before any network call if the file does not exist, cannot be read, exceeds 100 MB, or has no `%PDF` header in its first 1 KiB.

Smoke test over stdio (uses `config/config.php`):

```sh
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"convert_pdf_to_markdown","arguments":{"path":"/tmp/report.pdf"}}}' \
  | php index.php
```

By hand against Stirling-PDF the equivalent request is:

```sh
# Only include the X-API-KEY header if your Stirling-PDF instance requires it.
curl -X POST "$ENDPOINT/api/v1/convert/pdf/markdown" \
  -H "Accept: application/octet-stream" \
  -H "X-API-KEY: $API_KEY" \
  -F "fileInput=@document.pdf"
```

## Image → PDF (Stirling-PDF)

`convert_image_to_pdf` posts an image (PNG, JPG, JPEG, WEBP, GIF, BMP, TIFF, SVG) to
`{endpoint}/api/v1/convert/img/pdf` and writes the resulting PDF to `<dir>/<name>.pdf`.

| Argument | Type | Default | Notes |
|----------|------|---------|-------|
| `path` | string | *required* | Path of the image file to convert. Writes `<dir>/<name>.pdf`. |
| `input_file_path` | string | optional | Alias for `path`. |
| `fit_option` | string | `'fillPage'` | Fit mode: `fillPage`, `fitToPage`, or `maintainAspectRatio`. |
| `color_type` | string | `'color'` | Output color mode: `color`, `greyscale`, or `black-and-white`. |
| `auto_rotate` | boolean | `false` | Whether to automatically rotate images to better fit the page. |

Smoke test over stdio:

```sh
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"convert_image_to_pdf","arguments":{"path":"data/photo.png"}}}' \
  | php index.php
```

## PDF → Image (Stirling-PDF)

`convert_pdf_to_image` posts a PDF to `{endpoint}/api/v1/convert/pdf/img` and exports the converted
image(s) directly into the source directory (`<dir>/<name>.<format>` for single images, or unzipped
individual page images `<dir>/<name>_<n>.<format>` for multi-page extractions). When a multi-page ZIP
archive is returned, it is automatically unzipped directly into the source directory (`<dir>`), and the
temporary ZIP file is removed (unless `keep_zip: true` is passed). Extraction supports PHP's `ZipArchive`
with fallback to system tools (`tar`, `powershell`, or `unzip`).

| Argument | Type | Default | Notes |
|----------|------|---------|-------|
| `path` | string | *required* | Path of the PDF file to convert. |
| `input_file_path` | string | optional | Alias for `path`. |
| `image_format` | string | `'png'` | Output format: `png`, `jpeg`, `jpg`, `gif`, or `webp`. |
| `single_or_multiple` | string | `'single'` | `'single'` merges all pages into one continuous image; `'multiple'` exports separate images per page (unzipped into source directory). |
| `page_numbers` | string | `'all'` | Pages to convert: `'all'` or ranges/subsets like `'1'`, `'1,3,5-9'`. |
| `color_type` | string | `'color'` | `'color'`, `'greyscale'`, or `'blackandwhite'`. |
| `dpi` | integer | `300` | Resolution in dots per inch. |
| `keep_zip` | boolean | `false` | Whether to retain the ZIP archive alongside unzipped images for multi-page conversions. |

Smoke test over stdio:

```sh
printf '%s\n%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"convert_pdf_to_image","arguments":{"path":"data/report.pdf","image_format":"png"}}}' \
  | php index.php
```

## Adding a tool

Tools live in [src/Tools/](src/Tools/). Create a class whose methods are decorated with
`#[McpFunction(name, description, schema)]`. Each method receives the raw `arguments` array
and returns an array of MCP content blocks.

```php
<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;

readonly class GreetingTool {
    #[McpFunction(
        name: 'greet',
        description: 'Greets a person by name.',
        schema: [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The name to greet'],
            ],
            'required' => ['name'],
        ],
    )]
    public function greet(array $arguments): array {
        $name = $arguments['name'] ?? 'world';
        return [['type' => 'text', 'text' => "Hello, $name!"]];
    }
}
```

[src/McpServer.php](src/McpServer.php#L59) calls `registerToolsFromDirectory()`, which
auto-discovers every non-abstract class in `src/Tools/` containing at least one
`#[McpFunction]` method — no manual registration step.

### Restricting a tool

Pass `roles` and/or `permissions` to the attribute (see [AdminTool.php](src/Tools/AdminTool.php)):

```php
#[McpFunction(
    name: 'server_status',
    description: 'Returns server runtime information. Admin-only.',
    schema: ['type' => 'object', 'properties' => new \stdClass()],
    roles: ['admin'],
)]
```

**Access rule:** a tool that declares a category requires the caller to match within *every*
declared category (any match within a category suffices). A tool with no `roles` /
`permissions` is **public** — anonymous HTTP callers can list and call it.

### Reading the current user

Declare an optional `?UserContext $user = null` parameter (explicit `?` — implicit-nullable
is deprecated in PHP 8.4). The server detects it via reflection at registration and injects
the context at call time; the `arguments` array stays at parameter index 0
([UserInfoTool.php](src/Tools/UserInfoTool.php)):

```php
public function getCurrentUser(array $arguments, ?UserContext $user = null): array {
    $user ??= UserContext::anonymous();
    return [['type' => 'text', 'text' => json_encode($user->toArray())]];
}
```

## Users & access control

Users are seeded from [config/users.php](config/users.php) into SQLite only when the `users`
table is empty. On this branch the seed is:

| Username | Password | Roles | Status |
|----------|----------|-------|--------|
| `admin` | `admin123` | `admin` | active |
| `alice` | `secret` | `user` | active |
| `bob` | *(none yet)* | `user` | pending |

> These are dev-only seed credentials — change them before deploying anywhere real.

A user added with no password (status `pending`) must complete **onboarding**: the first time
they log in — via `/account` or the OAuth flow a tool call triggers — they are asked to set a
password. Their provisioned username is kept and is not editable. `/account/onboard` is the
public path for brand-new users, who choose their own username. New accounts are granted
no special roles (`[]`); grant `admin` (or other roles) by editing `config/users.php` or the
`users` table.

In **stdio mode** there is no HTTP layer: every request runs as the trusted `local` user with
the `*` wildcard role/permission, so all tools are visible and callable.

## HTTP authentication (OAuth 2.1)

When served over HTTP, requests are **optionally** authenticated:

- **No bearer token** → the caller is `UserContext::anonymous()`; only tools with no
  `roles`/`permissions` are listed and callable.
- **Present but invalid/expired token** → `401` with a `WWW-Authenticate` challenge pointing
  at the protected-resource metadata, so MCP clients re-authenticate.
- **Valid token** → resolved to the matching account.

The flow is **Authorization Code + PKCE**, hosted by the server itself:

| Endpoint | Purpose |
|----------|---------|
| `GET/POST /oauth/authorize` | Validates the client/redirect (exact match, https except loopback), renders the two-step login (username, then password or onboarding), redirects with a one-time `code`. Posting `anonymous=1` skips login and issues a code for the anonymous identity. |
| `POST /oauth/token` | Exchanges `code` + `code_verifier` for `{access_token, refresh_token}`; rotates refresh tokens. Confidential clients authenticate via `client_secret_post` (form body) or `client_secret_basic` (HTTP Basic) and may skip PKCE; public clients must use PKCE (S256). |
| `POST /oauth/register` | RFC 7591 **dynamic client registration** — post `redirect_uris`, `token_endpoint_auth_method`, etc. and get back a `client_id` (+ `client_secret` when confidential). Optionally protected by `registration_access_token` in config. |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 discovery metadata. |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 protected-resource metadata. |

Only `none` and `client_secret_post` are advertised in discovery — Cherry Studio fails the
OAuth handshake when `client_secret_basic` is offered, so DCR-registered clients default to
`client_secret_post`.

### Connecting MCP clients

- **Claude Desktop / Cherry Studio / generic** — point them at `php index.php` (stdio) or the
  HTTP endpoint, and let them complete the interactive browser login at `/oauth/authorize`.
- **Open WebUI** — use the pre-registered **confidential** client `openwebui` from
  [config/oauth.php](config/oauth.php) and pick **OAuth 2.1 (Static)** when adding the MCP
  server, pasting the client id + secret. The interactive login is the same `/oauth/authorize`
  page; its redirect URI must be in `openwebui`'s `redirect_uris`.
- **Anonymous** — POST `anonymous=1` to `/oauth/authorize` (with a valid
  client/redirect/PKCE) and exchange the resulting code normally; the client connects but
  only sees/calls public tools.

## Configuration

- **[config/users.php](config/users.php)** — seed users. `password` is a bcrypt hash (generate
  with `php -r 'echo password_hash("pw", PASSWORD_BCRYPT);'`); a plaintext value is accepted
  as a dev fallback. `status: 'pending'` (or a missing password) marks a user for onboarding.
- **[config/oauth.php](config/oauth.php)** — OAuth clients, token/code TTLs, whether plain
  PKCE is allowed, and an optional `registration_access_token` protecting `/oauth/register`.
- **[config/config.php](config/config.php)** — global defaults for the PDF conversion tools
  (`stirling_pdf.endpoint` / `stirling_pdf.api_key`), used in stdio mode and as the fallback
  for HTTP accounts that left a field blank on the `/account` page.

## Project structure

```
index.php                     entry point — stdio loop, or HTTP router + auth bootstrap
config/
  users.php                   seed users
  oauth.php                   OAuth clients, TTLs, registration token
  config.php                  global defaults for the PDF tools (endpoint + API key)
src/
  McpServer.php               MCP core: tool registry, routing, access control, UserContext injection
  UserContext.php             immutable user value object (local() / anonymous() factories, * wildcard)
  StirlingPdfClient.php       Stirling-PDF client (both directions) + transport-aware config resolution
  PdfStore.php                saves generated PDFs under data/output + capability download URLs
  Attributes/McpFunction.php  the #[McpFunction(name, description, schema, roles, permissions)] attribute
  Tools/                      auto-discovered tool classes (CalculatorTool, AdminTool, PdfTool, ...)
  Auth/
    Database.php              SQLite bootstrap + idempotent schema + user seeding
    UserStore.php             DB-backed accounts: auth, onboarding, change password
    SettingsStore.php         per-account settings editable on /account (Stirling-PDF endpoint + key)
    TokenStore.php            OAuth codes/access/refresh tokens (sha256-hashed, single-use, rotating)
    ClientStore.php           OAuth client registry: static config + RFC 7591 dynamic clients
    OAuthServer.php           authorize/token/register/discovery + resolveUser()
    AccountController.php     /account user-management pages (native sessions + CSRF)
    DebugLog.php              append-only diagnostics log to data/requests.log
data/                         runtime-only, gitignored (app.sqlite, requests.log,
                              sessions/, output/ for generated PDFs)
```

## Notes & gotchas

- `data/` is runtime-only and gitignored (`/data/*`): `app.sqlite` (users + tokens),
  `requests.log`, `sessions/`. Deleting it re-seeds users from `config/users.php` on the next
  HTTP start.
- Run the dev server with `php -S localhost:8000 index.php` so `data/` is never served
  statically.
- The `try/catch` in `processMethod()` catches `Throwable`, so PHP `Error`s escaping a tool
  become a `-32603` response instead of killing the process.
- A `tools/call` response always sets `isError: false`; tools that return error text in a
  content block are still reported as successful. Access denials are a JSON-RPC `-32001`
  error, not a tool result.
- Tool methods receive the **unvalidated** `arguments` array — defaults and validation are
  the tool's responsibility (see `CalculatorTool::calculateLoanInstallment`).
- HTTP requests are optionally authenticated: no token → anonymous; present-but-invalid token
  → 401. To test the full authenticated flow, complete the OAuth login in a browser (or with
  curl), or log in at `/account`.
- The HTTP server is stateless about MCP sessions: it mints an `Mcp-Session-Id` on
  `initialize` (needed by some clients, e.g. Cherry Studio) and echoes it back, but stores
  nothing.
- `vendor/` contains unused leftovers (phpdotenv, extendorm) not declared in `composer.json`.

## License

Not specified.
