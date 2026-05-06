<?php

declare(strict_types=1);

namespace McpServer\Web;

use McpServer\Auth\OAuthServer;

abstract class BaseController
{
    protected OAuthServer $oauth;

    public function __construct()
    {
        $this->oauth = new OAuthServer();
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    // ── Auth guards ───────────────────────────────────────────────────────────

    protected function requireLogin(): void
    {
        if (empty($_SESSION['sso_user_id'])) {
            $this->redirect('/login');
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireLogin();
        if (($_SESSION['sso_user_role'] ?? 'user') !== 'admin') {
            http_response_code(403);
            echo "403 Forbidden - Admin access required.";
            exit;
        }
    }

    protected function redirect(string $path): never
    {
        // If $path is already an absolute URL (e.g. the OAuth redirect_uri),
        // use it directly — do NOT prepend baseUrl() again.
        $url = (str_starts_with($path, 'http://') || str_starts_with($path, 'https://'))
            ? $path
            : View::baseUrl() . $path;
        header('Location: ' . $url);
        exit;
    }
}
