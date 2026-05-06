<?php

declare(strict_types=1);

namespace McpServer\Web;

/**
 * Renders reusable HTML layouts and page partials for the SSO UI.
 */
class View {
    /**
     * Render a full HTML page wrapped in the base layout.
     *
     * @param string $title    Page <title>
     * @param string $content  Inner HTML body
     * @param string $bodyClass Extra CSS classes for <body>
     */
    public static function render(string $title, string $content, string $bodyClass = ''): void {
        echo self::layout($title, $content, $bodyClass);
    }

    public static function layout(string $title, string $content, string $bodyClass = ''): string {
        $base = self::baseUrl();
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SimpleMCP SSO — Secure OAuth2 Authorization Server">
    <title>{$title} — SimpleMCP SSO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:         #0a0e1a;
            --bg2:        #111827;
            --bg3:        #1a2235;
            --surface:    rgba(255,255,255,0.04);
            --border:     rgba(255,255,255,0.08);
            --accent:     #6366f1;
            --accent2:    #8b5cf6;
            --accent-glow:rgba(99,102,241,0.35);
            --text:       #f1f5f9;
            --text-muted: #94a3b8;
            --success:    #10b981;
            --error:      #ef4444;
            --warning:    #f59e0b;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{
            font-family:'Inter',sans-serif;
            background:var(--bg);
            color:var(--text);
            min-height:100vh;
            line-height:1.6;
        }
        a{color:var(--accent);text-decoration:none}
        a:hover{text-decoration:underline}

        /* ── Noise Overlay ── */
        body::before{
            content:'';
            position:fixed;inset:0;
            background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events:none;z-index:0;
        }

        /* ── Gradient Orbs ── */
        .orb{
            position:fixed;border-radius:50%;filter:blur(80px);
            pointer-events:none;z-index:0;
        }
        .orb-1{width:600px;height:600px;background:radial-gradient(circle,rgba(99,102,241,.18),transparent 70%);top:-200px;left:-150px}
        .orb-2{width:500px;height:500px;background:radial-gradient(circle,rgba(139,92,246,.14),transparent 70%);bottom:-150px;right:-100px}

        /* ── Navbar ── */
        .navbar{
            position:sticky;top:0;z-index:100;
            display:flex;align-items:center;justify-content:space-between;
            padding:0 2rem;height:60px;
            background:rgba(10,14,26,.85);
            backdrop-filter:blur(20px);
            border-bottom:1px solid var(--border);
        }
        .navbar-brand{
            display:flex;align-items:center;gap:.6rem;
            font-weight:700;font-size:1.05rem;letter-spacing:-.01em;
        }
        .navbar-brand .logo{
            width:28px;height:28px;
            background:linear-gradient(135deg,var(--accent),var(--accent2));
            border-radius:7px;
            display:flex;align-items:center;justify-content:center;
            font-size:.7rem;font-weight:800;color:#fff;
        }
        .navbar-nav{display:flex;align-items:center;gap:1.5rem;font-size:.875rem}
        .navbar-nav a{color:var(--text-muted);transition:color .2s}
        .navbar-nav a:hover,.navbar-nav a.active{color:var(--text);text-decoration:none}
        .btn-sm{
            padding:.35rem .9rem;border-radius:6px;font-size:.8rem;
            background:var(--accent);color:#fff;border:none;cursor:pointer;
            transition:opacity .2s;text-decoration:none!important;
        }
        .btn-sm:hover{opacity:.85}

        /* ── Cards ── */
        .card{
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:16px;
            backdrop-filter:blur(24px);
            position:relative;z-index:1;
        }
        .card-header{
            padding:1.5rem 1.75rem 1rem;
            border-bottom:1px solid var(--border);
        }
        .card-body{padding:1.75rem}

        /* ── Forms ── */
        .form-group{margin-bottom:1.25rem}
        .form-label{display:block;font-size:.8rem;font-weight:500;color:var(--text-muted);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em}
        .form-control{
            width:100%;padding:.65rem 1rem;
            background:rgba(255,255,255,.05);
            border:1px solid var(--border);
            border-radius:8px;
            color:var(--text);
            font-size:.9rem;font-family:inherit;
            transition:border-color .2s,box-shadow .2s;
            outline:none;
        }
        .form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-glow)}
        .form-control::placeholder{color:var(--text-muted)}

        /* ── Buttons ── */
        .btn{
            display:inline-flex;align-items:center;justify-content:center;gap:.5rem;
            padding:.65rem 1.4rem;border-radius:10px;font-size:.9rem;font-weight:600;
            cursor:pointer;border:none;transition:all .2s;text-decoration:none!important;
        }
        .btn-primary{
            background:linear-gradient(135deg,var(--accent),var(--accent2));
            color:#fff;
            box-shadow:0 4px 20px var(--accent-glow);
        }
        .btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 28px var(--accent-glow)}
        .btn-secondary{background:rgba(255,255,255,.07);color:var(--text);border:1px solid var(--border)}
        .btn-secondary:hover{background:rgba(255,255,255,.12)}
        .btn-danger{background:rgba(239,68,68,.15);color:var(--error);border:1px solid rgba(239,68,68,.3)}
        .btn-danger:hover{background:rgba(239,68,68,.25)}
        .btn-full{width:100%}

        /* ── Alerts ── */
        .alert{
            padding:.85rem 1.1rem;border-radius:10px;
            font-size:.875rem;margin-bottom:1.25rem;
            display:flex;align-items:flex-start;gap:.6rem;
        }
        .alert-error  {background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#fca5a5}
        .alert-success{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#6ee7b7}
        .alert-info   {background:rgba(99,102,241,.1);border:1px solid rgba(99,102,241,.25);color:#a5b4fc}
        .alert-warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:#fcd34d}

        /* ── Tables ── */
        .table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border)}
        table{width:100%;border-collapse:collapse}
        th{
            padding:.75rem 1rem;font-size:.75rem;font-weight:600;
            text-transform:uppercase;letter-spacing:.05em;
            color:var(--text-muted);background:rgba(255,255,255,.02);
            text-align:left;border-bottom:1px solid var(--border);
        }
        td{
            padding:.85rem 1rem;font-size:.875rem;
            border-bottom:1px solid var(--border);
            color:var(--text);
        }
        tr:last-child td{border-bottom:none}
        tr:hover td{background:rgba(255,255,255,.02)}

        /* ── Badges ── */
        .badge{
            display:inline-block;padding:.2rem .6rem;border-radius:999px;
            font-size:.7rem;font-weight:600;letter-spacing:.03em;
        }
        .badge-success{background:rgba(16,185,129,.15);color:var(--success)}
        .badge-muted  {background:rgba(255,255,255,.07);color:var(--text-muted)}
        .badge-accent {background:rgba(99,102,241,.2);color:#a5b4fc}

        /* ── Layout Helpers ── */
        .container{max-width:1100px;margin:0 auto;padding:2rem 1.5rem}
        .page-center{
            min-height:calc(100vh - 60px);
            display:flex;align-items:center;justify-content:center;
            padding:2rem;position:relative;z-index:1;
        }
        .grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:1.25rem}
        .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem}
        @media(max-width:640px){.grid-2,.grid-3{grid-template-columns:1fr}}
        .flex{display:flex}.flex-between{justify-content:space-between}.flex-center{align-items:center}
        .gap-1{gap:.5rem}.gap-2{gap:1rem}.gap-3{gap:1.5rem}
        .mt-1{margin-top:.5rem}.mt-2{margin-top:1rem}.mt-3{margin-top:1.5rem}.mt-4{margin-top:2rem}
        .mb-1{margin-bottom:.5rem}.mb-2{margin-bottom:1rem}.mb-3{margin-bottom:1.5rem}
        .text-muted{color:var(--text-muted)}.text-sm{font-size:.8rem}.text-center{text-align:center}
        .font-mono{font-family:monospace}
        .truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}
        .w-full{width:100%}

        /* ── Stat Cards ── */
        .stat-card{
            padding:1.5rem;
            background:var(--surface);
            border:1px solid var(--border);
            border-radius:14px;
        }
        .stat-value{font-size:2rem;font-weight:700;line-height:1}
        .stat-label{font-size:.8rem;color:var(--text-muted);margin-top:.4rem}

        /* ── Consent Scope List ── */
        .scope-list{list-style:none;margin:.5rem 0}
        .scope-list li{
            display:flex;align-items:center;gap:.6rem;
            padding:.5rem 0;font-size:.875rem;
            border-bottom:1px solid var(--border);
        }
        .scope-list li:last-child{border-bottom:none}
        .scope-icon{width:20px;height:20px;flex-shrink:0;color:var(--accent)}

        /* ── Animations ── */
        @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
        .fade-up{animation:fadeUp .4s ease both}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

        /* ── Code block ── */
        pre{
            background:rgba(0,0,0,.4);
            border:1px solid var(--border);
            border-radius:10px;
            padding:1rem;
            font-size:.78rem;
            overflow-x:auto;
            color:#e2e8f0;
            font-family:'JetBrains Mono',monospace;
        }
        code{font-size:.85em;color:#a5b4fc;font-family:inherit}
    </style>
</head>
<body class="{$bodyClass}">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <nav class="navbar">
        <a class="navbar-brand" href="{$base}/" style="text-decoration:none;color:inherit">
            <div class="logo">M</div>
            SimpleMCP SSO
        </a>
        <div class="navbar-nav">
            <a href="{$base}/">Dashboard</a>
            <a href="{$base}/clients">Clients</a>
            <a href="{$base}/users">Users</a>
            <a href="{$base}/docs">API Docs</a>
            <a href="{$base}/logout" class="btn-sm">Sign Out</a>
        </div>
    </nav>

    {$content}
</body>
</html>
HTML;
    }

    public static function baseUrl(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public static function e(string $str): string {
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function csrf(): string {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['csrf_token'];
        return "<input type='hidden' name='_csrf' value='{$token}'>";
    }

    public static function verifyCsrf(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $submitted = $_POST['_csrf'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
    }
}
