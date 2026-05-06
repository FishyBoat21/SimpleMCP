<?php

declare(strict_types=1);

namespace McpServer\Web;

use McpServer\Auth\OAuthServer;

class SsoController
{
    private OAuthServer $oauth;

    public function __construct()
    {
        $this->oauth = new OAuthServer();
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    // ── Auth guards ───────────────────────────────────────────────────────────

    private function requireLogin(): void
    {
        if (empty($_SESSION['sso_user_id'])) {
            $this->redirect('/login');
        }
    }

    private function redirect(string $path): never
    {
        // If $path is already an absolute URL (e.g. the OAuth redirect_uri),
        // use it directly — do NOT prepend baseUrl() again.
        $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
            ? $path
            : View::baseUrl() . $path;
        header('Location: ' . $url);
        exit;
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(): void
    {
        $this->requireLogin();
        $stats   = $this->oauth->getActiveTokenStats();
        $clients = $this->oauth->listClients();
        $users   = $this->oauth->listUsers();
        $e = View::e(...);

        $content = <<<HTML
<div class="container" style="position:relative;z-index:1">
    <div class="mt-4 mb-3">
        <h1 style="font-size:1.6rem;font-weight:700">Dashboard</h1>
        <p class="text-muted text-sm mt-1">OAuth2 Authorization Server Overview</p>
    </div>
    <div class="grid-3 mb-3">
        <div class="stat-card fade-up">
            <div class="stat-value" style="color:var(--accent)">{$stats['active']}</div>
            <div class="stat-label">Active Tokens</div>
        </div>
        <div class="stat-card fade-up" style="animation-delay:.07s">
            <div class="stat-value" style="color:var(--accent2)">{$stats['today']}</div>
            <div class="stat-label">Tokens Issued (24h)</div>
        </div>
        <div class="stat-card fade-up" style="animation-delay:.14s">
            <div class="stat-value">{$e((string)count($clients))}</div>
            <div class="stat-label">Registered Clients</div>
        </div>
    </div>

    <div class="grid-2" style="gap:1.5rem">
        <div class="card fade-up" style="animation-delay:.2s">
            <div class="card-header flex flex-between flex-center">
                <span style="font-weight:600">OAuth2 Clients</span>
                <a href="/clients" class="btn-sm">Manage</a>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-wrap" style="border:none;border-radius:0 0 16px 16px">
                    <table>
                        <thead><tr><th>Name</th><th>Client ID</th><th>Scopes</th></tr></thead>
                        <tbody>
HTML;
        foreach (array_slice($clients, 0, 5) as $c) {
            $n  = $e($c['name']);
            $id = $e($c['client_id']);
            $sc = $e($c['scopes']);
            $content .= "<tr><td>{$n}</td><td class='font-mono text-sm truncate'>{$id}</td><td><span class='badge badge-accent'>{$sc}</span></td></tr>";
        }
        $content .= <<<HTML
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card fade-up" style="animation-delay:.27s">
            <div class="card-header flex flex-between flex-center">
                <span style="font-weight:600">Users</span>
                <a href="/users" class="btn-sm">Manage</a>
            </div>
            <div class="card-body" style="padding:0">
                <div class="table-wrap" style="border:none;border-radius:0 0 16px 16px">
                    <table>
                        <thead><tr><th>Username</th><th>Email</th></tr></thead>
                        <tbody>
HTML;
        foreach (array_slice($users, 0, 5) as $u) {
            $un = $e($u['username']);
            $em = $e($u['email'] ?? '—');
            $content .= "<tr><td>{$un}</td><td class='text-muted'>{$em}</td></tr>";
        }
        $content .= '</tbody></table></div></div></div></div></div>';
        View::render('Dashboard', $content);
    }

    // ── Login ─────────────────────────────────────────────────────────────────

    public function loginForm(): void
    {
        if (!empty($_SESSION['sso_user_id'])) $this->redirect('/');
        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);
        $e = View::e(...);
        $csrf = View::csrf();

        $errorHtml = $error
            ? "<div class='alert alert-error'>⚠ {$e($error)}</div>"
            : '';

        $redirect = $e($_GET['redirect'] ?? '');
        $redirectInput = $redirect ? "<input type='hidden' name='redirect' value='{$redirect}'>" : '';

        $content = <<<HTML
<div class="page-center">
<div style="width:100%;max-width:420px" class="fade-up">
    <div class="text-center mb-3">
        <div style="width:52px;height:52px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;margin:0 auto .75rem">M</div>
        <h1 style="font-size:1.5rem;font-weight:700">Sign in to SimpleMCP</h1>
        <p class="text-muted text-sm mt-1">OAuth2 Authorization Server</p>
    </div>
    <div class="card">
        <div class="card-body">
            {$errorHtml}
            <form method="POST" action="/login">
                {$csrf}
                {$redirectInput}
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input id="username" class="form-control" type="text" name="username" placeholder="Enter username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input id="password" class="form-control" type="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                </div>
                <button id="btn-login" class="btn btn-primary btn-full mt-2" type="submit">Sign In</button>
            </form>
        </div>
    </div>
    <p class="text-center text-sm text-muted mt-2">Default: <code>admin / admin</code></p>
</div>
</div>
HTML;
        View::render('Sign In', $content);
    }

    public function loginPost(): void
    {
        View::verifyCsrf();
        $user = $this->oauth->authenticateUser(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? ''
        );
        if ($user) {
            $_SESSION['sso_user_id']   = $user['id'];
            $_SESSION['sso_username']  = $user['username'];
            $redirect = $_POST['redirect'] ?? '/';
            $this->redirect($redirect);
        }
        $_SESSION['login_error'] = 'Invalid username or password.';
        $this->redirect('/login');
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('/login');
    }

    // ── OAuth2 Authorize Endpoint ─────────────────────────────────────────────

    public function authorizeGet(): void
    {
        if (empty($_SESSION['sso_user_id'])) {
            $q = http_build_query($_GET);
            $this->redirect('/login?redirect=' . urlencode('/authorize?' . $q));
        }

        $result = $this->oauth->validateAuthorizationRequest($_GET);
        if (isset($result['error'])) {
            http_response_code(400);
            View::render('Authorization Error', "<div class='page-center'><div class='alert alert-error'>" . View::e($result['error']) . "</div></div>");
            return;
        }

        $_SESSION['oauth_request'] = $result;
        $e = View::e(...);
        $csrf = View::csrf();
        $clientName = $e($result['client']['name']);
        $scopes = explode(' ', $result['scope']);

        $scopeItems = '';
        $scopeLabels = ['mcp' => 'Access MCP tools', 'profile' => 'Read profile info', 'offline_access' => 'Stay signed in (refresh token)'];
        foreach ($scopes as $s) {
            $label = $scopeLabels[$s] ?? $s;
            $scopeItems .= "<li><svg class='scope-icon' viewBox='0 0 20 20' fill='currentColor'><path fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z'/></svg>" . $e($label) . "</li>";
        }

        $content = <<<HTML
<div class="page-center">
<div style="width:100%;max-width:460px" class="fade-up">
    <div class="card">
        <div class="card-header text-center">
            <div style="font-size:1.3rem;font-weight:700">Authorization Request</div>
            <p class="text-muted text-sm mt-1"><strong style="color:var(--text)">{$clientName}</strong> is requesting access to your account</p>
        </div>
        <div class="card-body">
            <p class="text-sm text-muted mb-2">This application will be able to:</p>
            <ul class="scope-list">{$scopeItems}</ul>
            <div class="flex gap-2 mt-3" style="justify-content:stretch">
                <form method="POST" action="/authorize" style="flex:1">
                    {$csrf}
                    <input type="hidden" name="decision" value="deny">
                    <button id="btn-deny" class="btn btn-secondary btn-full" type="submit">Deny</button>
                </form>
                <form method="POST" action="/authorize" style="flex:1">
                    {$csrf}
                    <input type="hidden" name="decision" value="allow">
                    <button id="btn-allow" class="btn btn-primary btn-full" type="submit">Allow Access</button>
                </form>
            </div>
            <p class="text-center text-sm text-muted mt-2">Signed in as <strong style="color:var(--text)">{$e($_SESSION['sso_username'])}</strong></p>
        </div>
    </div>
</div>
</div>
HTML;
        View::render('Authorize', $content);
    }

    public function authorizePost(): void
    {
        View::verifyCsrf();
        if (empty($_SESSION['sso_user_id']) || empty($_SESSION['oauth_request'])) {
            $this->redirect('/login');
        }

        $req = $_SESSION['oauth_request'];
        unset($_SESSION['oauth_request']);

        if (($_POST['decision'] ?? '') !== 'allow') {
            $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';
            $this->redirect($req['redirect_uri'] . $sep . 'error=access_denied&state=' . urlencode($req['state']));
        }

        $code = $this->oauth->issueAuthorizationCode(
            $req['client']['client_id'],
            (int) $_SESSION['sso_user_id'],
            $req['redirect_uri'],
            $req['scope'],
            $req['code_challenge'],
            $req['code_challenge_method']
        );

        $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';
        $this->redirect($req['redirect_uri'] . $sep . http_build_query(['code' => $code, 'state' => $req['state']]));
    }

    // ── Token Endpoint ────────────────────────────────────────────────────────

    public function token(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $params = array_merge($_POST, (array) json_decode(file_get_contents('php://input') ?: '{}', true));
        $grant  = $params['grant_type'] ?? '';

        $result = match ($grant) {
            'authorization_code' => $this->oauth->exchangeCodeForToken($params),
            'refresh_token'      => $this->oauth->refreshToken($params),
            default              => ['error' => 'unsupported_grant_type'],
        };

        http_response_code(isset($result['error']) ? 400 : 200);
        echo json_encode($result);
    }

    // ── Revoke Endpoint ───────────────────────────────────────────────────────

    public function revoke(): void
    {
        header('Content-Type: application/json');
        $token = $_POST['token'] ?? '';
        if ($token) $this->oauth->revokeToken($token);
        echo json_encode(['revoked' => true]);
    }

    // ── Clients Management ────────────────────────────────────────────────────

    public function clients(): void
    {
        $this->requireLogin();
        $e = View::e(...);
        $csrf = View::csrf();
        $msg = '';
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            $msg = "<div class='alert alert-success'>✓ {$e($f)}</div>";
        }
        $clients = $this->oauth->listClients();

        $rows = '';
        foreach ($clients as $c) {
            $name    = $e($c['name']);
            $cid     = $e($c['client_id']);
            $secret  = $e($c['client_secret']);
            $uris    = $e(implode(', ', json_decode($c['redirect_uris'], true) ?? []));
            $scopes  = $e($c['scopes']);
            $rows   .= <<<HTML
<tr>
    <td><strong>{$name}</strong></td>
    <td class="font-mono text-sm">{$cid}</td>
    <td class="font-mono text-sm" style="color:var(--text-muted)">{$secret}</td>
    <td class="text-sm text-muted">{$uris}</td>
    <td><span class="badge badge-accent">{$scopes}</span></td>
</tr>
HTML;
        }

        $content = <<<HTML
<div class="container" style="position:relative;z-index:1">
    <div class="flex flex-between flex-center mt-4 mb-3">
        <div>
            <h1 style="font-size:1.4rem;font-weight:700">OAuth2 Clients</h1>
            <p class="text-muted text-sm">Registered applications that can request tokens</p>
        </div>
    </div>
    {$msg}
    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Register New Client</span></div>
        <div class="card-body">
            <form method="POST" action="/clients">
                {$csrf}
                <div class="grid-2 gap-2">
                    <div class="form-group">
                        <label class="form-label">App Name</label>
                        <input class="form-control" name="name" placeholder="My MCP Client" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Scopes</label>
                        <input class="form-control" name="scopes" value="mcp profile" placeholder="mcp profile">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label">Redirect URIs (comma-separated)</label>
                        <input class="form-control" name="redirect_uris" placeholder="http://localhost/callback, https://app.example.com/cb">
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Register Client</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Name</th><th>Client ID</th><th>Client Secret</th><th>Redirect URIs</th><th>Scopes</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</div>
HTML;
        View::render('Clients', $content);
    }

    public function clientsPost(): void
    {
        $this->requireLogin();
        View::verifyCsrf();
        $name  = trim($_POST['name'] ?? '');
        $uris  = array_map('trim', explode(',', $_POST['redirect_uris'] ?? 'http://localhost/callback'));
        $scopes = trim($_POST['scopes'] ?? 'mcp');
        if ($name) $this->oauth->createClient($name, $uris, $scopes);
        $_SESSION['flash'] = "Client '{$name}' registered successfully.";
        $this->redirect('/clients');
    }

    // ── Users Management ──────────────────────────────────────────────────────

    public function users(): void
    {
        $this->requireLogin();
        $e = View::e(...);
        $csrf = View::csrf();
        $msg = '';
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            $msg = "<div class='alert alert-success'>✓ {$e($f)}</div>";
        }
        $users = $this->oauth->listUsers();

        $rows = '';
        foreach ($users as $u) {
            $un = $e($u['username']);
            $em = $e($u['email'] ?? '');
            $dt = date('Y-m-d', (int) $u['created_at']);
            $rows .= "<tr><td><strong>{$un}</strong></td><td class='text-muted'>{$em}</td><td class='text-muted text-sm'>{$dt}</td></tr>";
        }

        $content = <<<HTML
<div class="container" style="position:relative;z-index:1">
    <div class="mt-4 mb-3">
        <h1 style="font-size:1.4rem;font-weight:700">Users</h1>
        <p class="text-muted text-sm">User accounts that can authenticate via SSO</p>
    </div>
    {$msg}
    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Create User</span></div>
        <div class="card-body">
            <form method="POST" action="/users">
                {$csrf}
                <div class="grid-2 gap-2">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" placeholder="johndoe" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" name="email" type="email" placeholder="john@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Create User</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Username</th><th>Email</th><th>Created</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</div>
HTML;
        View::render('Users', $content);
    }

    public function usersPost(): void
    {
        $this->requireLogin();
        View::verifyCsrf();
        $ok = $this->oauth->createUser(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? '',
            trim($_POST['email'] ?? '')
        );
        $_SESSION['flash'] = $ok ? "User created." : "Username already exists.";
        $this->redirect('/users');
    }

    // ── API Docs ──────────────────────────────────────────────────────────────

    public function docs(): void
    {
        $base = View::baseUrl();
        $content = <<<HTML
<div class="container" style="position:relative;z-index:1;max-width:820px">
    <div class="mt-4 mb-3">
        <h1 style="font-size:1.4rem;font-weight:700">API Documentation</h1>
        <p class="text-muted text-sm">OAuth2 endpoints &amp; MCP authorization guide</p>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Endpoints</span></div>
        <div class="card-body">
            <div class="table-wrap" style="border:none">
                <table>
                    <thead><tr><th>Method</th><th>Path</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><span class="badge badge-accent">GET</span></td><td class="font-mono">/authorize</td><td>Authorization endpoint (redirect user here)</td></tr>
                        <tr><td><span class="badge badge-success">POST</span></td><td class="font-mono">/token</td><td>Token endpoint — exchange code or refresh token</td></tr>
                        <tr><td><span class="badge badge-success">POST</span></td><td class="font-mono">/revoke</td><td>Revoke an access token</td></tr>
                        <tr><td><span class="badge badge-accent">GET/POST</span></td><td class="font-mono">/mcp</td><td>MCP server (requires Bearer token)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Authorization Code Flow</span></div>
        <div class="card-body">
            <p class="text-sm text-muted mb-2">1. Redirect user to the authorization endpoint:</p>
            <pre>GET {$base}/authorize
  ?response_type=code
  &amp;client_id=YOUR_CLIENT_ID
  &amp;redirect_uri=https://yourapp/callback
  &amp;scope=mcp profile
  &amp;state=RANDOM_STATE
  &amp;code_challenge=BASE64URL_SHA256_OF_VERIFIER   (PKCE)
  &amp;code_challenge_method=S256</pre>

            <p class="text-sm text-muted mt-2 mb-2">2. Exchange the code for tokens:</p>
            <pre>POST {$base}/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&amp;code=AUTH_CODE
&amp;redirect_uri=https://yourapp/callback
&amp;client_id=YOUR_CLIENT_ID
&amp;client_secret=YOUR_SECRET
&amp;code_verifier=YOUR_VERIFIER   (PKCE)</pre>

            <p class="text-sm text-muted mt-2 mb-2">3. Call the MCP server with your token:</p>
            <pre>POST {$base}/index.php
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json

{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}</pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Token Refresh</span></div>
        <div class="card-body">
            <pre>POST {$base}/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&amp;refresh_token=YOUR_REFRESH_TOKEN
&amp;client_id=YOUR_CLIENT_ID
&amp;client_secret=YOUR_SECRET</pre>
        </div>
    </div>
</div>
HTML;
        View::render('API Docs', $content);
    }
}
