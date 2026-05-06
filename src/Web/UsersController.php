<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /users, POST /users
class UsersController extends BaseController
{
    public function users(): void
    {
        $this->requireLogin();
        $e = View::e(...);
        $csrf = View::csrf();
        $msg = '';
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            $msg = "<div class='alert alert-success'>✓ {$e($f)}</div>";
        }
        $users = $this->oauth->listUsers();

        $rows = '';
        foreach ($users as $u) {
            $un = $e($u['username']);
            $em = $e($u['email'] ?? '');
            $dt = date('Y-m-d', (int) $u['created_at']);
            $rows .= "<tr><td><strong>{$un}</strong></td><td class='text-muted'>{$em}</td><td class='text-muted text-sm'>{$dt}</td></tr>";
        }

        $content = <<<HTML
<div class="container" style="position:relative;z-index:1">
    <div class="mt-4 mb-3">
        <h1 style="font-size:1.4rem;font-weight:700">Users</h1>
        <p class="text-muted text-sm">User accounts that can authenticate via SSO</p>
    </div>
    {$msg}
    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Create User</span></div>
        <div class="card-body">
            <form method="POST" action="/users">
                {$csrf}
                <div class="grid-2 gap-2">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" placeholder="johndoe" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input class="form-control" name="email" type="email" placeholder="john@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Create User</button>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Username</th><th>Email</th><th>Created</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</div>
HTML;
        View::render('Users', $content);
    }

    public function usersPost(): void
    {
        $this->requireLogin();
        View::verifyCsrf();
        $ok = $this->oauth->createUser(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? '',
            trim($_POST['email'] ?? '')
        );
        $_SESSION['flash'] = $ok ? "User created." : "Username already exists.";
        $this->redirect('/users');
    }
}
