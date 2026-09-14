<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;
use McpServer\Auth\ClipboardStore;
use McpServer\UserContext;
use Throwable;

/**
 * The clipboard tool: a per-user, size-capped store for bulky text, so a large
 * tool result never has to occupy the model's context window.
 *
 * One tool with an `action` switch rather than four verb tools, and deliberately
 * so: every tool definition is itself always-resident context, and this feature
 * exists to reduce that. One definition costs roughly a quarter of four.
 *
 * Tools that can return a lot of text declare an `offloadAt` threshold on their
 * #[McpFunction] attribute (see src/McpServer.php). A result over that size is
 * stored here automatically and replaced in the response by a receipt carrying a
 * clip id — so the model usually meets the clipboard without calling it. This
 * tool is the explicit half: stash something deliberately, see what is held, read
 * a clip back, pin it against eviction, or drop it.
 *
 * Retention is enforced by {@see ClipboardStore}: at most `clipboard.max_entries`
 * (default 10) live clips per user, least-recently-used evicted first, pinned
 * clips exempt, and `ttl_seconds` expiring the rest. A clip that has been evicted,
 * expired or deleted resolves to a message saying so rather than a bare "not
 * found", so a dead handle is diagnosable.
 *
 * This tool declares no `offloadAt` of its own: its `get` reply is multi-block by
 * design and must never be re-offloaded into another clip.
 *
 * Access: requires the `user` or `admin` role, like the memory and knowledge-base
 * tools, so clips stay inside an authenticated per-user namespace. Anonymous HTTP
 * callers see none of this and get a -32001 on direct calls; in stdio mode the
 * injected `local` user holds the `*` role, so access still works. Note that this
 * also means the automatic offload path is skipped for anonymous callers, who
 * keep receiving full results inline.
 */
readonly class ClipboardTool {
    /** @var string[] login required, mirroring MemoryTool::REQUIRED_ROLES */
    private const REQUIRED_ROLES = ['user', 'admin'];

    #[McpFunction(
        name: 'clipboard',
        roles: self::REQUIRED_ROLES,
        description: 'Per-user clipboard for bulky text, so large payloads do not consume the context window. Actions: put stores content and returns a clip id; list shows the clips you hold; get reads one back (in byte windows); delete removes one; pin/unpin exempt a clip from eviction. Clips are capped at 10 per user and evicted least-recently-used first, so read what you still need and re-run the producing tool if a clip has been evicted. Tools with large output (convert_pdf_to_markdown, get_document, read_graph, ...) store their result here automatically and return a receipt with the clip id — pass that id to a tool that accepts clip_id (e.g. ingest_document) to move data between tools without it ever entering the context window.',
        schema: [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['put', 'list', 'get', 'delete', 'pin', 'unpin'],
                    'description' => 'What to do. put = store content and get a clip id. list = show the clips you hold. get = read a clip back. delete = drop a clip. pin/unpin = exempt a clip from eviction, or stop doing so.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'For action=put: the text to store.',
                ],
                'id' => [
                    'type' => 'string',
                    'description' => 'Clip id, for action=get, delete, pin and unpin.',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'For action=put: an optional short human-readable label, shown in list so you can tell clips apart later.',
                ],
                'ttl_seconds' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'For action=put: how long the clip lives before it expires and is swept. 0 means it never expires. Defaults to 24 hours.',
                ],
                'pin' => [
                    'type' => 'boolean',
                    'description' => 'For action=put: store the clip pinned, so eviction never removes it.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'For action=get: byte offset to start from, for paging through a long clip.',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'For action=get: how much to return this call. Defaults to 20000; hard max 100000. Despite the name the unit is bytes (a UTF-8-safe cut), so for CJK text this is fewer characters than bytes. A small value such as 300 is a cheap peek — and, like any read, it refreshes the clip\'s recency.',
                ],
            ],
            'required' => ['action'],
        ]
    )]
    public function handle(array $arguments, ?UserContext $user = null): array {
        $user ??= UserContext::anonymous();
        $action = strtolower(trim((string) ($arguments['action'] ?? '')));

        try {
            $store = new ClipboardStore();

            return match ($action) {
                'put' => $this->put($store, $user, $arguments),
                'list' => $this->listClips($store, $user),
                'get' => $this->get($store, $user, $arguments),
                'delete' => $this->delete($store, $user, $arguments),
                'pin' => $this->setPinned($store, $user, $arguments, true),
                'unpin' => $this->setPinned($store, $user, $arguments, false),
                default => self::text("Error: 'action' must be one of: put, list, get, delete, pin, unpin."),
            };
        } catch (Throwable $e) {
            // Tool failures are text blocks here, never isError — see README.
            return self::text('Error: the clipboard is unavailable: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $arguments */
    private function put(ClipboardStore $store, UserContext $user, array $arguments): array {
        $content = $arguments['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return self::text("Error: 'content' must be a non-empty string.");
        }

        $ttl = $arguments['ttl_seconds'] ?? null;
        if ($ttl !== null && (!is_numeric($ttl) || (int) $ttl < 0)) {
            return self::text("Error: 'ttl_seconds' must be a non-negative integer (0 means never expires).");
        }

        $result = $store->put(
            $user->username,
            $content,
            is_string($arguments['label'] ?? null) ? (string) $arguments['label'] : '',
            'clipboard',
            (bool) ($arguments['pin'] ?? false),
            $ttl === null ? null : (int) $ttl,
        );

        if (isset($result['error'])) {
            return self::text('Error: ' . $result['error']);
        }

        return self::text(implode("\n", $this->storedLines($result)));
    }

    private function listClips(ClipboardStore $store, UserContext $user): array {
        $result = $store->listClips($user->username);
        $json = (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($result['total'] === 0) {
            return [
                ['type' => 'text', 'text' => $json],
                ['type' => 'text', 'text' => 'Clipboard is empty. Store a payload with clipboard action=put (content, optional label/ttl_seconds), then pass its clip id to another tool instead of the payload.'],
            ];
        }

        $lines = ['Clipboard: ' . $result['total'] . ' of ' . $result['maxEntries'] . ' slots used, '
            . $result['pinnedCount'] . ' pinned. Least recently read is evicted first.'];
        foreach ($result['clips'] as $clip) {
            $lines[] = '- ' . $clip['id']
                . ($clip['label'] !== '' ? ' "' . $clip['label'] . '"' : ' (no label)')
                . ' — ' . number_format($clip['bytes']) . ' bytes'
                . ($clip['source'] !== '' ? ', from ' . $clip['source'] : '')
                . ', last read ' . $clip['lastAccess']
                . ($clip['pinned'] ? ', pinned (never evicted)' : '')
                . ($clip['expiresAt'] !== null ? ', expires ' . $clip['expiresAt'] : ', never expires')
                . '.';
        }
        $lines[] = 'Read one with: clipboard action=get id=<id>';

        return [
            ['type' => 'text', 'text' => $json],
            ['type' => 'text', 'text' => implode("\n", $lines)],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function get(ClipboardStore $store, UserContext $user, array $arguments): array {
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return self::text("Error: 'id' is required for action=get.");
        }

        $offset = is_numeric($arguments['offset'] ?? null) ? max(0, (int) $arguments['offset']) : 0;
        $maxChars = is_numeric($arguments['max_chars'] ?? null) ? (int) $arguments['max_chars'] : $store->defaultMaxChars();

        $result = $store->get($user->username, $id, $offset, $maxChars);
        if (isset($result['error'])) {
            return self::text('Error: ' . $result['error']);
        }

        $content = (string) $result['content'];
        unset($result['content']);

        // Three blocks so nothing is a parsing hazard: metadata JSON stays
        // parseable, the payload stays byte-exact, and the paging hint stays
        // skippable.
        $blocks = [
            ['type' => 'text', 'text' => (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
        ];

        if ($result['returned'] > 0) {
            $blocks[] = ['type' => 'text', 'text' => $content];
        }

        if ($result['truncated'] === true) {
            $blocks[] = ['type' => 'text', 'text' => '[truncated] showing bytes ' . number_format($result['offset'])
                . '–' . number_format($result['offset'] + $result['returned']) . ' of ' . number_format($result['total'])
                . '. Continue with: clipboard action=get id=' . $result['id'] . ' offset=' . $result['nextOffset']
                . ' (or raise max_chars, ceiling ' . number_format($store->hardMaxChars()) . ' per call).'];
        } elseif ($result['returned'] === 0) {
            $blocks[] = ['type' => 'text', 'text' => '[empty] offset ' . number_format($result['offset'])
                . ' is past the end (total ' . number_format($result['total']) . ').'];
        }

        if ($result['clamped'] === true) {
            $blocks[] = ['type' => 'text', 'text' => 'Note: max_chars was clamped to '
                . number_format($store->hardMaxChars()) . '.'];
        }

        if ($result['encoding'] === 'base64') {
            $blocks[] = ['type' => 'text', 'text' => 'Note: this clip is not valid UTF-8, so it is stored and returned base64-encoded.'];
        }

        return $blocks;
    }

    /** @param array<string, mixed> $arguments */
    private function delete(ClipboardStore $store, UserContext $user, array $arguments): array {
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return self::text("Error: 'id' is required for action=delete.");
        }

        $result = $store->delete($user->username, $id);
        if (($result['deleted'] ?? false) === false) {
            return self::text('Warning: ' . ($result['warning'] ?? 'unknown error'));
        }

        return self::text('Deleted clip ' . $result['id'] . ' (' . number_format($result['bytes']) . ' bytes'
            . ($result['label'] !== '' ? ', label "' . $result['label'] . '"' : '') . '). Its content is gone.');
    }

    /** @param array<string, mixed> $arguments */
    private function setPinned(ClipboardStore $store, UserContext $user, array $arguments, bool $pinned): array {
        $verb = $pinned ? 'pin' : 'unpin';
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return self::text("Error: 'id' is required for action=$verb.");
        }

        $result = $store->setPinned($user->username, $id, $pinned);
        if (isset($result['error'])) {
            return self::text('Error: ' . $result['error']);
        }

        $note = $pinned
            ? ' It is now exempt from eviction — the clipboard may hold more than its usual entry count to keep it.'
            : ' It is evictable again, and will go first if it is the least recently read.';

        return self::text(($pinned ? 'Pinned' : 'Unpinned') . ' clip ' . $result['id']
            . ($result['label'] !== '' ? ' ("' . $result['label'] . '")' : '') . '.' . $note);
    }

    /**
     * The lines describing a completed put: what was stored, how full the
     * clipboard now is, what (if anything) had to be evicted to make room, and
     * the exact call that reads the clip back.
     *
     * No preview here, unlike the automatic offload receipt: the caller just
     * authored this content, so echoing it back is pure waste. It is the one
     * place the two receipts deliberately differ.
     *
     * @param array<string, mixed> $result
     * @return string[]
     */
    private function storedLines(array $result): array {
        $lines = [];

        if (($result['reused'] ?? false) === true) {
            $lines[] = 'Reused clip ' . $result['id'] . ' — it already holds identical content ('
                . number_format($result['bytes']) . ' bytes, sha256 '
                . substr((string) $result['sha256'], 0, 8) . '…). Its recency and expiry were refreshed '
                . 'instead of storing a second copy.';
        } else {
            $lines[] = 'Stored clip ' . $result['id'] . ' (' . number_format($result['bytes']) . ' bytes'
                . ($result['label'] !== '' ? ', label "' . $result['label'] . '"' : '')
                . ', source ' . $result['source'] . ').';
        }

        $lines[] = 'Clipboard: ' . $result['live'] . ' of ' . $result['maxEntries'] . ' slots used, '
            . $result['pinnedCount'] . ' pinned. ' . $result['expiry'];

        if ($result['ttlClamped'] ?? false) {
            $lines[] = 'Note: ttl_seconds was clamped to the configured maximum.';
        }

        if (($result['overLimit'] ?? false) === true) {
            $lines[] = 'Warning: the clipboard is over its ' . $result['maxEntries'] . '-entry limit ('
                . $result['live'] . ' stored, ' . $result['pinnedCount'] . ' pinned). Pinned clips are never evicted — '
                . 'unpin one with clipboard action=unpin id=<id> to make it evictable again.';
        }

        $evicted = $result['evicted'] ?? [];
        if ($evicted !== []) {
            $count = count($evicted);
            $lines[] = 'Evicted ' . $count . ' least-recently-used ' . ($count === 1 ? 'clip' : 'clips') . ' to make room:';
            foreach ($evicted as $victim) {
                $lines[] = '  - ' . $victim['id']
                    . ($victim['label'] !== '' ? ' "' . $victim['label'] . '"' : '')
                    . ' (' . number_format($victim['bytes']) . ' bytes'
                    . ($victim['source'] !== '' ? ', from ' . $victim['source'] : '')
                    . ', last read ' . $victim['lastAccess'] . '). Its content is gone; re-run the tool that produced it if you still need it.';
            }
        }

        $lines[] = 'Read it back with: clipboard action=get id=' . $result['id'];

        return $lines;
    }

    /** A one-block tool result. */
    private static function text(string $text): array {
        return [['type' => 'text', 'text' => $text]];
    }
}
