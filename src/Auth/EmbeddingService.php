<?php

declare(strict_types=1);

namespace McpServer\Auth;

/**
 * Service for generating dense vector embeddings via OpenAI Embedding API
 * (e.g. text-embedding-3-small, text-embedding-3-large, text-embedding-ada-002)
 * and performing cosine similarity calculations.
 *
 * Provides binary vector serialization (pack/unpack with 32-bit floats) for
 * compact and fast SQLite storage, plus batch embedding generation.
 */
class EmbeddingService {
    public const DEFAULT_MODEL = 'text-embedding-3-small';
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private array $config;
    /** @var (callable(array<string, mixed>): array<int, array<int, float>>)|null */
    private $transport;

    /**
     * @param array<string, mixed>|null $config Optional explicit configuration
     * @param (callable(array<string, mixed>): array<int, array<int, float>>)|null $transport Optional mock transport for testing
     */
    public function __construct(?array $config = null, ?callable $transport = null) {
        $this->transport = $transport;
        if ($config !== null) {
            $this->config = $config;
            return;
        }

        $loadedConfig = [];
        $root = dirname(__DIR__, 2);

        $embedFile = $root . '/config/embedding.php';
        if (file_exists($embedFile)) {
            $loaded = require $embedFile;
            if (is_array($loaded)) {
                $loadedConfig = $loaded;
            }
        } else {
            $configFile = $root . '/config/config.php';
            if (file_exists($configFile)) {
                $appCfg = require $configFile;
                if (is_array($appCfg) && isset($appCfg['embedding']) && is_array($appCfg['embedding'])) {
                    $loadedConfig = $appCfg['embedding'];
                }
            }
        }

        $this->config = $loadedConfig;
    }

    /**
     * Whether OpenAI embedding is configured and active.
     * When using custom/local endpoints (e.g. LM Studio, Ollama), an API key is optional.
     */
    public function isConfigured(): bool {
        if ($this->getModel() === '') {
            return false;
        }
        $key = $this->getApiKey();
        if ($key !== '') {
            return true;
        }
        $baseUrl = $this->getBaseUrl();
        return $baseUrl !== '' && !str_starts_with($baseUrl, 'https://api.openai.com');
    }

    public function getApiKey(): string {
        $key = (string) ($this->config['api_key'] ?? '');
        if ($key === '') {
            $envKey = getenv('OPENAI_API_KEY');
            if (is_string($envKey) && $envKey !== '') {
                return $envKey;
            }
            if (isset($_ENV['OPENAI_API_KEY']) && is_string($_ENV['OPENAI_API_KEY'])) {
                return $_ENV['OPENAI_API_KEY'];
            }
        }
        return $key;
    }

    public function getModel(): string {
        $model = (string) ($this->config['model'] ?? '');
        if ($model === '') {
            $envModel = getenv('OPENAI_EMBEDDING_MODEL');
            if (is_string($envModel) && $envModel !== '') {
                return $envModel;
            }
            if (isset($_ENV['OPENAI_EMBEDDING_MODEL']) && is_string($_ENV['OPENAI_EMBEDDING_MODEL'])) {
                return $_ENV['OPENAI_EMBEDDING_MODEL'];
            }
            return self::DEFAULT_MODEL;
        }
        return $model;
    }

    public function getBaseUrl(): string {
        $url = (string) ($this->config['base_url'] ?? '');
        if ($url === '') {
            $envUrl = getenv('OPENAI_BASE_URL');
            if (is_string($envUrl) && $envUrl !== '') {
                $url = $envUrl;
            } elseif (isset($_ENV['OPENAI_BASE_URL']) && is_string($_ENV['OPENAI_BASE_URL'])) {
                $url = $_ENV['OPENAI_BASE_URL'];
            } else {
                return self::DEFAULT_BASE_URL;
            }
        }
        $url = rtrim($url, '/');
        // Normalize /api/v1 to /v1 (common when configuring LM Studio / local server)
        if (preg_match('#/api/v1$#i', $url)) {
            $url = preg_replace('#/api/v1$#i', '/v1', $url);
        }
        return $url;
    }

    public function getDimensions(): ?int {
        $dims = $this->config['dimensions'] ?? null;
        if (is_numeric($dims) && (int) $dims > 0) {
            return (int) $dims;
        }
        $envDims = getenv('OPENAI_EMBEDDING_DIMENSIONS');
        if (is_numeric($envDims) && (int) $envDims > 0) {
            return (int) $envDims;
        }
        return null;
    }

    public function getTimeout(): int {
        return max(3, (int) ($this->config['timeout'] ?? 15));
    }

    /**
     * Generate embeddings for one or more text inputs.
     *
     * @param string|string[] $input Single text string or array of texts
     * @return array<int, array<int, float>> Array of float embedding vectors
     */
    public function embed(string|array $input): array {
        if (!$this->isConfigured()) {
            return [];
        }

        $texts = is_array($input) ? array_values($input) : [$input];
        if ($texts === []) {
            return [];
        }

        // Clean text: OpenAI returns 400 for empty string, replace with space
        $cleanTexts = [];
        foreach ($texts as $text) {
            $str = trim((string) $text);
            $cleanTexts[] = $str === '' ? ' ' : $str;
        }

        // Use custom transport if injected (e.g. for testing)
        if ($this->transport !== null) {
            return ($this->transport)([
                'model' => $this->getModel(),
                'input' => $cleanTexts,
                'dimensions' => $this->getDimensions(),
            ]);
        }

        // Batch in groups of 100 to stay within request size limits
        $batches = array_chunk($cleanTexts, 100);
        $allEmbeddings = [];

        foreach ($batches as $batch) {
            $batchResult = $this->requestEmbeddings($batch);
            if ($batchResult === null) {
                // Return whatever we have or fail gracefully
                return [];
            }
            foreach ($batchResult as $vec) {
                $allEmbeddings[] = $vec;
            }
        }

        return $allEmbeddings;
    }

    /**
     * Perform HTTP request to the OpenAI embeddings endpoint.
     *
     * @param string[] $inputs
     * @return array<int, array<int, float>>|null
     */
    private function requestEmbeddings(array $inputs): ?array {
        $payload = [
            'model' => $this->getModel(),
            'input' => $inputs,
        ];
        $dims = $this->getDimensions();
        if ($dims !== null) {
            $payload['dimensions'] = $dims;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            DebugLog::append('openai_embedding', 'Failed to JSON encode embedding request payload');
            return null;
        }

        $url = $this->getBaseUrl() . '/embeddings';
        $apiKey = $this->getApiKey();
        $timeout = $this->getTimeout();

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $httpCode < 200 || $httpCode >= 300) {
                DebugLog::append('openai_embedding', "HTTP $httpCode error from $url: $error - body: " . substr((string) $response, 0, 500));
                return null;
            }
            $body = (string) $response;
        } else {
            // Stream context fallback
            $headerLines = implode("\r\n", $headers) . "\r\n";
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => $headerLines,
                    'content' => $jsonPayload,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                DebugLog::append('openai_embedding', "Stream context request failed to $url");
                return null;
            }
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            DebugLog::append('openai_embedding', 'Invalid JSON response from OpenAI: ' . substr($body, 0, 300));
            return null;
        }

        // Sort results by index to ensure order matches input
        $items = $decoded['data'];
        usort($items, static fn(array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $embeddings = [];
        foreach ($items as $item) {
            if (isset($item['embedding']) && is_array($item['embedding'])) {
                $embeddings[] = array_map('floatval', $item['embedding']);
            }
        }

        return $embeddings;
    }

    /**
     * Packs an array of floats into a binary string using 32-bit single-precision floats.
     *
     * @param array<int, float> $vector
     */
    public static function packVector(array $vector): string {
        return pack('f*', ...$vector);
    }

    /**
     * Unpacks a binary string of 32-bit floats back into a PHP array of floats.
     *
     * @return array<int, float>
     */
    public static function unpackVector(string $blob): array {
        if ($blob === '') {
            return [];
        }
        $unpacked = unpack('f*', $blob);
        return $unpacked !== false ? array_values($unpacked) : [];
    }

    /**
     * Compute Cosine Similarity between two float vectors.
     * Returns a float between -1.0 and 1.0 (typically 0.0 to 1.0 for normalized text embeddings).
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    public static function cosineSimilarity(array $a, array $b): float {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $valA = $a[$i];
            $valB = $b[$i];
            $dot += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Dot product of two vectors (equivalent to cosine similarity if vectors are unit-normalized).
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    public static function dotProduct(array $a, array $b): float {
        $count = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }
}
