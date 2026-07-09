<?php
declare(strict_types=1);

/**
 * uMap GeoJSON gzip Proxy - PHP variant
 *
 * Fetches a fixed remote GeoJSON source, decompresses gzip payloads if needed,
 * validates JSON, caches the result on disk, and serves plain GeoJSON.
 *
 * Usage in uMap:
 *   URL:    https://your-domain.example/geojson-proxy.php
 *   Format: GeoJSON
 */

const VERSION = '1.0.0';

try {
    $config = loadConfig();

    if (PHP_SAPI === 'cli') {
        cliMain($argv, $config);
        exit;
    }

    webMain($config);
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "error: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    sendError(500, 'internal proxy error', $e->getMessage());
}

/**
 * Adjust defaults here if your webspace cannot set environment variables.
 */
function loadConfig(): array
{
    return [
        // Required. Can also be configured via SOURCE_URL environment variable.
        'source_url' => envString('SOURCE_URL', 'https://example.com/export'),

        // Cache directory must be writable by PHP.
        'cache_dir' => envString('CACHE_DIR', __DIR__ . '/cache'),

        // Fresh cache lifetime.
        'cache_ttl' => parseDuration(envString('CACHE_TTL', '5m')),

        // How long stale cache may be served if refresh fails.
        'stale_ttl' => parseDuration(envString('STALE_TTL', '24h')),

        // Maximum source response size after fetch and after decompression.
        'max_bytes' => (int) envString('MAX_BYTES', '104857600'), // 100 MB

        // HTTP timeout for upstream requests.
        'http_timeout' => (int) envString('HTTP_TIMEOUT', '30'),

        'user_agent' => envString('USER_AGENT', 'umap-geojson-gzip-proxy-php/' . VERSION),
    ];
}

function webMain(array $config): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'OPTIONS') {
        setCORSHeaders();
        http_response_code(204);
        return;
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        setCORSHeaders();
        header('Allow: GET, HEAD, OPTIONS');
        sendError(405, 'method not allowed');
        return;
    }

    validateConfig($config);
    ensureCacheDir($config['cache_dir']);

    $now = time();

    $entry = loadCache($config);
    if ($entry !== null && $now < $entry['expires_at']) {
        serveCache($entry, 'HIT', $method, $config);
        return;
    }

    $lockPath = $config['cache_dir'] . '/refresh.lock';
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        sendError(500, 'could not open cache lock file');
        return;
    }

    try {
        flock($lock, LOCK_EX);

        // Another request may have refreshed the cache while we waited.
        $entry = loadCache($config);
        if ($entry !== null && time() < $entry['expires_at']) {
            serveCache($entry, 'HIT', $method, $config);
            return;
        }

        try {
            $body = fetchAndPrepare($config);
            $entry = saveCache($config, $body);
            serveCache($entry, 'MISS', $method, $config);
            return;
        } catch (Throwable $e) {
            error_log('GeoJSON proxy refresh failed: ' . $e->getMessage());

            $entry = loadCache($config);
            if ($entry !== null && time() < $entry['stale_until']) {
                header('Warning: 110 - "Response is stale because source refresh failed"');
                serveCache($entry, 'STALE', $method, $config);
                return;
            }

            sendError(502, 'could not fetch valid GeoJSON from source', $e->getMessage());
            return;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cliMain(array $argv, array $config): void
{
    $args = array_slice($argv, 1);

    if (in_array('-h', $args, true) || in_array('--help', $args, true)) {
        echo helpText();
        return;
    }

    if (in_array('--version', $args, true)) {
        echo VERSION . PHP_EOL;
        return;
    }

    if (in_array('--warm-cache', $args, true)) {
        validateConfig($config);
        ensureCacheDir($config['cache_dir']);

        $body = fetchAndPrepare($config);
        $entry = saveCache($config, $body);

        echo "cache refreshed\n";
        echo "etag: " . $entry['etag'] . "\n";
        echo "bytes: " . strlen($entry['body']) . "\n";
        echo "expires_at: " . gmdate(DATE_ATOM, $entry['expires_at']) . "\n";
        echo "stale_until: " . gmdate(DATE_ATOM, $entry['stale_until']) . "\n";
        return;
    }

    echo helpText();
}

function helpText(): string
{
    return <<<TEXT
uMap GeoJSON gzip Proxy - PHP variant

Fetches a fixed remote GeoJSON source, decompresses gzip payloads if needed,
validates JSON, caches the result on disk, and serves plain GeoJSON.

Web usage:

  https://your-domain.example/geojson-proxy.php

uMap configuration:

  URL:    https://your-domain.example/geojson-proxy.php
  Format: GeoJSON

CLI usage:

  php geojson-proxy.php --help
  php geojson-proxy.php --version
  php geojson-proxy.php --warm-cache

Environment variables:

  SOURCE_URL    Remote source URL. Required unless configured in loadConfig().
  CACHE_DIR     Writable cache directory. Default: ./cache
  CACHE_TTL     Fresh cache lifetime. Default: 5m
  STALE_TTL     Stale cache lifetime after refresh errors. Default: 24h
  MAX_BYTES     Maximum response size in bytes. Default: 104857600
  HTTP_TIMEOUT  Upstream HTTP timeout in seconds. Default: 30
  USER_AGENT    User-Agent sent to upstream service.

Duration examples:

  30s
  5m
  1h
  24h
  1d

Notes:

  The source URL does not need a .geojson or .gz file extension.
  Gzip is detected by inspecting the response body for gzip magic bytes.
  This is a fixed-purpose proxy, not an open proxy.

TEXT;
}

function validateConfig(array $config): void
{
    if (
        trim($config['source_url']) === '' ||
        $config['source_url'] === 'https://example.com/export'
    ) {
        throw new RuntimeException('missing SOURCE_URL; set environment variable or edit loadConfig()');
    }

    if ($config['cache_ttl'] <= 0) {
        throw new RuntimeException('CACHE_TTL must be greater than zero');
    }

    if ($config['stale_ttl'] < 0) {
        throw new RuntimeException('STALE_TTL must not be negative');
    }

    if ($config['max_bytes'] <= 0) {
        throw new RuntimeException('MAX_BYTES must be greater than zero');
    }
}

function fetchAndPrepare(array $config): string
{
    $body = fetchUrl($config);

    if (isGzip($body)) {
        $decoded = gzdecode($body);

        if ($decoded === false) {
            throw new RuntimeException('could not decompress gzip payload');
        }

        $body = $decoded;
    }

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException('decoded response too large');
    }

    validateJson($body);

    return $body;
}

function fetchUrl(array $config): string
{
    if (function_exists('curl_init')) {
        return fetchUrlCurl($config);
    }

    return fetchUrlFallback($config);
}

function fetchUrlCurl(array $config): string
{
    $ch = curl_init($config['source_url']);

    if ($ch === false) {
        throw new RuntimeException('could not initialize curl');
    }

    $body = '';
    $tooLarge = false;

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $config['http_timeout'],
        CURLOPT_CONNECTTIMEOUT => min(10, $config['http_timeout']),
        CURLOPT_USERAGENT => $config['user_agent'],
        CURLOPT_HTTPHEADER => [
            'Accept: application/geo+json, application/json, */*',
        ],

        // Enables transparent decoding of HTTP Content-Encoding gzip/deflate/br.
        // A real application/gzip payload still remains gzip and is handled later.
        CURLOPT_ENCODING => '',

        CURLOPT_FAILONERROR => false,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,

        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooLarge, $config): int {
            if (strlen($body) + strlen($chunk) > $config['max_bytes']) {
                $tooLarge = true;
                return 0;
            }

            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    if ($tooLarge) {
        throw new RuntimeException('source response too large');
    }

    if ($ok === false) {
        throw new RuntimeException('curl error: ' . $error);
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('source returned HTTP ' . $status);
    }

    return $body;
}

function fetchUrlFallback(array $config): string
{
    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('neither curl nor allow_url_fopen is available');
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $config['http_timeout'],
            'header' =>
                "User-Agent: {$config['user_agent']}\r\n" .
                "Accept: application/geo+json, application/json, */*\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents(
        $config['source_url'],
        false,
        $context,
        0,
        $config['max_bytes'] + 1
    );

    if ($body === false) {
        throw new RuntimeException('could not fetch source URL');
    }

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException('source response too large');
    }

    $status = parseHttpStatus($http_response_header ?? []);
    if ($status !== null && ($status < 200 || $status >= 300)) {
        throw new RuntimeException('source returned HTTP ' . $status);
    }

    return $body;
}

function parseHttpStatus(array $headers): ?int
{
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            return (int) $matches[1];
        }
    }

    return null;
}

function validateJson(string $body): void
{
    if (function_exists('json_validate')) {
        if (!json_validate($body)) {
            throw new RuntimeException('decoded response is not valid JSON');
        }

        return;
    }

    json_decode($body);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('decoded response is not valid JSON: ' . json_last_error_msg());
    }
}

function isGzip(string $data): bool
{
    return strlen($data) >= 2 && ord($data[0]) === 0x1f && ord($data[1]) === 0x8b;
}

function ensureCacheDir(string $cacheDir): void
{
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('could not create cache directory: ' . $cacheDir);
        }
    }

    if (!is_writable($cacheDir)) {
        throw new RuntimeException('cache directory is not writable: ' . $cacheDir);
    }
}

function loadCache(array $config): ?array
{
    $metaPath = $config['cache_dir'] . '/meta.json';
    $dataPath = $config['cache_dir'] . '/data.geojson';

    if (!is_file($metaPath) || !is_file($dataPath)) {
        return null;
    }

    $metaRaw = file_get_contents($metaPath);
    $body = file_get_contents($dataPath);

    if ($metaRaw === false || $body === false) {
        return null;
    }

    $meta = json_decode($metaRaw, true);

    if (!is_array($meta)) {
        return null;
    }

    foreach (['etag', 'fetched_at', 'expires_at', 'stale_until'] as $key) {
        if (!array_key_exists($key, $meta)) {
            return null;
        }
    }

    return [
        'body' => $body,
        'etag' => (string) $meta['etag'],
        'fetched_at' => (int) $meta['fetched_at'],
        'expires_at' => (int) $meta['expires_at'],
        'stale_until' => (int) $meta['stale_until'],
    ];
}

function saveCache(array $config, string $body): array
{
    $now = time();

    $entry = [
        'body' => $body,
        'etag' => makeETag($body),
        'fetched_at' => $now,
        'expires_at' => $now + $config['cache_ttl'],
        'stale_until' => $now + $config['cache_ttl'] + $config['stale_ttl'],
    ];

    $dataPath = $config['cache_dir'] . '/data.geojson';
    $metaPath = $config['cache_dir'] . '/meta.json';

    $tmpData = $dataPath . '.' . getmypid() . '.tmp';
    $tmpMeta = $metaPath . '.' . getmypid() . '.tmp';

    $meta = [
        'etag' => $entry['etag'],
        'fetched_at' => $entry['fetched_at'],
        'expires_at' => $entry['expires_at'],
        'stale_until' => $entry['stale_until'],
        'bytes' => strlen($body),
    ];

    if (file_put_contents($tmpData, $body, LOCK_EX) === false) {
        throw new RuntimeException('could not write cache data');
    }

    if (file_put_contents($tmpMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        @unlink($tmpData);
        throw new RuntimeException('could not write cache metadata');
    }

    rename($tmpData, $dataPath);
    rename($tmpMeta, $metaPath);

    return $entry;
}

function serveCache(array $entry, string $cacheStatus, string $method, array $config): void
{
    setCORSHeaders();

    header('Content-Type: application/geo+json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $config['cache_ttl']);
    header('ETag: ' . $entry['etag']);
    header('X-Cache: ' . $cacheStatus);
    header('X-Cache-Fetched-At: ' . gmdate(DATE_ATOM, $entry['fetched_at']));

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $entry['etag']) {
        http_response_code(304);
        return;
    }

    http_response_code(200);

    if ($method === 'HEAD') {
        return;
    }

    echo $entry['body'];
}

function setCORSHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
}

function sendError(int $status, string $message, ?string $details = null): void
{
    setCORSHeaders();

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'error' => $message,
    ];

    if ($details !== null) {
        $payload['details'] = $details;
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function makeETag(string $body): string
{
    return '"' . hash('sha256', $body) . '"';
}

function envString(string $key, string $fallback): string
{
    $value = getenv($key);

    if ($value === false || trim($value) === '') {
        return $fallback;
    }

    return trim($value);
}

function parseDuration(string $value): int
{
    $value = trim($value);

    if (ctype_digit($value)) {
        return (int) $value;
    }

    if (!preg_match('/^(\d+)\s*([smhd])$/i', $value, $matches)) {
        throw new RuntimeException('invalid duration: ' . $value);
    }

    $number = (int) $matches[1];
    $unit = strtolower($matches[2]);

    return match ($unit) {
        's' => $number,
        'm' => $number * 60,
        'h' => $number * 3600,
        'd' => $number * 86400,
        default => throw new RuntimeException('invalid duration unit: ' . $unit),
    };
}
