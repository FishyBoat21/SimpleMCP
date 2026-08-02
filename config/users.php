<?php

declare(strict_types=1);

/**
 * Seed users, imported into the SQLite database on first run (only when the
 * users table is empty — see src/Auth/Database.php).
 *
 * - `password`: bcrypt hash. Generate one with:
 *       php -r 'echo password_hash("your-password", PASSWORD_BCRYPT);'
 *   A plaintext value is also accepted as a dev fallback.
 * - `status: 'pending'` (or no `password`) marks a user as needing onboarding:
 *   the first time they log in — via the user-management page or via the OAuth
 *   login a tool call triggers — they are asked to set a password. Their
 *   provisioned username is kept (it is not editable during onboarding).
 */
return [
    [
        'username' => 'admin',
        'name' => 'Administrator',
        'password' => '$2y$12$SykCZauQsdZmUCNyuXFzleOa5fsmOKW.94nFh40lnHnCiWzeaNw02', // admin123
        'roles' => ['admin', 'user'],
        'permissions' => ['*'],
        'status' => 'active',
    ],
    [
        'username' => 'alice',
        'name' => 'Alice',
        'password' => '$2y$12$Ct7lHRUlKhh5aWnVWcdqnO6VoVHYMEBplHwrX3VKODnXvjkDZe35O', // secret
        'roles' => ['user'],
        'permissions' => [],
        'status' => 'active',
    ],
    [
        'username' => 'bob',
        'name' => 'Bob',
        'password' => null, // not set -> pending onboarding
        'roles' => ['user'],
        'permissions' => [],
        'status' => 'pending',
    ],
];
