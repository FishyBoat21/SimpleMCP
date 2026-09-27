<?php

declare(strict_types=1);

/**
 * Account Onboarding Page (File-per-Page with PRG Pattern).
 *
 * GET:  Renders account setup / onboarding form.
 * POST: Validates input, completes onboarding, sets session, and redirects (PRG).
 */

require_once __DIR__ . '/../layout.php';

start_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash_set('error', 'Invalid security token. Please try again.');
        redirect('/account/onboard.php');
    }

    $existingUsername = trim((string) ($_POST['existing_username'] ?? ''));
    $newUsername = trim((string) ($_POST['new_username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $error = '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A valid email address is required for two-factor security.';
    } elseif ($password === '' || $confirm === '' || $password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($existingUsername === '' && $newUsername === '') {
        $error = 'Username is required.';
    } else {
        $user = App::userStore()->onboardUser(
            $existingUsername !== '' ? $existingUsername : null,
            $newUsername,
            $password,
            '',
            $email
        );
        if ($user === null) {
            $error = $existingUsername !== '' ? 'Account is not pending.' : 'Username is already taken.';
        } else {
            // Trust this device upon onboarding
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $token = App::twoFactor()->trustDevice($user->username, $ua, $ip);
            App::twoFactor()->setDeviceCookie($token, is_https());

            $_SESSION['username'] = $user->username;
            session_regenerate_id(true);
            flash_set('msg', 'Account activated successfully! Welcome to SimpleMCP.');
            redirect('/account/index.php');
        }
    }

    // Validation failed
    flash_set('error', $error);
    flash_set('onboard_data', [
        'existing_username' => $existingUsername,
        'new_username' => $newUsername,
        'email' => $email,
    ]);

    $redirUrl = '/account/onboard.php';
    if ($existingUsername !== '') {
        $redirUrl .= '?existing_username=' . urlencode($existingUsername);
    }
    redirect($redirUrl);
}

// ---- GET Request ------------------------------------------------------------

$savedData = flash_get('onboard_data', []);
$existingUsername = (string) ($_GET['existing_username'] ?? ($savedData['existing_username'] ?? ''));
$username = (string) ($_GET['username'] ?? ($savedData['new_username'] ?? ''));
$email = (string) ($savedData['email'] ?? '');

if ($existingUsername !== '' && $username === '') {
    $existingUser = App::userStore()->getByUsername($existingUsername);
    if ($existingUser !== null && empty($email)) {
        $email = (string) ($existingUser['email'] ?? '');
    }
}

ob_start();
?>
<form method="post" action="/account/onboard.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="existing_username" value="<?= htmlspecialchars($existingUsername, ENT_QUOTES) ?>">

    <?php if ($existingUsername !== ''): ?>
        <p class="hint" style="margin-bottom: 1.25rem;">
            Setting up account for <strong><?= htmlspecialchars($existingUsername) ?></strong>.
        </p>
    <?php else: ?>
        <label>
            Username
            <input type="text" name="new_username" value="<?= htmlspecialchars($username, ENT_QUOTES) ?>" required autofocus autocomplete="username">
        </label>
    <?php endif; ?>

    <label>
        Email Address
        <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>" required autocomplete="email" placeholder="you@example.com">
        <span class="hint">Required for two-factor verification on new devices.</span>
    </label>

    <label>
        Password (min 8 characters)
        <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
    </label>

    <label>
        Confirm Password
        <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
    </label>

    <button type="submit">Complete Setup</button>
</form>

<p class="hint" style="margin-top: 1.25rem; text-align: center;">
    Already have an account? <a href="/account/login.php">Sign In</a>.
</p>
<?php
$content = ob_get_clean();

render_html('Set Up Your Account', $content, ['subtitle' => 'Initialize your password and two-factor security email']);
