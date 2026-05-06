<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /
class DashboardController extends BaseController
{
    public function dashboard(): void
    {
        $this->requireLogin();

        if (($_SESSION['sso_user_role'] ?? 'user') !== 'admin') {
            $e = View::e(...);
            $username = $e($_SESSION['sso_username'] ?? '');
            $content = <<<HTML
<div class="container" style="position:relative;z-index:1;text-align:center;padding-top:4rem">
    <div style="width:64px;height:64px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:#fff;margin:0 auto 1.5rem" class="fade-up">M</div>
    <h1 style="font-size:2rem;font-weight:700" class="fade-up" style="animation-delay:.1s">Welcome, {$username}</h1>
    <p class="text-muted mt-2 fade-up" style="animation-delay:.2s">You are logged in. You can now close this window or continue to authorize applications.</p>
</div>
HTML;
            View::render('Welcome', $content);
            return;
        }

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
}
