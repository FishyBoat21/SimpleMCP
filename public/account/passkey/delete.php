<?php

declare(strict_types=1);

/**
 * Delete Passkey Action (PRG Pattern).
 *
 * POST: Removes a registered passkey credential and redirects.
 */

require_once __DIR__ . '/../../bootstrap.php';

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

    $id = trim((string) ($_POST['id'] ?? ''));
    if ($id !== '') {
        App::passkeyStore()->delete($id, $username);
        flash_set('msg', 'Passkey deleted.');
    }
}

redirect('/account/index.php');
