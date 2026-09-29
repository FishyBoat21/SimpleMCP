<?php

declare(strict_types=1);

/**
 * Logout Action (PRG Pattern).
 *
 * POST / GET: Destroys user session and redirects to login page.
 */

require_once __DIR__ . '/../bootstrap.php';

start_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    // If CSRF is passed, verify it; otherwise still allow logout for safety
    if (isset($_POST['csrf'])) {
        csrf_verify((string) $_POST['csrf']);
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

start_session();
flash_set('msg', 'You have been signed out.');
redirect('/account/login.php');
