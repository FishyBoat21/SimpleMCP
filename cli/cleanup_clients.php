<?php

declare(strict_types=1);

/**
 * Housekeeping CLI script for Dynamic Client Registration (RFC 7591) and OAuth tokens.
 *
 * Usage:
 *   php cli/cleanup_clients.php                  # Show client summary & expired token count
 *   php cli/cleanup_clients.php --prune          # Prune expired tokens and inactive clients (>30 days)
 *   php cli/cleanup_clients.php --prune --days=7 # Prune inactive clients older than 7 days
 *   php cli/cleanup_clients.php --prune-tokens   # Prune only expired tokens and auth codes
 *   php cli/cleanup_clients.php --delete=<id>    # Delete a specific dynamic client and its tokens
 *   php cli/cleanup_clients.php --dry-run        # Preview actions without modifying database
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use McpServer\Auth\ClientStore;
use McpServer\Auth\Database;
use McpServer\Auth\TokenStore;

$oauthConfig = require dirname(__DIR__) . '/config/oauth.php';
$db = new Database();
$tokenStore = new TokenStore($db);
$clientStore = new ClientStore($db, $oauthConfig['clients'] ?? []);

$options = getopt('', [
    'prune',
    'prune-tokens',
    'dry-run',
    'days::',
    'delete::',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
SimpleMCP OAuth & Client Housekeeping Tool

Usage:
  php cli/cleanup_clients.php [options]

Options:
  --prune             Prune expired tokens and inactive dynamic clients
  --prune-tokens      Prune only expired tokens and auth codes
  --days=<N>          Inactivity threshold in days for client pruning (default: 30)
  --delete=<id>       Delete a specific dynamic client ID and its tokens
  --dry-run           Preview pruning results without executing database deletes
  --help              Display this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$days = isset($options['days']) ? max(1, (int) $options['days']) : 30;
$prune = isset($options['prune']);
$pruneTokens = isset($options['prune-tokens']);
$deleteId = isset($options['delete']) ? (string) $options['delete'] : null;

echo "=== SimpleMCP OAuth Housekeeping ===\n";
if ($dryRun) {
    echo "[DRY RUN MODE - No database changes will be saved]\n\n";
} else {
    echo "\n";
}

// 1. Single client delete
if ($deleteId !== null && $deleteId !== '') {
    echo "Deleting client '{$deleteId}'... ";
    if ($dryRun) {
        echo "[DRY RUN: would delete]\n";
    } else {
        $deleted = $clientStore->deleteDynamic($deleteId);
        echo $deleted ? "DELETED\n" : "NOT FOUND\n";
    }
    exit(0);
}

// 2. Client Listing & Stats
$dynamicClients = $clientStore->listDynamic();
$clientCount = count($dynamicClients);
echo "Dynamically registered clients: {$clientCount}\n";
echo str_repeat('-', 72) . "\n";
printf("%-20s %-20s %-12s %-10s %s\n", "Client ID", "Name", "Auth Method", "Created", "Active Tokens");
echo str_repeat('-', 72) . "\n";

$cutoffTime = time() - ($days * 86400);
$prunableClients = 0;

foreach ($dynamicClients as $client) {
    $createdStr = date('Y-m-d', $client['created_at']);
    $tokensStr = "A:{$client['active_access_tokens']} / R:{$client['active_refresh_tokens']}";
    $isPrunable = ($client['created_at'] < $cutoffTime)
        && ($client['active_access_tokens'] === 0)
        && ($client['active_refresh_tokens'] === 0);

    if ($isPrunable) {
        $prunableClients++;
    }

    $marker = $isPrunable ? ' [PRUNABLE]' : '';
    printf(
        "%-20s %-20s %-12s %-10s %s%s\n",
        substr($client['client_id'], 0, 18) . '..',
        substr($client['client_name'] !== '' ? $client['client_name'] : '(unnamed)', 0, 18),
        $client['auth_method'],
        $createdStr,
        $tokensStr,
        $marker
    );
}

if ($clientCount === 0) {
    echo "  (No dynamic clients registered)\n";
}
echo str_repeat('-', 72) . "\n\n";

// 3. Expired token counts
$pdo = $db->pdo();
$now = time();
$expCodes = (int) $pdo->query("SELECT COUNT(*) FROM auth_codes WHERE expires_at < {$now}")->fetchColumn();
$expAccess = (int) $pdo->query("SELECT COUNT(*) FROM access_tokens WHERE expires_at < {$now}")->fetchColumn();
$expRefresh = (int) $pdo->query("SELECT COUNT(*) FROM refresh_tokens WHERE expires_at < {$now}")->fetchColumn();

echo "Expired tokens awaiting cleanup:\n";
echo "  • Authorization codes: {$expCodes}\n";
echo "  • Access tokens:       {$expAccess}\n";
echo "  • Refresh tokens:      {$expRefresh}\n";
echo "  • Inactive clients:    {$prunableClients} (older than {$days} days with no active tokens)\n\n";

// 4. Execution if --prune or --prune-tokens specified
if ($prune || $pruneTokens) {
    echo "Executing cleanup...\n";
    if ($dryRun) {
        echo "  [DRY RUN] Would delete {$expCodes} auth codes, {$expAccess} access tokens, {$expRefresh} refresh tokens.\n";
        if ($prune) {
            echo "  [DRY RUN] Would delete {$prunableClients} inactive dynamic clients.\n";
        }
    } else {
        $cleaned = $tokenStore->pruneExpired();
        echo "  ✓ Pruned {$cleaned['codes']} auth codes, {$cleaned['access_tokens']} access tokens, {$cleaned['refresh_tokens']} refresh tokens.\n";

        if ($prune) {
            $deletedClients = $clientStore->pruneInactiveClients($days * 86400);
            echo "  ✓ Pruned {$deletedClients} inactive dynamic clients.\n";
        }
    }
    echo "Housekeeping completed successfully.\n";
} else {
    echo "To prune expired tokens and inactive clients, run:\n";
    echo "  php cli/cleanup_clients.php --prune\n";
}
