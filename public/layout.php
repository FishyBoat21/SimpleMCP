<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Render a complete HTML page with rich modern aesthetics, responsive layout,
 * flash message display, and strict security headers.
 *
 * @param string $title Page title
 * @param string $content HTML body content inside the container
 * @param array{
 *     subtitle?: string,
 *     maxWidth?: string,
 *     showNav?: bool,
 *     user?: ?\McpServer\UserContext
 * } $options
 */
function render_html(string $title, string $content, array $options = []): void {
    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $maxWidth = $options['maxWidth'] ?? '34rem';
    $subtitle = $options['subtitle'] ?? '';

    $flashError = flash_get('error');
    $flashMsg = flash_get('msg') ?? ($_GET['msg'] ?? null);

    $errorHtml = $flashError !== null && $flashError !== ''
        ? '<div class="alert alert-error" role="alert"><svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg><span>' . htmlspecialchars((string) $flashError) . '</span></div>'
        : '';

    $msgHtml = $flashMsg !== null && $flashMsg !== ''
        ? '<div class="alert alert-success" role="status"><svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><span>' . htmlspecialchars((string) $flashMsg) . '</span></div>'
        : '';

    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> — SimpleMCP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border-card: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --color-primary: #2563eb;
            --color-primary-hover: #1d4ed8;
            --color-teal: #0f766e;
            --color-teal-hover: #115e59;
            --color-danger: #dc2626;
            --color-danger-hover: #b91c1c;
            --shadow-card: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.05);
            --radius-md: 8px;
            --radius-lg: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            padding: 2.5rem 1rem 4rem;
        }
        .container {
            max-width: <?= htmlspecialchars($maxWidth) ?>;
            margin: 0 auto;
        }
        .header {
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .logo-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ede9fe;
            color: #6d28d9;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            margin-bottom: 0.6rem;
        }
        h1 {
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }
        .subtitle {
            color: var(--text-muted);
            font-size: 0.92rem;
            margin-top: 0.25rem;
        }
        .main-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: var(--radius-lg);
            padding: 1.75rem 2rem;
            box-shadow: var(--shadow-card);
        }
        h2 {
            font-size: 1.15rem;
            font-weight: 600;
            margin: 1.5rem 0 0.75rem;
            color: var(--text-main);
            border-bottom: 1px solid var(--border-card);
            padding-bottom: 0.4rem;
        }
        h2:first-of-type { margin-top: 0; }
        h3 { font-size: 0.98rem; font-weight: 600; margin-bottom: 0.5rem; }
        p { margin-bottom: 0.9rem; }
        .hint { color: var(--text-muted); font-size: 0.88rem; margin-top: 0.25rem; }
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 0.8rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }
        .alert-icon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: 1px; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        label {
            display: block;
            font-size: 0.88rem;
            font-weight: 500;
            margin-bottom: 0.85rem;
            color: #334155;
        }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 0.65rem 0.85rem;
            margin-top: 0.35rem;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            font-family: inherit;
            color: var(--text-main);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        button, .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            width: 100%;
            padding: 0.68rem 1rem;
            margin-top: 0.4rem;
            background: var(--color-primary);
            color: #ffffff;
            border: 0;
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            font-weight: 500;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, filter 0.15s, transform 0.05s;
        }
        button:hover, .btn:hover { background: var(--color-primary-hover); }
        button:active, .btn:active { transform: scale(0.99); }
        button.teal, .btn.teal { background: var(--color-teal); }
        button.teal:hover, .btn.teal:hover { background: var(--color-teal-hover); }
        button.secondary, .btn.secondary { background: #475569; }
        button.secondary:hover, .btn.secondary:hover { background: #334155; }
        button.danger, .btn.danger {
            background: var(--color-danger);
            color: #ffffff;
            width: auto;
            padding: 0.32rem 0.65rem;
            font-size: 0.8rem;
            margin: 0;
        }
        button.danger:hover, .btn.danger:hover { background: var(--color-danger-hover); }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        dl.user-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.4rem 1rem;
            font-size: 0.9rem;
            background: #f8fafc;
            border: 1px solid var(--border-card);
            border-radius: var(--radius-md);
            padding: 0.85rem 1rem;
            margin-bottom: 1.25rem;
        }
        dl.user-grid dt { font-weight: 600; color: #475569; }
        dl.user-grid dd { color: #0f172a; word-break: break-word; }
        .badge {
            display: inline-block;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #e2e8f0;
            color: #334155;
            margin-right: 0.3rem;
        }
        .badge.admin { background: #ede9fe; color: #6d28d9; }
        .badge.user { background: #e0f2fe; color: #0369a1; }
        table.data-table {
            width: 100%;
            font-size: 0.86rem;
            border-collapse: collapse;
            margin: 0.5rem 0 1rem;
        }
        table.data-table th, table.data-table td {
            padding: 0.55rem 0.5rem;
            border-bottom: 1px solid var(--border-card);
            text-align: left;
        }
        table.data-table th { font-weight: 600; color: #475569; }
        .card-inner {
            background: #f8fafc;
            border: 1px solid var(--border-card);
            border-radius: var(--radius-md);
            padding: 1rem 1.15rem;
            margin: 1rem 0;
        }
        a { color: var(--color-primary); text-decoration: none; font-weight: 500; }
        a:hover { text-decoration: underline; }
        .footer-links {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.88rem;
            color: var(--text-muted);
        }
        .footer-links a { color: var(--text-muted); }
        .footer-links a:hover { color: var(--color-primary); }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-badge">⚡ SimpleMCP</div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
                <div class="subtitle"><?= htmlspecialchars($subtitle) ?></div>
            <?php endif; ?>
        </div>

        <?= $errorHtml ?>
        <?= $msgHtml ?>

        <div class="main-card">
            <?= $content ?>
        </div>

        <div class="footer-links">
            SimpleMCP &middot; Model Context Protocol Server &middot; <a href="/account">Dashboard</a>
        </div>
    </div>
</body>
</html>
<?php
}
