<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /authorize, POST /authorize, POST /token, POST /revoke
class OAuthController extends BaseController
{
    public function authorizeGet(): void
    {
        if (empty($_SESSION['sso_user_id'])) {
            $q = http_build_query($_GET);
            $this->redirect('/login?redirect=' . urlencode('/authorize?' . $q));
        }

        $result = $this->oauth->validateAuthorizationRequest($_GET);
        if (isset($result['error'])) {
            http_response_code(400);
            View::render('Authorization Error', "<div class='page-center'><div class='alert alert-error'>" . View::e($result['error']) . "</div></div>");
            return;
        }

        $_SESSION['oauth_request'] = $result;
        $e = View::e(...);
        $csrf = View::csrf();
        $clientName = $e($result['client']['name']);
        $scopes = explode(' ', $result['scope']);

        $scopeItems = '';
        $scopeLabels = ['mcp' => 'Access MCP tools', 'profile' => 'Read profile info', 'offline_access' => 'Stay signed in (refresh token)'];
        foreach ($scopes as $s) {
            $label = $scopeLabels[$s] ?? $s;
            $scopeItems .= "<li><svg class='scope-icon' viewBox='0 0 20 20' fill='currentColor'><path fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z'/></svg>" . $e($label) . "</li>";
        }

        $content = <<<HTML
<div class="page-center">
<div style="width:100%;max-width:460px" class="fade-up">
    <div class="card">
        <div class="card-header text-center">
            <div style="font-size:1.3rem;font-weight:700">Authorization Request</div>
            <p class="text-muted text-sm mt-1"><strong style="color:var(--text)">{$clientName}</strong> is requesting access to your account</p>
        </div>
        <div class="card-body">
            <p class="text-sm text-muted mb-2">This application will be able to:</p>
            <ul class="scope-list">{$scopeItems}</ul>
            <div class="flex gap-2 mt-3" style="justify-content:stretch">
                <form method="POST" action="/authorize" style="flex:1">
                    {$csrf}
                    <input type="hidden" name="decision" value="deny">
                    <button id="btn-deny" class="btn btn-secondary btn-full" type="submit">Deny</button>
                </form>
                <form method="POST" action="/authorize" style="flex:1">
                    {$csrf}
                    <input type="hidden" name="decision" value="allow">
                    <button id="btn-allow" class="btn btn-primary btn-full" type="submit">Allow Access</button>
                </form>
            </div>
            <p class="text-center text-sm text-muted mt-2">Signed in as <strong style="color:var(--text)">{$e($_SESSION['sso_username'])}</strong></p>
        </div>
    </div>
</div>
</div>
HTML;
        View::render('Authorize', $content);
    }

    public function authorizePost(): void
    {
        View::verifyCsrf();
        if (empty($_SESSION['sso_user_id']) || empty($_SESSION['oauth_request'])) {
            $this->redirect('/login');
        }

        $req = $_SESSION['oauth_request'];
        unset($_SESSION['oauth_request']);

        if (($_POST['decision'] ?? '') !== 'allow') {
            $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';
            $this->redirect($req['redirect_uri'] . $sep . 'error=access_denied&state=' . urlencode($req['state']));
        }

        $code = $this->oauth->issueAuthorizationCode(
            $req['client']['client_id'],
            (int) $_SESSION['sso_user_id'],
            $req['redirect_uri'],
            $req['scope'],
            $req['code_challenge'],
            $req['code_challenge_method']
        );

        $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';
        $this->redirect($req['redirect_uri'] . $sep . http_build_query(['code' => $code, 'state' => $req['state']]));
    }

    public function token(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        $params = array_merge($_POST, (array) json_decode(file_get_contents('php://input') ?: '{}', true));
        $grant  = $params['grant_type'] ?? '';

        $result = match ($grant) {
            'authorization_code' => $this->oauth->exchangeCodeForToken($params),
            'refresh_token'      => $this->oauth->refreshToken($params),
            default              => ['error' => 'unsupported_grant_type'],
        };

        http_response_code(isset($result['error']) ? 400 : 200);
        echo json_encode($result);
    }

    public function revoke(): void
    {
        header('Content-Type: application/json');
        $token = $_POST['token'] ?? '';
        if ($token) $this->oauth->revokeToken($token);
        echo json_encode(['revoked' => true]);
    }
}
