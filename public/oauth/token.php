<?php

declare(strict_types=1);

/**
 * OAuth 2.1 Token Endpoint (Standalone File-per-API).
 *
 * POST: Handles authorization_code and refresh_token grants with PKCE and client authentication.
 */

require_once __DIR__ . '/../bootstrap.php';

use McpServer\Auth\ClientStore;
use McpServer\Auth\DebugLog;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_response(405, ['error' => 'invalid_request', 'error_description' => 'Token endpoint requires POST.']);
}

$rawBody = file_get_contents('php://input');
$post = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $post = json_decode($rawBody, true) ?: [];
} else {
    parse_str($rawBody, $post);
}

$grantType = (string) ($post['grant_type'] ?? '');
$config = App::oauthConfig();

// Authenticate client
function authenticate_client(array $post): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $basic = null;
    if (preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$id, $secret] = explode(':', $decoded, 2);
            $basic = [rawurldecode($id), rawurldecode($secret)];
        }
    } elseif (isset($_SERVER['PHP_AUTH_USER'])) {
        $basic = [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
    }

    // 1. client_secret_basic
    if ($basic !== null) {
        [$id, $secret] = $basic;
        $client = App::clientStore()->find($id);
        if ($client !== null
            && ($client['token_endpoint_auth_method'] ?? '') === 'client_secret_basic'
            && App::clientStore()->verifySecret($id, $secret)) {
            return $client;
        }
        return null;
    }

    $postId = (string) ($post['client_id'] ?? '');
    $postSecret = (string) ($post['client_secret'] ?? '');

    // 2. client_secret_post
    if ($postId !== '' && $postSecret !== '') {
        $client = App::clientStore()->find($postId);
        if ($client !== null
            && ($client['token_endpoint_auth_method'] ?? '') === 'client_secret_post'
            && App::clientStore()->verifySecret($postId, $postSecret)) {
            return $client;
        }
        return null;
    }

    // 3. Public client (auth method `none` with PKCE)
    if ($postId !== '') {
        $client = App::clientStore()->find($postId);
        if ($client !== null && ($client['token_endpoint_auth_method'] ?? 'none') === 'none') {
            return $client;
        }
    }

    return null;
}

$client = authenticate_client($post);
if ($client === null) {
    DebugLog::write("TOKEN grant={$grantType} client_auth=failed => 401");
    json_response(401, ['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']);
}
$clientId = (string) $client['client_id'];

function base64_url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

if ($grantType === 'authorization_code') {
    $code = (string) ($post['code'] ?? '');
    $redirectUri = (string) ($post['redirect_uri'] ?? '');
    $verifier = (string) ($post['code_verifier'] ?? '');

    if ($code === '' || $redirectUri === '') {
        json_response(400, ['error' => 'invalid_request', 'error_description' => 'Missing code or redirect_uri.']);
    }

    $redeemed = App::tokenStore()->consumeAuthCode($code, ['client_id' => $clientId, 'redirect_uri' => $redirectUri]);
    if ($redeemed === null) {
        json_response(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid, expired or already-used authorization code.']);
    }

    $expectedChallenge = $redeemed['code_challenge'];
    if ($expectedChallenge !== '') {
        if (!preg_match('/^[A-Za-z0-9_.~-]{43,128}$/', $verifier)) {
            json_response(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed: malformed code_verifier.']);
        }

        $ok = false;
        if ($redeemed['code_challenge_method'] === 'S256') {
            $ok = hash_equals($expectedChallenge, base64_url_encode(hash('sha256', $verifier, true)));
        } elseif (($config['allow_plain_pkce'] ?? false) === true) {
            $ok = hash_equals($expectedChallenge, $verifier);
        }

        if (!$ok) {
            json_response(400, ['error' => 'invalid_grant', 'error_description' => 'PKCE verification failed: challenge mismatch.']);
        }
    }

    $accessTtl = (int) ($config['access_token_ttl'] ?? 300);
    $refreshTtl = (int) ($config['refresh_token_ttl'] ?? 2592000);
    $username = (string) $redeemed['username'];

    $accessToken = App::tokenStore()->createAccessToken($username, $clientId, $accessTtl);
    $refreshToken = App::tokenStore()->createRefreshToken($username, $clientId, $refreshTtl);

    DebugLog::write("TOKEN grant=authorization_code client={$clientId} user={$username} => 200 ok");
    json_response(200, [
        'access_token' => $accessToken,
        'token_type' => 'Bearer',
        'expires_in' => $accessTtl,
        'refresh_token' => $refreshToken,
    ]);
}

if ($grantType === 'refresh_token') {
    $refreshToken = (string) ($post['refresh_token'] ?? '');
    if ($refreshToken === '') {
        json_response(400, ['error' => 'invalid_request', 'error_description' => 'Missing refresh_token.']);
    }

    $accessTtl = (int) ($config['access_token_ttl'] ?? 300);
    $refreshTtl = (int) ($config['refresh_token_ttl'] ?? 2592000);

    $rotated = App::tokenStore()->rotateRefreshToken($refreshToken, $accessTtl, $refreshTtl);
    if ($rotated === null) {
        json_response(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid or expired refresh token.']);
    }

    DebugLog::write("TOKEN grant=refresh_token client={$clientId} => 200 ok");
    json_response(200, [
        'access_token' => $rotated['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $accessTtl,
        'refresh_token' => $rotated['refresh_token'],
    ]);
}

json_response(400, [
    'error' => 'unsupported_grant_type',
    'error_description' => 'Supported grant types: authorization_code, refresh_token',
]);
