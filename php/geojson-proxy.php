<?php

declare(strict_types=1);

/**
 * uMap GeoJSON spatial filter proxy
 *
 * Downloads a gzip-compressed GeoJSON source and a GeoJSON buffer dataset,
 * keeps only source features that intersect at least one buffer polygon,
 * caches the filtered result, and serves plain GeoJSON to uMap.
 *
 * No database or external GIS library is required.
 */

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Adjust all runtime options here.
|
*/

const VERSION = '1.2.0';

/**
 * Main source dataset containing all features.
 * The response may be gzip-compressed or plain GeoJSON.
 */
const SOURCE_URL = 'https://example.org/source.geojson.gz';

/**
 * Buffer dataset containing Polygon or MultiPolygon geometries.
 * The response may be gzip-compressed or plain GeoJSON.
 */
const BUFFER_URL = 'https://example.org/buffer.geojson.gz';

/** Writable cache directory. */
const CACHE_DIR = __DIR__ . '/cache';

/** Fresh result-cache lifetime. Examples: 30s, 5m, 1h, 1d. */
const CACHE_TTL = '15m';

/** Maximum time an expired cache may be served after refresh failures. */
const STALE_TTL = '24h';

/**
 * Maximum size of each downloaded or decompressed GeoJSON document and of
 * the filtered result. The source dataset is about 6 MiB decompressed, so
 * 32 MiB leaves comfortable headroom.
 */
const MAX_BYTES = 32 * 1024 * 1024;

/** HTTP timeout for each upstream request, in seconds. */
const HTTP_TIMEOUT = 60;

/** User-Agent sent to upstream servers. */
const USER_AGENT = 'umap-geojson-spatial-filter/' . VERSION;

/** Numerical tolerance used by the pure-PHP geometry tests. */
const GEO_EPSILON = 1.0e-12;

/** Write detailed processing information into CACHE_DIR/proxy.log. */
const DEBUG_LOG_ENABLED = false;

/** Log file name inside CACHE_DIR. */
const DEBUG_LOG_FILENAME = 'proxy.log';

/** Current processing status file name inside CACHE_DIR. */
const STATUS_FILENAME = 'status.json';

/** Allow GET requests with ?status=1 to return the current processing status. */
const STATUS_ENDPOINT_ENABLED = true;

/** Write filtering progress after this many source features. Set to 0 to disable. */
const LOG_PROGRESS_EVERY = 1000;

/** Approximate number of indexed buffer segments per grid cell. */
const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL = 12;

/** Upper bounds prevent pathological memory use on unusual geometries. */
const SPATIAL_INDEX_MAX_TOTAL_CELLS = 65536;
const SPATIAL_INDEX_MAX_GRID_DIMENSION = 256;
const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT = 1024;

/** Point-in-polygon Y-bucket index settings. */
const POINT_INDEX_TARGET_EDGES_PER_BUCKET = 24;
const POINT_INDEX_MAX_BUCKETS = 512;
const POINT_INDEX_MAX_BUCKETS_PER_EDGE = 128;

try {
    $config = loadConfig();

    if (PHP_SAPI === 'cli') {
        cliMain($argv, $config);
        exit;
    }

    webMain($config);
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    sendError(500, 'internal proxy error', $e->getMessage());
}

function loadConfig(): array
{
    return [
        'source_url' => SOURCE_URL,
        'buffer_url' => BUFFER_URL,
        'cache_dir' => CACHE_DIR,
        'cache_ttl' => parseDuration(CACHE_TTL),
        'stale_ttl' => parseDuration(STALE_TTL),
        'max_bytes' => MAX_BYTES,
        'http_timeout' => HTTP_TIMEOUT,
        'user_agent' => USER_AGENT,
        'debug_log_enabled' => DEBUG_LOG_ENABLED,
        'log_file' => CACHE_DIR . '/' . DEBUG_LOG_FILENAME,
        'status_file' => CACHE_DIR . '/' . STATUS_FILENAME,
        'status_endpoint_enabled' => STATUS_ENDPOINT_ENABLED,
        'log_progress_every' => LOG_PROGRESS_EVERY,
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

    if (
        $method === 'GET'
        && $config['status_endpoint_enabled']
        && isset($_GET['status'])
    ) {
        serveStatus($config);
        return;
    }

    initializeDiagnostics($config, 'web');
    setProxyStage('request-start', [
        'method' => $method,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    ]);

    $entry = loadCache($config);

    if ($entry !== null && time() < $entry['expires_at']) {
        setProxyStage('serving-fresh-cache', [
            'cache_age_seconds' => max(0, time() - $entry['fetched_at']),
            'response_bytes' => strlen($entry['body']),
        ]);
        serveCache($entry, 'HIT', $method);
        return;
    }

    proxyLog('INFO', 'no fresh cache available', [
        'cache_present' => $entry !== null,
    ]);

    $lockPath = $config['cache_dir'] . '/refresh.lock';
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        setProxyStage('lock-open-failed', ['lock_path' => $lockPath]);
        sendError(500, 'could not open cache lock file');
        return;
    }

    $lockWaitStarted = microtime(true);
    setProxyStage('waiting-for-refresh-lock', ['lock_path' => $lockPath]);

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('could not acquire cache lock');
        }

        setProxyStage('refresh-lock-acquired', [
            'wait_seconds' => round(microtime(true) - $lockWaitStarted, 3),
        ]);

        // Another request may have refreshed the cache while this request waited.
        $entry = loadCache($config);

        if ($entry !== null && time() < $entry['expires_at']) {
            setProxyStage('serving-cache-refreshed-by-other-request', [
                'cache_age_seconds' => max(0, time() - $entry['fetched_at']),
                'response_bytes' => strlen($entry['body']),
            ]);
            serveCache($entry, 'HIT', $method);
            return;
        }

        try {
            setProxyStage('refresh-started');
            $body = fetchAndPrepare($config);

            setProxyStage('writing-cache', [
                'result_bytes' => strlen($body),
            ]);
            $entry = saveCache($config, $body);

            setProxyStage('refresh-complete', [
                'response_bytes' => strlen($entry['body']),
                'etag' => $entry['etag'],
            ]);
            serveCache($entry, 'MISS', $method);
            return;
        } catch (Throwable $e) {
            logProxyException('GeoJSON proxy refresh failed', $e);
            setProxyStage('refresh-failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $entry = loadCache($config);

            if ($entry !== null && time() < $entry['stale_until']) {
                header('Warning: 110 - "Response is stale because source refresh failed"');
                proxyLog('WARNING', 'serving stale cache after refresh failure');
                serveCache($entry, 'STALE', $method);
                return;
            }

            sendError(
                502,
                'could not fetch and filter valid GeoJSON',
                $e->getMessage()
            );
            return;
        }
    } finally {
        proxyLog('INFO', 'releasing refresh lock');
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
        initializeDiagnostics($config, 'cli');
        setProxyStage('cli-warm-cache-start');

        $lockPath = $config['cache_dir'] . '/refresh.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('could not open cache lock file');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('could not acquire cache lock');
            }

            setProxyStage('cli-refresh-lock-acquired');
            $body = fetchAndPrepare($config);
            setProxyStage('writing-cache', ['result_bytes' => strlen($body)]);
            $entry = saveCache($config, $body);
            setProxyStage('cli-warm-cache-complete', [
                'result_bytes' => strlen($entry['body']),
                'etag' => $entry['etag'],
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        echo "cache refreshed\n";
        echo 'etag: ' . $entry['etag'] . "\n";
        echo 'bytes: ' . strlen($entry['body']) . "\n";
        echo 'expires_at: ' . gmdate(DATE_ATOM, $entry['expires_at']) . "\n";
        echo 'stale_until: ' . gmdate(DATE_ATOM, $entry['stale_until']) . "\n";
        echo 'peak_memory_mib: ' . number_format(
            memory_get_peak_usage(true) / 1024 / 1024,
            2,
            '.',
            ''
        ) . "\n";
        return;
    }

    echo helpText();
}

function helpText(): string
{
    return <<<TEXT
uMap GeoJSON spatial filter proxy - PHP variant

Downloads a source GeoJSON and a buffer GeoJSON, decompresses gzip payloads,
keeps only source features intersecting a Polygon or MultiPolygon buffer,
caches the filtered result, and serves plain GeoJSON.

Configuration:

  Edit the constants at the beginning of this PHP file:

  SOURCE_URL     Source FeatureCollection containing all objects.
  BUFFER_URL     GeoJSON containing Polygon or MultiPolygon buffers.
  CACHE_DIR      Writable cache directory.
  CACHE_TTL      Fresh result-cache lifetime.
  STALE_TTL      Stale-cache lifetime after refresh errors.
  MAX_BYTES      Maximum compressed, decompressed, or result size.
  HTTP_TIMEOUT   Upstream HTTP timeout in seconds.
  DEBUG_LOG_ENABLED
                 Write detailed JSON-lines logs to CACHE_DIR/proxy.log.
  LOG_PROGRESS_EVERY
                 Write filtering progress after this many source features.

Web usage:

  https://your-domain.example/geojson-spatial-filter-proxy.php

Status endpoint:

  https://your-domain.example/geojson-spatial-filter-proxy.php?status=1

  The normal endpoint remains GeoJSON-only. The status endpoint reports the
  current processing stage without interrupting the running refresh.

uMap configuration:

  URL:    https://your-domain.example/geojson-spatial-filter-proxy.php
  Format: GeoJSON

CLI usage:

  php geojson-spatial-filter-proxy.php --help
  php geojson-spatial-filter-proxy.php --version
  php geojson-spatial-filter-proxy.php --warm-cache

Duration examples:

  30s
  5m
  1h
  24h
  1d

Notes:

  Both remote responses may be gzip-compressed or plain GeoJSON.
  Gzip is detected from the response body's gzip magic bytes.
  The source GeoJSON must be a FeatureCollection.
  Buffer data may be a Geometry, Feature, FeatureCollection, or
  GeometryCollection containing Polygon or MultiPolygon geometries.
  Both datasets must use the same coordinate reference system.
  This is a fixed-purpose proxy, not an open proxy.

TEXT;
}

function validateConfig(array $config): void
{
    foreach ([
        'source_url' => 'SOURCE_URL',
        'buffer_url' => 'BUFFER_URL',
    ] as $key => $name) {
        $url = trim((string) ($config[$key] ?? ''));

        if ($url === '') {
            throw new RuntimeException($name . ' is empty');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException($name . ' is not a valid URL');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException($name . ' must use HTTP or HTTPS');
        }
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

    if ($config['http_timeout'] <= 0) {
        throw new RuntimeException('HTTP_TIMEOUT must be greater than zero');
    }
}

/**
 * Downloads both GeoJSON documents, filters the source, and returns the final
 * uncompressed GeoJSON string that will be stored in the cache.
 */
function fetchAndPrepare(array $config): string
{
    $startedAt = microtime(true);

    setProxyStage('downloading-source');
    $sourceDocument = fetchGeoJsonDocument(
        $config,
        $config['source_url'],
        'source GeoJSON'
    );

    setProxyStage('source-decoded', [
        'geojson_type' => $sourceDocument['type'] ?? null,
        'feature_count' => is_array($sourceDocument['features'] ?? null)
            ? count($sourceDocument['features'])
            : null,
    ]);

    setProxyStage('downloading-buffer');
    $bufferDocument = fetchGeoJsonDocument(
        $config,
        $config['buffer_url'],
        'buffer GeoJSON'
    );

    setProxyStage('buffer-decoded', [
        'geojson_type' => $bufferDocument['type'] ?? null,
    ]);

    setProxyStage('spatial-filter-start');
    [$filteredDocument, $statistics] = filterGeoJsonByBuffers(
        $sourceDocument,
        $bufferDocument
    );

    setProxyStage('spatial-filter-complete', $statistics);
    setProxyStage('encoding-result', $statistics);

    try {
        $body = json_encode(
            $filteredDocument,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $e) {
        throw new RuntimeException(
            'could not encode filtered GeoJSON: ' . $e->getMessage(),
            0,
            $e
        );
    }

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException('filtered result exceeds MAX_BYTES');
    }

    $summary = [
        'retained_features' => $statistics['retained_features'],
        'source_features' => $statistics['source_features'],
        'buffer_polygons' => $statistics['buffer_polygons'],
        'result_bytes' => strlen($body),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ];

    proxyLog('INFO', 'GeoJSON filter prepared successfully', $summary);
    setProxyStage('ready-to-cache', $summary);

    return $body;
}

/**
 * Downloads, optionally decompresses, and decodes one GeoJSON document.
 */
function fetchGeoJsonDocument(array $config, string $url, string $label): array
{
    proxyLog('INFO', 'starting GeoJSON download', [
        'label' => $label,
        'url' => $url,
    ]);

    $downloaded = fetchUrl($config, $url);
    $downloadedBytes = strlen($downloaded);
    $gzip = isGzip($downloaded);

    proxyLog('INFO', 'GeoJSON download completed', [
        'label' => $label,
        'downloaded_bytes' => $downloadedBytes,
        'gzip_payload' => $gzip,
    ]);

    if (strlen($downloaded) > $config['max_bytes']) {
        throw new RuntimeException($label . ' response exceeds MAX_BYTES');
    }

    if ($gzip) {
        proxyLog('INFO', 'decompressing gzip payload', [
            'label' => $label,
            'compressed_bytes' => $downloadedBytes,
        ]);
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('PHP zlib support is required for gzip data');
        }

        $body = gzdecode($downloaded);

        if ($body === false) {
            throw new RuntimeException('could not decompress ' . $label . ' gzip payload');
        }

        proxyLog('INFO', 'gzip payload decompressed', [
            'label' => $label,
            'compressed_bytes' => $downloadedBytes,
            'decompressed_bytes' => strlen($body),
        ]);
    } else {
        // cURL may already have decoded HTTP Content-Encoding gzip.
        $body = $downloaded;
    }

    unset($downloaded);

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException($label . ' decompressed response exceeds MAX_BYTES');
    }

    proxyLog('INFO', 'decoding GeoJSON JSON', [
        'label' => $label,
        'json_bytes' => strlen($body),
    ]);

    try {
        $document = json_decode(
            $body,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        throw new RuntimeException(
            $label . ' does not contain valid JSON: ' . $e->getMessage(),
            0,
            $e
        );
    }

    unset($body);

    if (!is_array($document)) {
        throw new RuntimeException($label . ' does not contain a GeoJSON object');
    }

    if (!is_string($document['type'] ?? null)) {
        throw new RuntimeException($label . ' has no valid GeoJSON type');
    }

    proxyLog('INFO', 'GeoJSON JSON decoded', [
        'label' => $label,
        'geojson_type' => $document['type'],
        'feature_count' => is_array($document['features'] ?? null)
            ? count($document['features'])
            : null,
    ]);

    return $document;
}

function fetchUrl(array $config, string $url): string
{
    if (function_exists('curl_init')) {
        return fetchUrlCurl($config, $url);
    }

    return fetchUrlFallback($config, $url);
}

function fetchUrlCurl(array $config, string $url): string
{
    proxyLog('INFO', 'HTTP request started with cURL', ['url' => $url]);
    $ch = curl_init($url);

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
            'Accept: application/geo+json, application/json, application/gzip, */*',
        ],

        // Transparently decodes HTTP Content-Encoding gzip/deflate/br.
        // A downloaded .gz payload normally remains gzip and is decoded later.
        CURLOPT_ENCODING => '',

        CURLOPT_FAILONERROR => false,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => false,

        CURLOPT_WRITEFUNCTION => static function (
            $ch,
            string $chunk
        ) use (&$body, &$tooLarge, $config): int {
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
    $info = curl_getinfo($ch);
    $status = (int) ($info['http_code'] ?? 0);

    curl_close($ch);

    proxyLog('INFO', 'HTTP request completed with cURL', [
        'url' => $url,
        'effective_url' => $info['url'] ?? null,
        'status' => $status,
        'response_bytes' => strlen($body),
        'content_type' => $info['content_type'] ?? null,
        'redirect_count' => $info['redirect_count'] ?? null,
        'total_seconds' => isset($info['total_time']) ? round((float) $info['total_time'], 3) : null,
        'curl_ok' => $ok !== false,
        'curl_error' => $error !== '' ? $error : null,
    ]);

    if ($tooLarge) {
        throw new RuntimeException('remote response exceeds MAX_BYTES');
    }

    if ($ok === false) {
        throw new RuntimeException('curl error: ' . $error);
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('remote source returned HTTP ' . $status);
    }

    return $body;
}

function fetchUrlFallback(array $config, string $url): string
{
    proxyLog('INFO', 'HTTP request started with file_get_contents', ['url' => $url]);

    if (!ini_get('allow_url_fopen')) {
        throw new RuntimeException('neither curl nor allow_url_fopen is available');
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $config['http_timeout'],
            'follow_location' => 1,
            'max_redirects' => 5,
            'header' =>
                "User-Agent: {$config['user_agent']}\r\n"
                . "Accept: application/geo+json, application/json, application/gzip, */*\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents(
        $url,
        false,
        $context,
        0,
        $config['max_bytes'] + 1
    );

    if ($body === false) {
        throw new RuntimeException('could not fetch remote URL');
    }

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException('remote response exceeds MAX_BYTES');
    }

    $status = parseHttpStatus($http_response_header ?? []);

    proxyLog('INFO', 'HTTP request completed with file_get_contents', [
        'url' => $url,
        'status' => $status,
        'response_bytes' => strlen($body),
    ]);

    if ($status !== null && ($status < 200 || $status >= 300)) {
        throw new RuntimeException('remote source returned HTTP ' . $status);
    }

    return $body;
}

/** Returns the last HTTP status, which is the final response after redirects. */
function parseHttpStatus(array $headers): ?int
{
    $status = null;

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return $status;
}

function isGzip(string $data): bool
{
    return strlen($data) >= 2
        && ord($data[0]) === 0x1f
        && ord($data[1]) === 0x8b;
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

    foreach ([
        'etag',
        'cache_key',
        'fetched_at',
        'expires_at',
        'stale_until',
    ] as $key) {
        if (!array_key_exists($key, $meta)) {
            return null;
        }
    }

    if (!hash_equals(makeCacheKey($config), (string) $meta['cache_key'])) {
        return null;
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
    proxyLog('INFO', 'cache write started', [
        'data_path' => $config['cache_dir'] . '/data.geojson',
        'meta_path' => $config['cache_dir'] . '/meta.json',
        'body_bytes' => strlen($body),
    ]);

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

    $suffix = '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $tmpData = $dataPath . $suffix;
    $tmpMeta = $metaPath . $suffix;

    $meta = [
        'version' => VERSION,
        'cache_key' => makeCacheKey($config),
        'etag' => $entry['etag'],
        'fetched_at' => $entry['fetched_at'],
        'expires_at' => $entry['expires_at'],
        'stale_until' => $entry['stale_until'],
        'bytes' => strlen($body),
    ];

    $metaJson = json_encode(
        $meta,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if (file_put_contents($tmpData, $body, LOCK_EX) === false) {
        throw new RuntimeException('could not write cache data');
    }

    if (file_put_contents($tmpMeta, $metaJson, LOCK_EX) === false) {
        @unlink($tmpData);
        throw new RuntimeException('could not write cache metadata');
    }

    if (!rename($tmpData, $dataPath)) {
        @unlink($tmpData);
        @unlink($tmpMeta);
        throw new RuntimeException('could not activate cache data');
    }

    if (!rename($tmpMeta, $metaPath)) {
        @unlink($tmpMeta);
        throw new RuntimeException('could not activate cache metadata');
    }

    proxyLog('INFO', 'cache write completed', [
        'data_path' => $dataPath,
        'meta_path' => $metaPath,
        'body_bytes' => strlen($body),
        'etag' => $entry['etag'],
    ]);

    return $entry;
}

function makeCacheKey(array $config): string
{
    return hash(
        'sha256',
        VERSION . "\n" . $config['source_url'] . "\n" . $config['buffer_url']
    );
}

function serveCache(array $entry, string $cacheStatus, string $method): void
{
    proxyLog('INFO', 'serving response', [
        'cache_status' => $cacheStatus,
        'method' => $method,
        'response_bytes' => strlen($entry['body']),
        'etag' => $entry['etag'],
    ]);

    setCORSHeaders();

    $remainingTtl = max(0, $entry['expires_at'] - time());

    header('Content-Type: application/geo+json; charset=utf-8');
    header('ETag: ' . $entry['etag']);
    header('X-Cache: ' . $cacheStatus);
    header('X-Cache-Fetched-At: ' . gmdate(DATE_ATOM, $entry['fetched_at']));
    header('Age: ' . max(0, time() - $entry['fetched_at']));

    if ($cacheStatus === 'STALE') {
        header('Cache-Control: public, max-age=0, must-revalidate');
    } else {
        header('Cache-Control: public, max-age=' . $remainingTtl);
    }

    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

    if (etagHeaderMatches($ifNoneMatch, $entry['etag'])) {
        http_response_code(304);
        return;
    }

    http_response_code(200);
    header('Content-Length: ' . strlen($entry['body']));

    if ($method === 'HEAD') {
        return;
    }

    echo $entry['body'];
}

function etagHeaderMatches(string $header, string $etag): bool
{
    foreach (explode(',', $header) as $candidate) {
        $candidate = trim($candidate);

        if ($candidate === '*' || $candidate === $etag) {
            return true;
        }
    }

    return false;
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
    header('Cache-Control: no-store');

    $payload = ['error' => $message];

    if ($details !== null) {
        $payload['details'] = $details;
    }

    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

function makeETag(string $body): string
{
    return '"' . hash('sha256', $body) . '"';
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

/*
|--------------------------------------------------------------------------
| Diagnostics and progress reporting
|--------------------------------------------------------------------------
*/

function initializeDiagnostics(array $config, string $mode): void
{
    $requestId = sprintf(
        '%s-%d-%s',
        gmdate('YmdHis'),
        getmypid(),
        substr(hash('sha256', uniqid('', true)), 0, 8)
    );

    $GLOBALS['proxy_diagnostics'] = [
        'enabled' => (bool) $config['debug_log_enabled'],
        'log_file' => $config['log_file'],
        'status_file' => $config['status_file'],
        'request_id' => $requestId,
        'started_at' => microtime(true),
        'mode' => $mode,
        'progress_every' => (int) $config['log_progress_every'],
    ];

    if (!$config['debug_log_enabled']) {
        return;
    }

    @ini_set('log_errors', '1');
    @ini_set('error_log', $config['log_file']);

    // Keep a small reserve so a fatal memory error can still be logged.
    $GLOBALS['proxy_fatal_memory_reserve'] = str_repeat('R', 128 * 1024);

    register_shutdown_function(static function (): void {
        unset($GLOBALS['proxy_fatal_memory_reserve']);

        $error = error_get_last();
        if ($error === null) {
            return;
        }

        if (!in_array($error['type'], [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ], true)) {
            return;
        }

        proxyLog('FATAL', 'PHP terminated with a fatal error', [
            'error_type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        writeProxyStatus('fatal-error', [
            'error_type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    });

    proxyLog('INFO', 'diagnostics initialized', [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'curl_available' => function_exists('curl_init'),
        'gzdecode_available' => function_exists('gzdecode'),
        'cache_dir' => $config['cache_dir'],
    ]);
}

function proxyLog(string $level, string $message, array $context = []): void
{
    $diagnostics = $GLOBALS['proxy_diagnostics'] ?? null;

    if (!is_array($diagnostics) || !($diagnostics['enabled'] ?? false)) {
        return;
    }

    $record = array_merge([
        'timestamp' => gmdate(DATE_ATOM),
        'level' => strtoupper($level),
        'request_id' => $diagnostics['request_id'] ?? null,
        'mode' => $diagnostics['mode'] ?? null,
        'elapsed_seconds' => round(
            microtime(true) - (float) ($diagnostics['started_at'] ?? microtime(true)),
            3
        ),
        'memory_mib' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak_memory_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'message' => $message,
    ], $context);

    $json = json_encode(
        $record,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json !== false) {
        @file_put_contents(
            (string) $diagnostics['log_file'],
            $json . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

function setProxyStage(string $stage, array $context = []): void
{
    proxyLog('INFO', 'stage: ' . $stage, $context);
    writeProxyStatus($stage, $context);
}

function writeProxyStatus(string $stage, array $context = []): void
{
    $diagnostics = $GLOBALS['proxy_diagnostics'] ?? null;

    if (!is_array($diagnostics) || !($diagnostics['enabled'] ?? false)) {
        return;
    }

    $status = array_merge([
        'stage' => $stage,
        'updated_at' => gmdate(DATE_ATOM),
        'request_id' => $diagnostics['request_id'] ?? null,
        'mode' => $diagnostics['mode'] ?? null,
        'elapsed_seconds' => round(
            microtime(true) - (float) ($diagnostics['started_at'] ?? microtime(true)),
            3
        ),
        'memory_mib' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak_memory_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    ], $context);

    $json = json_encode(
        $status,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        return;
    }

    $statusFile = (string) $diagnostics['status_file'];
    $tmp = $statusFile . '.' . getmypid() . '.tmp';

    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        if (!@rename($tmp, $statusFile)) {
            @unlink($tmp);
        }
    }
}

function logProxyException(string $message, Throwable $exception): void
{
    proxyLog('ERROR', $message, [
        'exception' => get_class($exception),
        'exception_message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
    ]);
}

function serveStatus(array $config): void
{
    setCORSHeaders();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $status = null;
    if (is_file($config['status_file'])) {
        $raw = file_get_contents($config['status_file']);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $status = $decoded;
            }
        }
    }

    $meta = null;
    $metaPath = $config['cache_dir'] . '/meta.json';
    if (is_file($metaPath)) {
        $raw = file_get_contents($metaPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = [
                    'fetched_at' => isset($decoded['fetched_at'])
                        ? gmdate(DATE_ATOM, (int) $decoded['fetched_at'])
                        : null,
                    'expires_at' => isset($decoded['expires_at'])
                        ? gmdate(DATE_ATOM, (int) $decoded['expires_at'])
                        : null,
                    'stale_until' => isset($decoded['stale_until'])
                        ? gmdate(DATE_ATOM, (int) $decoded['stale_until'])
                        : null,
                    'bytes' => $decoded['bytes'] ?? null,
                    'etag' => $decoded['etag'] ?? null,
                ];
            }
        }
    }

    echo json_encode([
        'status_available' => $status !== null,
        'current' => $status,
        'cache' => [
            'data_exists' => is_file($config['cache_dir'] . '/data.geojson'),
            'meta_exists' => is_file($metaPath),
            'metadata' => $meta,
        ],
        'log_file' => DEBUG_LOG_FILENAME,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/*
|--------------------------------------------------------------------------
| Spatial filtering
|--------------------------------------------------------------------------
|
| These routines implement topological intersection tests in pure PHP.
| Buffer boundaries are indexed in a uniform grid. Point-in-polygon tests
| use a separate Y-bucket index. This avoids comparing every source segment
| with every buffer segment.
|
*/

/**
 * @return array{
 *     0: array,
 *     1: array{
 *         source_features: int,
 *         retained_features: int,
 *         buffer_polygons: int,
 *         buffer_segments: int
 *     }
 * }
 */
function filterGeoJsonByBuffers(array $source, array $bufferDocument): array
{
    if (($source['type'] ?? null) !== 'FeatureCollection') {
        throw new RuntimeException('source GeoJSON must be a FeatureCollection');
    }

    $sourceFeatures = $source['features'] ?? null;

    if (!is_array($sourceFeatures)) {
        throw new RuntimeException('source FeatureCollection has no valid features array');
    }

    setProxyStage('preparing-buffer-polygons');
    $buffers = prepareBufferPolygons($bufferDocument);

    if ($buffers === []) {
        throw new RuntimeException(
            'buffer GeoJSON contains no valid Polygon or MultiPolygon geometry'
        );
    }

    $bufferSegmentCount = array_sum(array_map(
        static fn(array $buffer): int => (int) $buffer['segment_count'],
        $buffers
    ));

    proxyLog('INFO', 'buffer polygons prepared with spatial indexes', [
        'buffer_polygons' => count($buffers),
        'buffer_segments' => $bufferSegmentCount,
        'boundary_grid_cells' => array_sum(array_map(
            static fn(array $buffer): int => count($buffer['boundary_index']['cells']),
            $buffers
        )),
    ]);

    $retained = [];
    $totalFeatures = count($sourceFeatures);
    $progressEvery = max(0, (int) ($GLOBALS['proxy_diagnostics']['progress_every'] ?? 0));

    setProxyStage('filtering', [
        'processed_features' => 0,
        'total_features' => $totalFeatures,
        'retained_features' => 0,
        'buffer_polygons' => count($buffers),
        'buffer_segments' => $bufferSegmentCount,
    ]);

    foreach ($sourceFeatures as $featureIndex => $feature) {
        if (!is_array($feature)) {
            continue;
        }

        $geometry = $feature['geometry'] ?? null;

        if (!is_array($geometry)) {
            continue;
        }

        if (geometryIntersectsAnyBuffer($geometry, $buffers)) {
            $retained[] = $feature;
        }

        $processed = $featureIndex + 1;
        if (
            $progressEvery > 0
            && ($processed % $progressEvery === 0 || $processed === $totalFeatures)
        ) {
            $progress = [
                'processed_features' => $processed,
                'total_features' => $totalFeatures,
                'retained_features' => count($retained),
                'buffer_polygons' => count($buffers),
                'buffer_segments' => $bufferSegmentCount,
                'percent' => $totalFeatures > 0
                    ? round($processed * 100 / $totalFeatures, 1)
                    : 100.0,
            ];
            proxyLog('INFO', 'spatial filter progress', $progress);
            writeProxyStatus('filtering', $progress);
        }
    }

    $result = $source;
    $result['features'] = $retained;

    // A source-level bbox may no longer describe the filtered result.
    unset($result['bbox']);

    return [
        $result,
        [
            'source_features' => count($sourceFeatures),
            'retained_features' => count($retained),
            'buffer_polygons' => count($buffers),
            'buffer_segments' => $bufferSegmentCount,
        ],
    ];
}

/**
 * @return array<int, array{
 *     coordinates: array,
 *     bbox: array{minX: float, minY: float, maxX: float, maxY: float},
 *     first_point: array|null,
 *     rings: array,
 *     boundary_index: array,
 *     segment_count: int
 * }>
 */
function prepareBufferPolygons(array $document): array
{
    $polygonCoordinates = extractPolygonCoordinates($document);
    $prepared = [];

    foreach ($polygonCoordinates as $polygon) {
        if (!is_array($polygon) || $polygon === []) {
            continue;
        }

        $bbox = coordinatesBoundingBox($polygon);

        if ($bbox === null) {
            continue;
        }

        $segments = polygonSegmentRecords($polygon);

        if ($segments === []) {
            continue;
        }

        $preparedRings = [];
        foreach ($polygon as $ring) {
            if (is_array($ring)) {
                $preparedRing = prepareRingForPointTests($ring);
                if ($preparedRing !== null) {
                    $preparedRings[] = $preparedRing;
                }
            }
        }

        if ($preparedRings === []) {
            continue;
        }

        $prepared[] = [
            'coordinates' => $polygon,
            'bbox' => $bbox,
            'first_point' => firstPolygonPoint($polygon),
            'rings' => $preparedRings,
            'boundary_index' => buildSegmentGridIndex($segments, $bbox),
            'segment_count' => count($segments),
        ];
    }

    return $prepared;
}

/** @return array<int, array> */
function extractPolygonCoordinates(array $object): array
{
    $type = $object['type'] ?? null;
    $result = [];

    switch ($type) {
        case 'FeatureCollection':
            foreach ($object['features'] ?? [] as $feature) {
                if (is_array($feature)) {
                    foreach (extractPolygonCoordinates($feature) as $polygon) {
                        $result[] = $polygon;
                    }
                }
            }
            break;

        case 'Feature':
            if (is_array($object['geometry'] ?? null)) {
                $result = extractPolygonCoordinates($object['geometry']);
            }
            break;

        case 'GeometryCollection':
            foreach ($object['geometries'] ?? [] as $geometry) {
                if (is_array($geometry)) {
                    foreach (extractPolygonCoordinates($geometry) as $polygon) {
                        $result[] = $polygon;
                    }
                }
            }
            break;

        case 'Polygon':
            if (is_array($object['coordinates'] ?? null)) {
                $result[] = $object['coordinates'];
            }
            break;

        case 'MultiPolygon':
            foreach ($object['coordinates'] ?? [] as $polygon) {
                if (is_array($polygon)) {
                    $result[] = $polygon;
                }
            }
            break;
    }

    return $result;
}

/**
 * @param array<int, array> $buffers
 */
function geometryIntersectsAnyBuffer(array $geometry, array $buffers): bool
{
    foreach (flattenGeometry($geometry) as $part) {
        $partBbox = geometryBoundingBox($part);

        if ($partBbox === null) {
            continue;
        }

        foreach ($buffers as $buffer) {
            if (!boundingBoxesIntersect($partBbox, $buffer['bbox'])) {
                continue;
            }

            if (simpleGeometryIntersectsPreparedPolygon($part, $buffer)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Converts Multi* and GeometryCollection geometries into Point, LineString,
 * and Polygon parts.
 *
 * @return array<int, array>
 */
function flattenGeometry(array $geometry): array
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;
    $result = [];

    switch ($type) {
        case 'Point':
        case 'LineString':
        case 'Polygon':
            if (is_array($coordinates)) {
                $result[] = $geometry;
            }
            break;

        case 'MultiPoint':
            foreach ($coordinates ?? [] as $point) {
                if (is_array($point)) {
                    $result[] = ['type' => 'Point', 'coordinates' => $point];
                }
            }
            break;

        case 'MultiLineString':
            foreach ($coordinates ?? [] as $line) {
                if (is_array($line)) {
                    $result[] = ['type' => 'LineString', 'coordinates' => $line];
                }
            }
            break;

        case 'MultiPolygon':
            foreach ($coordinates ?? [] as $polygon) {
                if (is_array($polygon)) {
                    $result[] = ['type' => 'Polygon', 'coordinates' => $polygon];
                }
            }
            break;

        case 'GeometryCollection':
            foreach ($geometry['geometries'] ?? [] as $child) {
                if (is_array($child)) {
                    foreach (flattenGeometry($child) as $part) {
                        $result[] = $part;
                    }
                }
            }
            break;
    }

    return $result;
}

function simpleGeometryIntersectsPreparedPolygon(array $geometry, array $buffer): bool
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;

    if (!is_array($coordinates)) {
        return false;
    }

    return match ($type) {
        'Point' => pointInPreparedPolygon($coordinates, $buffer),
        'LineString' => lineStringIntersectsPreparedPolygon($coordinates, $buffer),
        'Polygon' => polygonIntersectsPreparedPolygon($coordinates, $buffer),
        default => false,
    };
}

function geometryBoundingBox(array $geometry): ?array
{
    $coordinates = $geometry['coordinates'] ?? null;

    if (!is_array($coordinates)) {
        return null;
    }

    return coordinatesBoundingBox($coordinates);
}

/**
 * @return array{minX: float, minY: float, maxX: float, maxY: float}|null
 */
function coordinatesBoundingBox(array $coordinates): ?array
{
    $bbox = null;
    expandBoundingBox($coordinates, $bbox);
    return $bbox;
}

function expandBoundingBox(array $coordinates, ?array &$bbox): void
{
    if (
        count($coordinates) >= 2
        && is_numeric($coordinates[0])
        && is_numeric($coordinates[1])
    ) {
        $x = (float) $coordinates[0];
        $y = (float) $coordinates[1];

        if (!is_finite($x) || !is_finite($y)) {
            return;
        }

        if ($bbox === null) {
            $bbox = ['minX' => $x, 'minY' => $y, 'maxX' => $x, 'maxY' => $y];
            return;
        }

        $bbox['minX'] = min($bbox['minX'], $x);
        $bbox['minY'] = min($bbox['minY'], $y);
        $bbox['maxX'] = max($bbox['maxX'], $x);
        $bbox['maxY'] = max($bbox['maxY'], $y);
        return;
    }

    foreach ($coordinates as $child) {
        if (is_array($child)) {
            expandBoundingBox($child, $bbox);
        }
    }
}

function boundingBoxesIntersect(array $a, array $b): bool
{
    return !(
        $a['maxX'] < $b['minX']
        || $a['minX'] > $b['maxX']
        || $a['maxY'] < $b['minY']
        || $a['minY'] > $b['maxY']
    );
}

function pointInPreparedPolygon(array $point, array $polygon): bool
{
    if (!isCoordinate($point) || !pointInBoundingBox($point, $polygon['bbox'])) {
        return false;
    }

    $rings = $polygon['rings'];

    if (!isset($rings[0]) || !pointInPreparedRing($point, $rings[0], true)) {
        return false;
    }

    for ($i = 1, $count = count($rings); $i < $count; $i++) {
        // A boundary of a hole is still part of the polygon boundary and must
        // count as an intersection. Therefore boundaryCountsAsInside=false.
        if (pointInPreparedRing($point, $rings[$i], false)) {
            return false;
        }
    }

    return true;
}

function pointInBoundingBox(array $point, array $bbox): bool
{
    return (float) $point[0] >= $bbox['minX'] - GEO_EPSILON
        && (float) $point[0] <= $bbox['maxX'] + GEO_EPSILON
        && (float) $point[1] >= $bbox['minY'] - GEO_EPSILON
        && (float) $point[1] <= $bbox['maxY'] + GEO_EPSILON;
}

/**
 * Prepare a ring for fast repeated point-in-ring tests. Edges are assigned to
 * horizontal buckets according to their Y extent.
 */
function prepareRingForPointTests(array $ring): ?array
{
    $records = ringSegmentRecords($ring);
    $bbox = coordinatesBoundingBox($ring);

    if ($records === [] || $bbox === null) {
        return null;
    }

    $bucketCount = max(
        1,
        min(
            POINT_INDEX_MAX_BUCKETS,
            (int) ceil(count($records) / POINT_INDEX_TARGET_EDGES_PER_BUCKET)
        )
    );

    if (($bbox['maxY'] - $bbox['minY']) <= GEO_EPSILON) {
        $bucketCount = 1;
    }

    $buckets = [];
    $overflow = [];

    foreach ($records as $id => $record) {
        $minBucket = pointIndexBucketForY($record['minY'], $bbox, $bucketCount);
        $maxBucket = pointIndexBucketForY($record['maxY'], $bbox, $bucketCount);
        $span = $maxBucket - $minBucket + 1;

        if ($span > POINT_INDEX_MAX_BUCKETS_PER_EDGE) {
            $overflow[] = $id;
            continue;
        }

        for ($bucket = $minBucket; $bucket <= $maxBucket; $bucket++) {
            $buckets[$bucket][] = $id;
        }
    }

    return [
        'bbox' => $bbox,
        'segments' => $records,
        'bucket_count' => $bucketCount,
        'buckets' => $buckets,
        'overflow' => $overflow,
    ];
}

function pointIndexBucketForY(float $y, array $bbox, int $bucketCount): int
{
    if ($bucketCount <= 1 || ($bbox['maxY'] - $bbox['minY']) <= GEO_EPSILON) {
        return 0;
    }

    $ratio = ($y - $bbox['minY']) / ($bbox['maxY'] - $bbox['minY']);
    $bucket = (int) floor($ratio * $bucketCount);

    return max(0, min($bucketCount - 1, $bucket));
}

function pointInPreparedRing(array $point, array $ring, bool $boundaryCountsAsInside): bool
{
    if (!pointInBoundingBox($point, $ring['bbox'])) {
        return false;
    }

    $bucket = pointIndexBucketForY((float) $point[1], $ring['bbox'], $ring['bucket_count']);
    $candidateIds = $ring['buckets'][$bucket] ?? [];

    if ($ring['overflow'] !== []) {
        foreach ($ring['overflow'] as $id) {
            $candidateIds[] = $id;
        }
    }

    $inside = false;

    foreach ($candidateIds as $id) {
        $segment = $ring['segments'][$id];
        $a = $segment['a'];
        $b = $segment['b'];

        if (pointOnSegment($point, $a, $b)) {
            return $boundaryCountsAsInside;
        }

        $crosses = (
            ((float) $b[1] > (float) $point[1])
            !== ((float) $a[1] > (float) $point[1])
        );

        if (!$crosses) {
            continue;
        }

        $intersectionX =
            ((float) $a[0] - (float) $b[0])
            * ((float) $point[1] - (float) $b[1])
            / ((float) $a[1] - (float) $b[1])
            + (float) $b[0];

        if ((float) $point[0] < $intersectionX) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * Raw point-in-polygon is retained for the one containment test where the
 * prepared buffer's first point is tested against a source polygon.
 */
function pointInPolygon(array $point, array $polygon): bool
{
    if (!isCoordinate($point) || !isset($polygon[0]) || !is_array($polygon[0])) {
        return false;
    }

    if (!pointInRing($point, $polygon[0], true)) {
        return false;
    }

    for ($i = 1, $count = count($polygon); $i < $count; $i++) {
        if (is_array($polygon[$i]) && pointInRing($point, $polygon[$i], false)) {
            return false;
        }
    }

    return true;
}

function pointInRing(array $point, array $ring, bool $boundaryCountsAsInside): bool
{
    $count = count($ring);

    if ($count < 3) {
        return false;
    }

    $inside = false;

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $a = $ring[$j] ?? null;
        $b = $ring[$i] ?? null;

        if (!is_array($a) || !is_array($b) || !isCoordinate($a) || !isCoordinate($b)) {
            continue;
        }

        if (pointOnSegment($point, $a, $b)) {
            return $boundaryCountsAsInside;
        }

        $crosses = (
            ((float) $b[1] > (float) $point[1])
            !== ((float) $a[1] > (float) $point[1])
        );

        if (!$crosses) {
            continue;
        }

        $intersectionX =
            ((float) $a[0] - (float) $b[0])
            * ((float) $point[1] - (float) $b[1])
            / ((float) $a[1] - (float) $b[1])
            + (float) $b[0];

        if ((float) $point[0] < $intersectionX) {
            $inside = !$inside;
        }
    }

    return $inside;
}

function lineStringIntersectsPreparedPolygon(array $line, array $buffer): bool
{
    $firstPoint = firstLinePoint($line);

    if ($firstPoint === null) {
        return false;
    }

    // For a connected line it is sufficient to test one point for containment.
    // If a later part enters the polygon, it must cross a polygon boundary,
    // which is detected by the indexed segment test below.
    if (pointInPreparedPolygon($firstPoint, $buffer)) {
        return true;
    }

    return lineBoundaryIntersectsIndex($line, $buffer['boundary_index']);
}

function polygonIntersectsPreparedPolygon(array $sourcePolygon, array $buffer): bool
{
    if (polygonBoundaryIntersectsIndex($sourcePolygon, $buffer['boundary_index'])) {
        return true;
    }

    $sourcePoint = firstPolygonPoint($sourcePolygon);

    if ($sourcePoint !== null && pointInPreparedPolygon($sourcePoint, $buffer)) {
        return true;
    }

    $bufferPoint = $buffer['first_point'];

    return $bufferPoint !== null && pointInPolygon($bufferPoint, $sourcePolygon);
}

function firstLinePoint(array $line): ?array
{
    foreach ($line as $point) {
        if (is_array($point) && isCoordinate($point)) {
            return $point;
        }
    }

    return null;
}

function firstPolygonPoint(array $polygon): ?array
{
    foreach ($polygon as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        foreach ($ring as $point) {
            if (is_array($point) && isCoordinate($point)) {
                return $point;
            }
        }
    }

    return null;
}

function lineBoundaryIntersectsIndex(array $line, array $index): bool
{
    $count = count($line);

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $line[$i] ?? null;
        $b = $line[$i + 1] ?? null;

        if (!is_array($a) || !is_array($b) || !isCoordinate($a) || !isCoordinate($b)) {
            continue;
        }

        if (segmentIntersectsGridIndex($a, $b, $index)) {
            return true;
        }
    }

    return false;
}

function polygonBoundaryIntersectsIndex(array $polygon, array $index): bool
{
    foreach ($polygon as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        if (ringBoundaryIntersectsIndex($ring, $index)) {
            return true;
        }
    }

    return false;
}

function ringBoundaryIntersectsIndex(array $ring, array $index): bool
{
    $count = count($ring);

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $ring[$i] ?? null;
        $b = $ring[$i + 1] ?? null;

        if (
            is_array($a)
            && is_array($b)
            && isCoordinate($a)
            && isCoordinate($b)
            && segmentIntersectsGridIndex($a, $b, $index)
        ) {
            return true;
        }
    }

    if ($count >= 3) {
        $first = $ring[0] ?? null;
        $last = $ring[$count - 1] ?? null;

        if (
            is_array($first)
            && is_array($last)
            && isCoordinate($first)
            && isCoordinate($last)
            && !pointsEqual($first, $last)
            && segmentIntersectsGridIndex($last, $first, $index)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int, array{a: array, b: array, minX: float, minY: float, maxX: float, maxY: float}>
 */
function polygonSegmentRecords(array $polygon): array
{
    $records = [];

    foreach ($polygon as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        foreach (ringSegmentRecords($ring) as $record) {
            $records[] = $record;
        }
    }

    return $records;
}

/**
 * @return array<int, array{a: array, b: array, minX: float, minY: float, maxX: float, maxY: float}>
 */
function ringSegmentRecords(array $ring): array
{
    $records = [];
    $count = count($ring);

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $ring[$i] ?? null;
        $b = $ring[$i + 1] ?? null;

        if (is_array($a) && is_array($b) && isCoordinate($a) && isCoordinate($b)) {
            $records[] = makeSegmentRecord($a, $b);
        }
    }

    if ($count >= 3) {
        $first = $ring[0] ?? null;
        $last = $ring[$count - 1] ?? null;

        if (
            is_array($first)
            && is_array($last)
            && isCoordinate($first)
            && isCoordinate($last)
            && !pointsEqual($first, $last)
        ) {
            $records[] = makeSegmentRecord($last, $first);
        }
    }

    return $records;
}

function makeSegmentRecord(array $a, array $b): array
{
    return [
        'a' => $a,
        'b' => $b,
        'minX' => min((float) $a[0], (float) $b[0]),
        'minY' => min((float) $a[1], (float) $b[1]),
        'maxX' => max((float) $a[0], (float) $b[0]),
        'maxY' => max((float) $a[1], (float) $b[1]),
    ];
}

function buildSegmentGridIndex(array $segments, array $bbox): array
{
    $segmentCount = count($segments);
    $width = $bbox['maxX'] - $bbox['minX'];
    $height = $bbox['maxY'] - $bbox['minY'];

    $targetCells = max(
        1,
        min(
            SPATIAL_INDEX_MAX_TOTAL_CELLS,
            (int) ceil($segmentCount / SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL)
        )
    );

    if ($width <= GEO_EPSILON && $height <= GEO_EPSILON) {
        $columns = 1;
        $rows = 1;
    } elseif ($height <= GEO_EPSILON) {
        $columns = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, max(1, $targetCells));
        $rows = 1;
    } elseif ($width <= GEO_EPSILON) {
        $columns = 1;
        $rows = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, max(1, $targetCells));
    } else {
        $aspect = max(1.0 / 16.0, min(16.0, $width / $height));
        $columns = (int) max(1, round(sqrt($targetCells * $aspect)));
        $columns = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, $columns);
        $rows = (int) max(1, ceil($targetCells / $columns));
        $rows = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, $rows);
    }

    $cells = [];
    $overflow = [];

    foreach ($segments as $id => $segment) {
        [$minColumn, $maxColumn, $minRow, $maxRow] = gridRangeForBoundingBox(
            $segment,
            $bbox,
            $columns,
            $rows
        );

        $cellSpan = ($maxColumn - $minColumn + 1) * ($maxRow - $minRow + 1);

        if ($cellSpan > SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT) {
            $overflow[] = $id;
            continue;
        }

        for ($row = $minRow; $row <= $maxRow; $row++) {
            for ($column = $minColumn; $column <= $maxColumn; $column++) {
                $cells[$row * $columns + $column][] = $id;
            }
        }
    }

    return [
        'bbox' => $bbox,
        'columns' => $columns,
        'rows' => $rows,
        'cells' => $cells,
        'overflow' => $overflow,
        'segments' => $segments,
    ];
}

function gridRangeForBoundingBox(
    array $itemBbox,
    array $gridBbox,
    int $columns,
    int $rows
): array {
    return [
        gridColumnForX((float) $itemBbox['minX'], $gridBbox, $columns),
        gridColumnForX((float) $itemBbox['maxX'], $gridBbox, $columns),
        gridRowForY((float) $itemBbox['minY'], $gridBbox, $rows),
        gridRowForY((float) $itemBbox['maxY'], $gridBbox, $rows),
    ];
}

function gridColumnForX(float $x, array $bbox, int $columns): int
{
    if ($columns <= 1 || ($bbox['maxX'] - $bbox['minX']) <= GEO_EPSILON) {
        return 0;
    }

    $ratio = ($x - $bbox['minX']) / ($bbox['maxX'] - $bbox['minX']);
    $column = (int) floor($ratio * $columns);

    return max(0, min($columns - 1, $column));
}

function gridRowForY(float $y, array $bbox, int $rows): int
{
    if ($rows <= 1 || ($bbox['maxY'] - $bbox['minY']) <= GEO_EPSILON) {
        return 0;
    }

    $ratio = ($y - $bbox['minY']) / ($bbox['maxY'] - $bbox['minY']);
    $row = (int) floor($ratio * $rows);

    return max(0, min($rows - 1, $row));
}

function segmentIntersectsGridIndex(array $a, array $b, array $index): bool
{
    $segmentBbox = [
        'minX' => min((float) $a[0], (float) $b[0]),
        'minY' => min((float) $a[1], (float) $b[1]),
        'maxX' => max((float) $a[0], (float) $b[0]),
        'maxY' => max((float) $a[1], (float) $b[1]),
    ];

    if (!boundingBoxesIntersect($segmentBbox, $index['bbox'])) {
        return false;
    }

    [$minColumn, $maxColumn, $minRow, $maxRow] = gridRangeForBoundingBox(
        $segmentBbox,
        $index['bbox'],
        $index['columns'],
        $index['rows']
    );

    $seen = [];

    foreach ($index['overflow'] as $id) {
        $seen[$id] = true;
    }

    for ($row = $minRow; $row <= $maxRow; $row++) {
        for ($column = $minColumn; $column <= $maxColumn; $column++) {
            $key = $row * $index['columns'] + $column;

            foreach ($index['cells'][$key] ?? [] as $id) {
                $seen[$id] = true;
            }
        }
    }

    foreach ($seen as $id => $_) {
        $candidate = $index['segments'][$id];

        if (!boundingBoxesIntersect($segmentBbox, $candidate)) {
            continue;
        }

        if (segmentsIntersect($a, $b, $candidate['a'], $candidate['b'])) {
            return true;
        }
    }

    return false;
}

function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    if (
        max((float) $a[0], (float) $b[0]) + GEO_EPSILON
            < min((float) $c[0], (float) $d[0])
        || max((float) $c[0], (float) $d[0]) + GEO_EPSILON
            < min((float) $a[0], (float) $b[0])
        || max((float) $a[1], (float) $b[1]) + GEO_EPSILON
            < min((float) $c[1], (float) $d[1])
        || max((float) $c[1], (float) $d[1]) + GEO_EPSILON
            < min((float) $a[1], (float) $b[1])
    ) {
        return false;
    }

    $o1 = orientationValue($a, $b, $c);
    $o2 = orientationValue($a, $b, $d);
    $o3 = orientationValue($c, $d, $a);
    $o4 = orientationValue($c, $d, $b);

    if (oppositeSigns($o1, $o2) && oppositeSigns($o3, $o4)) {
        return true;
    }

    return (
        abs($o1) <= GEO_EPSILON && pointOnSegment($c, $a, $b)
    ) || (
        abs($o2) <= GEO_EPSILON && pointOnSegment($d, $a, $b)
    ) || (
        abs($o3) <= GEO_EPSILON && pointOnSegment($a, $c, $d)
    ) || (
        abs($o4) <= GEO_EPSILON && pointOnSegment($b, $c, $d)
    );
}

function oppositeSigns(float $a, float $b): bool
{
    return (
        $a > GEO_EPSILON && $b < -GEO_EPSILON
    ) || (
        $a < -GEO_EPSILON && $b > GEO_EPSILON
    );
}

function pointOnSegment(array $point, array $a, array $b): bool
{
    if (!isCoordinate($point) || !isCoordinate($a) || !isCoordinate($b)) {
        return false;
    }

    if (abs(orientationValue($a, $b, $point)) > GEO_EPSILON) {
        return false;
    }

    return (
        (float) $point[0] >= min((float) $a[0], (float) $b[0]) - GEO_EPSILON
    ) && (
        (float) $point[0] <= max((float) $a[0], (float) $b[0]) + GEO_EPSILON
    ) && (
        (float) $point[1] >= min((float) $a[1], (float) $b[1]) - GEO_EPSILON
    ) && (
        (float) $point[1] <= max((float) $a[1], (float) $b[1]) + GEO_EPSILON
    );
}

function orientationValue(array $a, array $b, array $c): float
{
    return (
        ((float) $b[0] - (float) $a[0])
        * ((float) $c[1] - (float) $a[1])
    ) - (
        ((float) $b[1] - (float) $a[1])
        * ((float) $c[0] - (float) $a[0])
    );
}

function pointsEqual(array $a, array $b): bool
{
    return abs((float) $a[0] - (float) $b[0]) <= GEO_EPSILON
        && abs((float) $a[1] - (float) $b[1]) <= GEO_EPSILON;
}

function isCoordinate(array $value): bool
{
    return count($value) >= 2
        && is_numeric($value[0])
        && is_numeric($value[1])
        && is_finite((float) $value[0])
        && is_finite((float) $value[1]);
}
