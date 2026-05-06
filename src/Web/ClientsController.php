<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /clients, POST /clients
class ClientsController extends BaseController
{
    public function clients(): void
    {
        $this->requireAdmin();
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
        $this->requireAdmin();
        View::verifyCsrf();
        $name  = trim($_POST['name'] ?? '');
        $uris  = array_map('trim', explode(',', $_POST['redirect_uris'] ?? 'http://localhost/callback'));
        $scopes = trim($_POST['scopes'] ?? 'mcp');
        if ($name) $this->oauth->createClient($name, $uris, $scopes);
        $_SESSION['flash'] = "Client '{$name}' registered successfully.";
        $this->redirect('/clients');
    }
}
