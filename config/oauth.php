<?php

declare(strict_types=1);

/**
 * Configuration for the self-hosted OAuth 2.1 Authorization Server.
 *
 * Each client has a `token_endpoint_auth_method`:
 * - `none`                 — public client; authenticates via PKCE (S256 required).
 * - `client_secret_post`   — confidential; sends `client_secret` in the token body.
 * - `client_secret_basic`  — confidential; sends HTTP Basic (id:secret).
 *
 * `client_secret_basic` is accepted for explicitly-registered clients but is NOT
 * advertised in the discovery metadata: Cherry Studio (and other MCP clients)
 * fail the OAuth handshake when it is offered. Dynamically registered clients
 * default to `client_secret_post` for the same reason.
 *
 * Confidential clients may skip PKCE at the authorize endpoint. `redirect_uris`
 * must match the URI sent by clients exactly (OAuth requires exact matching).
 * Loopback http://localhost and http://127.0.0.1 are accepted for local
 * development; all other URIs must be https.
 *
 * Set `registration_access_token` to a non-empty value to require an initial
 * access token (`Authorization: Bearer <token>`) on POST /oauth/register.
 */
return [
    'clients' => [
        // Public client for MCP clients that use the interactive browser flow (PKCE).
        [
            'client_id' => 'mcp-client',
            'token_endpoint_auth_method' => 'none',
            'redirect_uris' => [
                'http://localhost:5173/callback',
                'http://127.0.0.1:5173/callback',
            ],
        ],
        // Confidential client for Open WebUI. Use "OAuth 2.1 (Static)" in its
        // MCP server config and paste this client id + secret. Adjust the
        // redirect URI to match your Open WebUI's OAuth callback.
        [
            'client_id' => 'openwebui',
            'token_endpoint_auth_method' => 'client_secret_post',
            'client_secret' => '6eb3d189f60768ced96d0a997a9d56bcc27d8aed14d35c55',
            'redirect_uris' => [
                'http://localhost:3000/oauth2/callback',
                'http://127.0.0.1:3000/oauth2/callback',
            ],
        ],
    ],
    // Seconds a token/code stays valid. The access token TTL is generous so
    // clients that cache tokens (e.g. Cherry Studio) don't hit expiry mid-session.
    'access_token_ttl' => 3600,       // 1 hour
    'refresh_token_ttl' => 2592000,   // 30 days
    'auth_code_ttl' => 600,           // 10 minutes

    // S256 is the only PKCE method MCP clients must use; allow "plain" explicitly.
    'allow_plain_pkce' => false,

    // Set to a non-empty value to protect POST /oauth/register (RFC 7591 DCR).
    'registration_access_token' => null,
];
