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

const VERSION = '1.1.0';

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

    $entry = loadCache($config);

    if ($entry !== null && time() < $entry['expires_at']) {
        serveCache($entry, 'HIT', $method);
        return;
    }

    $lockPath = $config['cache_dir'] . '/refresh.lock';
    $lock = fopen($lockPath, 'c');

    if ($lock === false) {
        sendError(500, 'could not open cache lock file');
        return;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('could not acquire cache lock');
        }

        // Another request may have refreshed the cache while this request waited.
        $entry = loadCache($config);

        if ($entry !== null && time() < $entry['expires_at']) {
            serveCache($entry, 'HIT', $method);
            return;
        }

        try {
            $body = fetchAndPrepare($config);
            $entry = saveCache($config, $body);
            serveCache($entry, 'MISS', $method);
            return;
        } catch (Throwable $e) {
            error_log('GeoJSON proxy refresh failed: ' . $e->getMessage());

            $entry = loadCache($config);

            if ($entry !== null && time() < $entry['stale_until']) {
                header('Warning: 110 - "Response is stale because source refresh failed"');
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

        $lockPath = $config['cache_dir'] . '/refresh.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('could not open cache lock file');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('could not acquire cache lock');
            }

            $body = fetchAndPrepare($config);
            $entry = saveCache($config, $body);
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

Web usage:

  https://your-domain.example/geojson-spatial-filter-proxy.php

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

    $sourceDocument = fetchGeoJsonDocument(
        $config,
        $config['source_url'],
        'source GeoJSON'
    );

    $bufferDocument = fetchGeoJsonDocument(
        $config,
        $config['buffer_url'],
        'buffer GeoJSON'
    );

    [$filteredDocument, $statistics] = filterGeoJsonByBuffers(
        $sourceDocument,
        $bufferDocument
    );

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

    error_log(sprintf(
        'GeoJSON filter refreshed: retained %d of %d features; '
        . '%d buffer polygons; result %.2f MiB; peak memory %.2f MiB; %.2f s',
        $statistics['retained_features'],
        $statistics['source_features'],
        $statistics['buffer_polygons'],
        strlen($body) / 1024 / 1024,
        memory_get_peak_usage(true) / 1024 / 1024,
        microtime(true) - $startedAt
    ));

    return $body;
}

/**
 * Downloads, optionally decompresses, and decodes one GeoJSON document.
 */
function fetchGeoJsonDocument(array $config, string $url, string $label): array
{
    $downloaded = fetchUrl($config, $url);

    if (strlen($downloaded) > $config['max_bytes']) {
        throw new RuntimeException($label . ' response exceeds MAX_BYTES');
    }

    if (isGzip($downloaded)) {
        if (!function_exists('gzdecode')) {
            throw new RuntimeException('PHP zlib support is required for gzip data');
        }

        $body = gzdecode($downloaded);

        if ($body === false) {
            throw new RuntimeException('could not decompress ' . $label . ' gzip payload');
        }
    } else {
        // cURL may already have decoded HTTP Content-Encoding gzip.
        $body = $downloaded;
    }

    unset($downloaded);

    if (strlen($body) > $config['max_bytes']) {
        throw new RuntimeException($label . ' decompressed response exceeds MAX_BYTES');
    }

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
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

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
| Spatial filtering
|--------------------------------------------------------------------------
|
| These routines implement topological intersection tests in pure PHP.
| They do not calculate distances. Both datasets must therefore already use
| the same coordinate reference system and coordinate order.
|
*/

/**
 * @return array{
 *     0: array,
 *     1: array{
 *         source_features: int,
 *         retained_features: int,
 *         buffer_polygons: int
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

    $buffers = prepareBufferPolygons($bufferDocument);

    if ($buffers === []) {
        throw new RuntimeException(
            'buffer GeoJSON contains no valid Polygon or MultiPolygon geometry'
        );
    }

    $retained = [];

    foreach ($sourceFeatures as $feature) {
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
        ],
    ];
}

/**
 * @return array<int, array{
 *     coordinates: array,
 *     bbox: array{minX: float, minY: float, maxX: float, maxY: float},
 *     segments: array<int, array{0: array, 1: array}>
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

        $prepared[] = [
            'coordinates' => $polygon,
            'bbox' => $bbox,
            'segments' => polygonSegments($polygon),
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
 * @param array<int, array{
 *     coordinates: array,
 *     bbox: array,
 *     segments: array
 * }> $buffers
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
                    $result[] = [
                        'type' => 'Point',
                        'coordinates' => $point,
                    ];
                }
            }
            break;

        case 'MultiLineString':
            foreach ($coordinates ?? [] as $line) {
                if (is_array($line)) {
                    $result[] = [
                        'type' => 'LineString',
                        'coordinates' => $line,
                    ];
                }
            }
            break;

        case 'MultiPolygon':
            foreach ($coordinates ?? [] as $polygon) {
                if (is_array($polygon)) {
                    $result[] = [
                        'type' => 'Polygon',
                        'coordinates' => $polygon,
                    ];
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

/**
 * @param array{
 *     coordinates: array,
 *     bbox: array,
 *     segments: array
 * } $buffer
 */
function simpleGeometryIntersectsPreparedPolygon(array $geometry, array $buffer): bool
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;

    if (!is_array($coordinates)) {
        return false;
    }

    return match ($type) {
        'Point' => pointInPolygon($coordinates, $buffer['coordinates']),
        'LineString' => lineStringIntersectsPreparedPolygon(
            $coordinates,
            $buffer['coordinates'],
            $buffer['segments']
        ),
        'Polygon' => polygonIntersectsPreparedPolygon(
            $coordinates,
            $buffer['coordinates'],
            $buffer['segments']
        ),
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
            $bbox = [
                'minX' => $x,
                'minY' => $y,
                'maxX' => $x,
                'maxY' => $y,
            ];
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

/**
 * Point-in-polygon test supporting GeoJSON inner rings (holes).
 * Polygon boundaries count as intersection.
 */
function pointInPolygon(array $point, array $polygon): bool
{
    if (!isCoordinate($point) || !isset($polygon[0]) || !is_array($polygon[0])) {
        return false;
    }

    if (!pointInRing($point, $polygon[0], true)) {
        return false;
    }

    // A point inside a hole is outside the polygon. A point on a hole's
    // boundary still intersects the polygon boundary and therefore remains true.
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

function lineStringIntersectsPreparedPolygon(
    array $line,
    array $polygon,
    array $polygonSegments
): bool {
    foreach ($line as $point) {
        if (is_array($point) && pointInPolygon($point, $polygon)) {
            return true;
        }
    }

    return segmentCollectionsIntersect(
        lineSegments($line),
        $polygonSegments
    );
}

function polygonIntersectsPreparedPolygon(
    array $sourcePolygon,
    array $bufferPolygon,
    array $bufferSegments
): bool {
    $sourceSegments = polygonSegments($sourcePolygon);

    if (segmentCollectionsIntersect($sourceSegments, $bufferSegments)) {
        return true;
    }

    $sourcePoint = firstPolygonPoint($sourcePolygon);

    if ($sourcePoint !== null && pointInPolygon($sourcePoint, $bufferPolygon)) {
        return true;
    }

    $bufferPoint = firstPolygonPoint($bufferPolygon);

    return $bufferPoint !== null && pointInPolygon($bufferPoint, $sourcePolygon);
}

function firstPolygonPoint(array $polygon): ?array
{
    $point = $polygon[0][0] ?? null;

    return is_array($point) && isCoordinate($point) ? $point : null;
}

/** @return array<int, array{0: array, 1: array}> */
function polygonSegments(array $polygon): array
{
    $segments = [];

    foreach ($polygon as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        foreach (ringSegments($ring) as $segment) {
            $segments[] = $segment;
        }
    }

    return $segments;
}

/** @return array<int, array{0: array, 1: array}> */
function lineSegments(array $line): array
{
    $segments = [];
    $count = count($line);

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $line[$i] ?? null;
        $b = $line[$i + 1] ?? null;

        if (
            is_array($a)
            && is_array($b)
            && isCoordinate($a)
            && isCoordinate($b)
        ) {
            $segments[] = [$a, $b];
        }
    }

    return $segments;
}

/** @return array<int, array{0: array, 1: array}> */
function ringSegments(array $ring): array
{
    $segments = lineSegments($ring);
    $count = count($ring);

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
            $segments[] = [$last, $first];
        }
    }

    return $segments;
}

function segmentCollectionsIntersect(array $segmentsA, array $segmentsB): bool
{
    foreach ($segmentsA as [$a1, $a2]) {
        foreach ($segmentsB as [$b1, $b2]) {
            if (segmentsIntersect($a1, $a2, $b1, $b2)) {
                return true;
            }
        }
    }

    return false;
}

function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    // Fast segment-bounding-box rejection.
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
    if (
        !isCoordinate($point)
        || !isCoordinate($a)
        || !isCoordinate($b)
    ) {
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
