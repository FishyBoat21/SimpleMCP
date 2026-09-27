<?php

declare(strict_types=1);

/**
 * Change Password Action (PRG Pattern).
 *
 * POST: Verifies old password, updates new password, and redirects back to dashboard.
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

    $oldPassword = (string) ($_POST['old_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');

    if (strlen($newPassword) < 8) {
        flash_set('error', 'New password must be at least 8 characters long.');
        redirect('/account/index.php');
    }

    $ok = App::userStore()->changePassword($username, $oldPassword, $newPassword);
    if ($ok) {
        flash_set('msg', 'Password changed successfully.');
    } else {
        flash_set('error', 'Current password is incorrect or new password was rejected.');
    }
}

redirect('/account/index.php');
