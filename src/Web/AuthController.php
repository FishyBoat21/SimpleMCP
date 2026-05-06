<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /login, POST /login, GET /logout
class AuthController extends BaseController
{
    public function loginForm(): void
    {
        if (!empty($_SESSION['sso_user_id'])) $this->redirect('/');
        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);
        $e = View::e(...);
        $csrf = View::csrf();

        $errorHtml = $error
            ? "<div class='alert alert-error'>⚠ {$e($error)}</div>"
            : '';

        $redirect = $e($_GET['redirect'] ?? '');
        $redirectInput = $redirect ? "<input type='hidden' name='redirect' value='{$redirect}'>" : '';

        $content = <<<HTML
<div class="page-center">
<div style="width:100%;max-width:420px" class="fade-up">
    <div class="text-center mb-3">
        <div style="width:52px;height:52px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;margin:0 auto .75rem">M</div>
        <h1 style="font-size:1.5rem;font-weight:700">Sign in to SimpleMCP</h1>
        <p class="text-muted text-sm mt-1">OAuth2 Authorization Server</p>
    </div>
    <div class="card">
        <div class="card-body">
            {$errorHtml}
            <form method="POST" action="/login">
                {$csrf}
                {$redirectInput}
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input id="username" class="form-control" type="text" name="username" placeholder="Enter username" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input id="password" class="form-control" type="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                </div>
                <button id="btn-login" class="btn btn-primary btn-full mt-2" type="submit">Sign In</button>
            </form>
        </div>
    </div>
    <p class="text-center text-sm text-muted mt-2">Default: <code>admin / admin</code></p>
</div>
</div>
HTML;
        View::render('Sign In', $content);
    }

    public function loginPost(): void
    {
        View::verifyCsrf();
        $user = $this->oauth->authenticateUser(
            trim($_POST['username'] ?? ''),
            $_POST['password'] ?? ''
        );
        if ($user) {
            $_SESSION['sso_user_id']   = $user['id'];
            $_SESSION['sso_username']  = $user['username'];
            $_SESSION['sso_user_role'] = $user['role'] ?? 'user';
            $redirect = $_POST['redirect'] ?? '/';
            $this->redirect($redirect);
        }
        $_SESSION['login_error'] = 'Invalid username or password.';
        $this->redirect('/login');
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('/login');
    }
}
