<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Simple user-management web pages: login, public onboarding (pending users set
 * a password for their provisioned username; brand-new users pick one), change
 * password, logout.
 *
 * Uses PHP's native cookie sessions + a per-session CSRF token. Returns the same
 * `{status, headers, body}` shape as {@see OAuthServer::handle()}.
 */
final class AccountController {
    public function __construct(
        private readonly UserStore $users,
        /** App mount prefix (e.g. "/SimpleMCP") when hosted under a virtual directory; '' at the site root. */
        private readonly string $mountPath = '',
    ) {}

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $path, array $get, array $post): array {
        return match ($path) {
            '/account/login' => $method === 'POST' ? $this->login($post) : $this->redirect('/account'),
            '/account/onboard' => $method === 'GET' ? $this->onboardPage('', '', '') : $this->onboard($post),
            '/account/change-password' => $method === 'POST' ? $this->changePassword($post) : $this->redirect('/account'),
            '/account/logout' => $method === 'POST' ? $this->logout($post) : $this->redirect('/account'),
            default => $method === 'GET' ? $this->accountPage($get) : $this->redirect('/account'),
        };
    }

    private function login(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $identifier = trim((string) ($post['username'] ?? ''));
        $password = (string) ($post['password'] ?? '');

        // Step 1 of the two-step login: only a username was submitted. Route to
        // the password step (active account) or to onboarding (pending account).
        if ($password === '') {
            if ($identifier === '') {
                return $this->usernamePage('Username is required.');
            }
            $row = $this->users->getByUsername($identifier);
            if ($row === null) {
                return $this->usernamePage('No account found for this username.');
            }
            if (($row['password_hash'] ?? null) === null) {
                // Newly added user with no password yet -> onboard before logging in.
                return $this->onboardPage('Set up your account to finish logging in.', (string) $row['username'], (string) $row['username']);
            }
            return $this->passwordPage(null, $identifier);
        }

        // Step 2: username + password.
        $user = $this->users->authenticate($identifier, $password);
        if ($user === null) {
            return $this->passwordPage('Incorrect password.', $identifier);
        }

        $_SESSION['username'] = (string) $user['username'];
        session_regenerate_id(true);
        return $this->redirect('/account');
    }

    private function onboard(array $post): array {
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $existingUsername = (string) ($post['existing_username'] ?? '');
        $username = trim((string) ($post['new_username'] ?? ''));
        $password = (string) ($post['new_password'] ?? '');
        $confirm = (string) ($post['confirm_password'] ?? '');
        $error = '';

        if ($password === '' || $confirm === '' || $password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($existingUsername === '' && $username === '') {
            // Only brand-new users pick a username; pending users keep theirs.
            $error = 'Username is required.';
        } else {
            $user = $this->users->onboardUser($existingUsername !== '' ? $existingUsername : null, $username, $password);
            if ($user === null) {
                $error = $existingUsername !== '' ? 'Account is not pending.' : 'Username is already taken.';
            } else {
                $_SESSION['username'] = $user->username;
                session_regenerate_id(true);
                return $this->redirect('/account');
            }
        }

        // Re-render. Pending users keep their provisioned username.
        $displayUsername = $username;
        if ($existingUsername !== '') {
            $row = $this->users->getByUsername($existingUsername);
            $displayUsername = $row !== null ? (string) $row['username'] : $username;
        }
        return $this->onboardPage($error, $existingUsername, $displayUsername);
    }

    private function changePassword(array $post): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null) {
            return $this->redirect('/account');
        }
        if (!$this->csrfOk($post)) {
            return $this->page(400, 'Bad request', '<p class="error">Invalid form submission.</p>');
        }

        $ok = $this->users->changePassword(
            (string) $username,
            (string) ($post['old_password'] ?? ''),
            (string) ($post['new_password'] ?? ''),
        );
        return $this->redirect($ok ? '/account?msg=Password+changed.' : '/account?msg=' . rawurlencode('Current password is incorrect or the new password is too short.'));
    }

    private function logout(array $post): array {
        if ($this->csrfOk($post)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
        }
        return $this->redirect('/account');
    }

    private function accountPage(array $get): array {
        $username = $_SESSION['username'] ?? null;
        if ($username === null) {
            return $this->usernamePage(null);
        }

        $row = $this->users->getByUsername((string) $username);
        if ($row === null) {
            $_SESSION = [];
            session_destroy();
            return $this->usernamePage('Your account no longer exists.');
        }

        $user = $this->users->toUserContext($row);
        $msg = (string) ($get['msg'] ?? '');
        $msgHtml = $msg === '' ? '' : '<div class="ok">' . htmlspecialchars($msg) . '</div>';

        $body = $msgHtml . '
        <h2>Your account</h2>
        <dl class="user">
            <dt>Username</dt><dd>' . htmlspecialchars($user->username) . '</dd>
            <dt>Name</dt><dd>' . htmlspecialchars($user->name) . '</dd>
            <dt>Roles</dt><dd>' . htmlspecialchars(implode(', ', $user->roles)) . '</dd>
            <dt>Permissions</dt><dd>' . htmlspecialchars(implode(', ', $user->permissions)) . '</dd>
        </dl>
        <h2>Change password</h2>
        <form method="post" action="' . $this->url('/account/change-password') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Current password <input type="password" name="old_password" required autocomplete="current-password"></label>
            <label>New password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
            <button type="submit">Change password</button>
        </form>
        <form method="post" action="' . $this->url('/account/logout') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <button type="submit" class="secondary">Log out</button>
        </form>';

        return $this->page(200, 'My account', $body);
    }

    private function usernamePage(?string $error): array {
        $body = $this->errorHtml($error) . '
        <form method="post" action="' . $this->url('/account/login') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <label>Username <input type="text" name="username" required autofocus autocomplete="username"></label>
            <button type="submit">Continue</button>
        </form>
        <p class="hint">New user? <a href="' . $this->url('/account/onboard') . '">Set up your account</a>.</p>';
        return $this->page(200, 'Log in', $body);
    }

    private function passwordPage(?string $error, string $identifier): array {
        $body = $this->errorHtml($error) . '
        <form method="post" action="' . $this->url('/account/login') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <input type="hidden" name="username" value="' . htmlspecialchars($identifier, ENT_QUOTES) . '">
            <p class="hint">Logging in as <strong>' . htmlspecialchars($identifier) . '</strong>.</p>
            <label>Password <input type="password" name="password" required autofocus autocomplete="current-password"></label>
            <button type="submit">Log in</button>
        </form>
        <p class="hint"><a href="' . $this->url('/account') . '">Use a different username</a>.</p>';
        return $this->page(200, 'Log in', $body);
    }

    private function onboardPage(string $error, string $existingUsername, string $username): array {
        // Pending users keep their provisioned username (read-only display);
        // brand-new users still choose their own username.
        $usernameField = $existingUsername !== ''
            ? '<p class="hint">Username <strong>' . htmlspecialchars($existingUsername, ENT_QUOTES) . '</strong> — cannot be changed.</p>'
            : '<label>Username <input type="text" name="new_username" value="' . htmlspecialchars($username, ENT_QUOTES) . '" required autofocus autocomplete="username"></label>';
        $body = $this->errorHtml($error) . '
        <form method="post" action="' . $this->url('/account/onboard') . '">
            <input type="hidden" name="csrf" value="' . $this->csrf() . '">
            <input type="hidden" name="existing_username" value="' . htmlspecialchars($existingUsername, ENT_QUOTES) . '">
            ' . $usernameField . '
            <label>Password <input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
            <label>Confirm password <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
            <button type="submit">Set up my account</button>
        </form>
        <p class="hint">Already have an account? <a href="' . $this->url('/account') . '">Log in</a>.</p>';
        return $this->page(200, 'Set up your account', $body);
    }

    // ---- helpers ------------------------------------------------------------

    private function csrf(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    private function csrfOk(array $post): bool {
        return isset($post['csrf'], $_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], (string) $post['csrf']);
    }

    /** Prefix an app-relative path with the mount prefix (e.g. "/account/login"). */
    private function url(string $path): string {
        return $this->mountPath . $path;
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function redirect(string $location): array {
        return ['status' => 303, 'headers' => ['Location' => $this->url($location)], 'body' => ''];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function page(int $status, string $title, string $body): array {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($title) . '</title><style>'
            . 'body{font-family:system-ui,sans-serif;max-width:28rem;margin:3rem auto;padding:0 1rem;color:#1a1a1a}'
            . 'h1,h2{font-size:1.3rem;margin:1.2rem 0 .6rem}.hint{color:#555;font-size:.9rem}'
            . '.error{color:#b00020;background:#fdecea;border:1px solid #f5c6c2;padding:.5rem .75rem;border-radius:6px;margin-bottom:1rem}'
            . '.ok{color:#0a6b2d;background:#e9f9ee;border:1px solid #b9e4c6;padding:.5rem .75rem;border-radius:6px;margin-bottom:1rem}'
            . 'label{display:block;margin-bottom:.9rem;font-size:.9rem}input{width:100%;box-sizing:border-box;padding:.55rem .6rem;margin-top:.25rem;border:1px solid #ccc;border-radius:6px}'
            . 'dl.user{font-size:.9rem}dl.user dt{font-weight:600;margin-top:.5rem}dl.user dd{margin:0}'
            . 'button{width:100%;padding:.6rem;margin-top:.4rem;background:#2563eb;color:#fff;border:0;border-radius:6px;font-size:1rem;cursor:pointer}'
            . 'button.secondary{background:#6b7280}a{color:#2563eb}'
            . '</style></head><body><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</body></html>';
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body' => $html,
        ];
    }

    private function errorHtml(?string $error): string {
        return $error === null || $error === '' ? '' : '<div class="error">' . htmlspecialchars($error) . '</div>';
    }
}
