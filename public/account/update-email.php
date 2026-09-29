<?php

declare(strict_types=1);

/**
 * Update Email Action (PRG Pattern).
 *
 * POST: Validates email, updates record, and redirects back to dashboard.
 */

require_once __DIR__ . '/../bootstrap.php';

start_session();

$username = $_SESSION['username'] ?? null;
if (!is_string($username) || $username === '') {
    redirect('/account/login.php');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid security token.');
        redirect('/account/index.php');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Please enter a valid email address.');
        redirect('/account/index.php');
    }

    $ok = App::userStore()->updateEmail($username, $email);
    if ($ok) {
        flash_set('msg', 'Email address updated successfully.');
    } else {
        flash_set('error', 'Failed to update email address.');
    }
}

redirect('/account/index.php');
