<?php

declare(strict_types=1);

namespace McpServer\Web;

// Routes: GET /docs
class DocsController extends BaseController
{
    public function docs(): void
    {
        $base = View::baseUrl();
        $content = <<<HTML
<div class="container" style="position:relative;z-index:1;max-width:820px">
    <div class="mt-4 mb-3">
        <h1 style="font-size:1.4rem;font-weight:700">API Documentation</h1>
        <p class="text-muted text-sm">OAuth2 endpoints &amp; MCP authorization guide</p>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Endpoints</span></div>
        <div class="card-body">
            <div class="table-wrap" style="border:none">
                <table>
                    <thead><tr><th>Method</th><th>Path</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><span class="badge badge-accent">GET</span></td><td class="font-mono">/authorize</td><td>Authorization endpoint (redirect user here)</td></tr>
                        <tr><td><span class="badge badge-success">POST</span></td><td class="font-mono">/token</td><td>Token endpoint — exchange code or refresh token</td></tr>
                        <tr><td><span class="badge badge-success">POST</span></td><td class="font-mono">/revoke</td><td>Revoke an access token</td></tr>
                        <tr><td><span class="badge badge-accent">GET/POST</span></td><td class="font-mono">/mcp</td><td>MCP server (requires Bearer token)</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Authorization Code Flow</span></div>
        <div class="card-body">
            <p class="text-sm text-muted mb-2">1. Redirect user to the authorization endpoint:</p>
            <pre>GET {$base}/authorize
  ?response_type=code
  &amp;client_id=YOUR_CLIENT_ID
  &amp;redirect_uri=https://yourapp/callback
  &amp;scope=mcp profile
  &amp;state=RANDOM_STATE
  &amp;code_challenge=BASE64URL_SHA256_OF_VERIFIER   (PKCE)
  &amp;code_challenge_method=S256</pre>

            <p class="text-sm text-muted mt-2 mb-2">2. Exchange the code for tokens:</p>
            <pre>POST {$base}/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&amp;code=AUTH_CODE
&amp;redirect_uri=https://yourapp/callback
&amp;client_id=YOUR_CLIENT_ID
&amp;client_secret=YOUR_SECRET
&amp;code_verifier=YOUR_VERIFIER   (PKCE)</pre>

            <p class="text-sm text-muted mt-2 mb-2">3. Call the MCP server with your token:</p>
            <pre>POST {$base}/index.php
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json

{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}</pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><span style="font-weight:600">Token Refresh</span></div>
        <div class="card-body">
            <pre>POST {$base}/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&amp;refresh_token=YOUR_REFRESH_TOKEN
&amp;client_id=YOUR_CLIENT_ID
&amp;client_secret=YOUR_SECRET</pre>
        </div>
    </div>
</div>
HTML;
        View::render('API Docs', $content);
    }
}
