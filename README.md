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

All tools on this branch are examples that demonstrate the server's capabilities:

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

## Project structure

```
index.php                     entry point — stdio loop, or HTTP router + auth bootstrap
config/
  users.php                   seed users
  oauth.php                   OAuth clients, TTLs, registration token
src/
  McpServer.php               MCP core: tool registry, routing, access control, UserContext injection
  UserContext.php             immutable user value object (local() / anonymous() factories, * wildcard)
  Attributes/McpFunction.php  the #[McpFunction(name, description, schema, roles, permissions)] attribute
  Tools/                      auto-discovered tool classes (CalculatorTool, AdminTool, ...)
  Auth/
    Database.php              SQLite bootstrap + idempotent schema + user seeding
    UserStore.php             DB-backed accounts: auth, onboarding, change password
    TokenStore.php            OAuth codes/access/refresh tokens (sha256-hashed, single-use, rotating)
    ClientStore.php           OAuth client registry: static config + RFC 7591 dynamic clients
    OAuthServer.php           authorize/token/register/discovery + resolveUser()
    AccountController.php     /account user-management pages (native sessions + CSRF)
    DebugLog.php              append-only diagnostics log to data/requests.log
data/                         runtime-only, gitignored (app.sqlite, requests.log, sessions/)
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
