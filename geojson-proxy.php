<?php

declare(strict_types=1);

/**
 * uMap GeoJSON spatial filter proxy
 *
 * Downloads a gzip-compressed GeoJSON source and a GeoJSON buffer dataset,
 * keeps only source features that intersect at least one buffer polygon,
 * caches full and point representations of the filtered result, and serves
 * plain GeoJSON to uMap.
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

const VERSION = '1.5.1';

/**
 * Canonical API response when no feature survives the filters.
 *
 * A bare JSON array (`[]`) is not a GeoJSON document. uMap expects the
 * top-level object to remain a FeatureCollection even when it has no
 * features.
 */
const EMPTY_FEATURE_COLLECTION_JSON =
    '{"type":"FeatureCollection","features":[]}';

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

/**
 * Feature property containing the transport-mode list.
 */
const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';

/**
 * Allowed transport-mode values.
 *
 * A feature passes this attribute filter when at least one value from its
 * properties[TRANSPORT_MODE_PROPERTY] list occurs in this array.
 *
 * Leave the array empty to disable the attribute filter and retain all
 * transport modes that pass the spatial filter.
 *
 * Matching is exact and case-sensitive.
 *
 * Example:
 *
 * const ALLOWED_TRANSPORT_MODE_TYPES = ['bus', 'tram', 'train'];
 */
const ALLOWED_TRANSPORT_MODE_TYPES = [];

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
const DEBUG_LOG_ENABLED = true;

/** Log file name inside CACHE_DIR. */
const DEBUG_LOG_FILENAME = 'proxy.log';

/** Current processing status file name inside CACHE_DIR. */
const STATUS_FILENAME = 'status.json';

/** Allow GET requests with ?status=1 to return the current processing status. */
const STATUS_ENDPOINT_ENABLED = true;

/** Write filtering progress after this many source features. Set to 0 to disable. */
const LOG_PROGRESS_EVERY = 1000;

/**
 * Compact spatial indexes.
 *
 * Segment coordinates are stored as four packed doubles (32 bytes per
 * segment). Grid and bucket memberships are stored as packed unsigned
 * 32-bit IDs (4 bytes per reference), avoiding large nested PHP integer
 * arrays.
 */
const PACKED_SEGMENT_BYTES = 32;
const PACKED_ID_BYTES = 4;

/** Approximate number of indexed buffer segments per grid cell. */
const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL = 24;

/** Upper bounds prevent pathological CPU and memory use. */
const SPATIAL_INDEX_MAX_TOTAL_CELLS = 16384;
const SPATIAL_INDEX_MAX_GRID_DIMENSION = 128;
const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT = 128;
const SPATIAL_INDEX_MAX_REFERENCE_BYTES = 16 * 1024 * 1024;

/** Point-in-polygon Y-bucket index settings. */
const POINT_INDEX_TARGET_EDGES_PER_BUCKET = 32;
const POINT_INDEX_MAX_BUCKETS = 256;
const POINT_INDEX_MAX_BUCKETS_PER_EDGE = 32;
const POINT_INDEX_MAX_REFERENCE_BYTES = 8 * 1024 * 1024;

/**
 * A controlled error is raised before an individual polygon's compact
 * segment store could consume an unreasonable part of the PHP memory limit.
 */
const COMPACT_SEGMENT_MAX_BYTES = 64 * 1024 * 1024;
const MEMORY_SAFETY_RESERVE_BYTES = 32 * 1024 * 1024;

if (!defined('GEOJSON_PROXY_SKIP_MAIN')) {
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
}

function loadConfig(): array
{
    return [
        'source_url' => SOURCE_URL,
        'buffer_url' => BUFFER_URL,
        'transport_mode_property' => TRANSPORT_MODE_PROPERTY,
        'allowed_transport_mode_types' => normalizeAllowedTransportModeTypes(
            ALLOWED_TRANSPORT_MODE_TYPES
        ),
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

    $geometryMode = requestedGeometryMode();

    initializeDiagnostics($config, 'web');
    setProxyStage('request-start', [
        'method' => $method,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'geometry_mode' => $geometryMode,
    ]);

    $entry = loadCache($config);

    if ($entry !== null && time() < $entry['expires_at']) {
        setProxyStage('serving-fresh-cache', [
            'cache_age_seconds' => max(0, time() - $entry['fetched_at']),
            'response_bytes' => cacheEntryBytes($entry, $geometryMode),
            'geometry_mode' => $geometryMode,
        ]);
        serveCache($entry, 'HIT', $method, $geometryMode);
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
                'response_bytes' => cacheEntryBytes($entry, $geometryMode),
                'geometry_mode' => $geometryMode,
            ]);
            serveCache($entry, 'HIT', $method, $geometryMode);
            return;
        }

        try {
            setProxyStage('refresh-started');
            $representations = fetchAndPrepare($config);

            setProxyStage('writing-cache', [
                'result_bytes' => strlen($representations['full']),
                'point_result_bytes' => strlen($representations['point']),
            ]);
            $entry = saveCache(
                $config,
                $representations['full'],
                $representations['point']
            );
            unset($representations);

            setProxyStage('refresh-complete', [
                'response_bytes' => $entry['bytes'],
                'point_response_bytes' => $entry['point_bytes'],
                'etag' => $entry['etag'],
                'point_etag' => $entry['point_etag'],
            ]);
            serveCache($entry, 'MISS', $method, $geometryMode);
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
                serveCache($entry, 'STALE', $method, $geometryMode);
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

/**
 * Selects the representation served for this request.
 *
 * The default keeps the complete filtered geometries. ?geometry=point returns
 * a Point geometry for every retained feature while preserving IDs,
 * properties, and other feature members.
 */
function requestedGeometryMode(): string
{
    if (!array_key_exists('geometry', $_GET)) {
        return 'full';
    }

    $value = $_GET['geometry'];

    if (!is_string($value)) {
        sendError(400, 'invalid geometry parameter', 'geometry must be a string');
        exit;
    }

    $value = strtolower(trim($value));

    if ($value === '' || $value === 'full') {
        return 'full';
    }

    if ($value === 'point' || $value === 'centroid') {
        return 'point';
    }

    sendError(
        400,
        'invalid geometry parameter',
        'supported values are full, point, and centroid'
    );
    exit;
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
            $representations = fetchAndPrepare($config);
            setProxyStage('writing-cache', [
                'result_bytes' => strlen($representations['full']),
                'point_result_bytes' => strlen($representations['point']),
            ]);
            $entry = saveCache(
                $config,
                $representations['full'],
                $representations['point']
            );
            unset($representations);
            setProxyStage('cli-warm-cache-complete', [
                'result_bytes' => $entry['bytes'],
                'point_result_bytes' => $entry['point_bytes'],
                'etag' => $entry['etag'],
                'point_etag' => $entry['point_etag'],
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        echo "cache refreshed\n";
        echo 'etag: ' . $entry['etag'] . "\n";
        echo 'bytes: ' . $entry['bytes'] . "\n";
        echo 'point_etag: ' . $entry['point_etag'] . "\n";
        echo 'point_bytes: ' . $entry['point_bytes'] . "\n";
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
  TRANSPORT_MODE_PROPERTY
                 Feature property containing the transport-mode list.
  ALLOWED_TRANSPORT_MODE_TYPES
                 Exact, case-sensitive values allowed by the attribute filter.
                 At least one configured value must occur in a feature. An
                 empty array disables this additional attribute filter.
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

  Full filtered geometries:
  https://your-domain.example/geojson-spatial-filter-proxy.php

  Point representation (centroid or robust fallback per feature):
  https://your-domain.example/geojson-spatial-filter-proxy.php?geometry=point

Status endpoint:

  https://your-domain.example/geojson-spatial-filter-proxy.php?status=1

  The normal endpoint remains GeoJSON-only. The status endpoint reports the
  current processing stage without interrupting the running refresh.

uMap configuration:

  Full geometry layer:
  URL:    https://your-domain.example/geojson-spatial-filter-proxy.php
  Format: GeoJSON

  Symbol/point layer:
  URL:    https://your-domain.example/geojson-spatial-filter-proxy.php?geometry=point
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
  When ALLOWED_TRANSPORT_MODE_TYPES is not empty, a feature must have a
  matching string in properties[TRANSPORT_MODE_PROPERTY].
  Buffer data may be a Geometry, Feature, FeatureCollection, or
  GeometryCollection containing Polygon or MultiPolygon geometries.
  Buffer polygons are processed one at a time. Segment coordinates and index
  references use compact binary storage to stay within constrained PHP memory.
  Both datasets must use the same coordinate reference system.
  The point representation is calculated in that coordinate plane. For normal
  longitude/latitude GeoJSON this is a planar, not geodesic, centroid.
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

    if (trim((string) $config['transport_mode_property']) === '') {
        throw new RuntimeException('TRANSPORT_MODE_PROPERTY must not be empty');
    }

    if (!is_array($config['allowed_transport_mode_types'])) {
        throw new RuntimeException('ALLOWED_TRANSPORT_MODE_TYPES must be an array');
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
 * Downloads both GeoJSON documents, filters the source, and returns the full
 * and point GeoJSON representations that will be stored in the cache.
 */
function fetchAndPrepare(array $config): array
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

    proxyLog('INFO', 'transport-mode filter configured', [
        'enabled' => $config['allowed_transport_mode_types'] !== [],
        'property' => $config['transport_mode_property'],
        'allowed_values' => $config['allowed_transport_mode_types'],
        'match_semantics' => 'any',
        'case_sensitive' => true,
    ]);

    setProxyStage('filtering-transport-modes');
    $attributeStatistics = filterTransportModesInPlace(
        $sourceDocument,
        $config['transport_mode_property'],
        $config['allowed_transport_mode_types']
    );
    setProxyStage('transport-mode-filter-complete', $attributeStatistics);

    setProxyStage('downloading-buffer');
    $bufferDocument = fetchGeoJsonDocument(
        $config,
        $config['buffer_url'],
        'buffer GeoJSON'
    );

    setProxyStage('buffer-decoded', [
        'geojson_type' => $bufferDocument['type'] ?? null,
    ]);

    $polygonCoordinates = extractPolygonCoordinates($bufferDocument);
    unset($bufferDocument);

    if ($polygonCoordinates === []) {
        throw new RuntimeException(
            'buffer GeoJSON contains no Polygon or MultiPolygon geometry'
        );
    }

    setProxyStage('spatial-filter-start');
    $statistics = filterGeoJsonByBufferPolygonsInPlace(
        $sourceDocument,
        $polygonCoordinates,
        $attributeStatistics
    );
    unset($polygonCoordinates);

    setProxyStage('spatial-filter-complete', $statistics);

    try {
        setProxyStage('encoding-full-result', $statistics);
        $body = encodeFeatureCollectionForApi($sourceDocument);

        if (strlen($body) > $config['max_bytes']) {
            throw new RuntimeException('filtered result exceeds MAX_BYTES');
        }

        setProxyStage('building-point-representation', $statistics);
        $pointStatistics = convertToPointRepresentationInPlace($sourceDocument);
        setProxyStage(
            'encoding-point-result',
            array_merge($statistics, $pointStatistics)
        );

        $pointBody = encodeFeatureCollectionForApi($sourceDocument);
    } catch (JsonException $e) {
        throw new RuntimeException(
            'could not encode filtered GeoJSON: ' . $e->getMessage(),
            0,
            $e
        );
    }

    if (strlen($pointBody) > $config['max_bytes']) {
        throw new RuntimeException('point result exceeds MAX_BYTES');
    }

    unset($sourceDocument);

    $summary = [
        'retained_features' => $statistics['retained_features'],
        'point_features' => $pointStatistics['point_features'],
        'point_features_omitted' => $pointStatistics['point_features_omitted'],
        'source_features' => $statistics['source_features'],
        'transport_mode_filter_enabled' => $statistics['transport_mode_filter_enabled'],
        'transport_mode_matched_features' => $statistics['transport_mode_matched_features'],
        'transport_mode_rejected_features' => $statistics['transport_mode_rejected_features'],
        'buffer_polygons' => $statistics['buffer_polygons'],
        'result_bytes' => strlen($body),
        'point_result_bytes' => strlen($pointBody),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ];

    proxyLog('INFO', 'GeoJSON filter representations prepared successfully', $summary);
    setProxyStage('ready-to-cache', $summary);

    return [
        'full' => $body,
        'point' => $pointBody,
    ];
}

/**
 * Encodes a complete GeoJSON FeatureCollection for the public API.
 *
 * The empty case deliberately uses one canonical object instead of encoding
 * a feature list on its own. Besides being valid GeoJSON, this is accepted by
 * clients such as uMap and avoids retaining irrelevant collection metadata in
 * a no-result response.
 */
function encodeFeatureCollectionForApi(array &$document): string
{
    if (($document['type'] ?? null) !== 'FeatureCollection') {
        throw new RuntimeException(
            'API result must be a GeoJSON FeatureCollection'
        );
    }

    if (!is_array($document['features'] ?? null)) {
        throw new RuntimeException(
            'API result FeatureCollection has no valid features array'
        );
    }

    if ($document['features'] === []) {
        return EMPTY_FEATURE_COLLECTION_JSON;
    }

    return json_encode(
        $document,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
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
    $pointDataPath = $config['cache_dir'] . '/points.geojson';

    if (!is_file($metaPath) || !is_file($dataPath) || !is_file($pointDataPath)) {
        return null;
    }

    $metaRaw = file_get_contents($metaPath);
    if ($metaRaw === false) {
        return null;
    }

    $meta = json_decode($metaRaw, true);

    if (!is_array($meta)) {
        return null;
    }

    foreach ([
        'etag',
        'point_etag',
        'cache_key',
        'fetched_at',
        'expires_at',
        'stale_until',
        'bytes',
        'point_bytes',
    ] as $key) {
        if (!array_key_exists($key, $meta)) {
            return null;
        }
    }

    if (!hash_equals(makeCacheKey($config), (string) $meta['cache_key'])) {
        return null;
    }

    $bytes = filesize($dataPath);
    $pointBytes = filesize($pointDataPath);
    if (
        $bytes === false
        || $pointBytes === false
        || $bytes !== (int) $meta['bytes']
        || $pointBytes !== (int) $meta['point_bytes']
    ) {
        return null;
    }

    $etag = makeFileETag($dataPath);
    $pointEtag = makeFileETag($pointDataPath);
    if (
        $etag === null
        || $pointEtag === null
        || !hash_equals((string) $meta['etag'], $etag)
        || !hash_equals((string) $meta['point_etag'], $pointEtag)
    ) {
        return null;
    }

    return [
        'data_path' => $dataPath,
        'point_data_path' => $pointDataPath,
        'bytes' => $bytes,
        'point_bytes' => $pointBytes,
        'etag' => (string) $meta['etag'],
        'point_etag' => (string) $meta['point_etag'],
        'fetched_at' => (int) $meta['fetched_at'],
        'expires_at' => (int) $meta['expires_at'],
        'stale_until' => (int) $meta['stale_until'],
    ];
}

function saveCache(array $config, string $body, string $pointBody): array
{
    proxyLog('INFO', 'cache write started', [
        'data_path' => $config['cache_dir'] . '/data.geojson',
        'point_data_path' => $config['cache_dir'] . '/points.geojson',
        'meta_path' => $config['cache_dir'] . '/meta.json',
        'body_bytes' => strlen($body),
        'point_body_bytes' => strlen($pointBody),
    ]);

    $now = time();
    $dataPath = $config['cache_dir'] . '/data.geojson';
    $pointDataPath = $config['cache_dir'] . '/points.geojson';
    $metaPath = $config['cache_dir'] . '/meta.json';

    $entry = [
        'data_path' => $dataPath,
        'point_data_path' => $pointDataPath,
        'bytes' => strlen($body),
        'point_bytes' => strlen($pointBody),
        'etag' => makeETag($body),
        'point_etag' => makeETag($pointBody),
        'fetched_at' => $now,
        'expires_at' => $now + $config['cache_ttl'],
        'stale_until' => $now + $config['cache_ttl'] + $config['stale_ttl'],
    ];

    $suffix = '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $tmpData = $dataPath . $suffix;
    $tmpPointData = $pointDataPath . $suffix;
    $tmpMeta = $metaPath . $suffix;

    $meta = [
        'version' => VERSION,
        'cache_key' => makeCacheKey($config),
        'etag' => $entry['etag'],
        'point_etag' => $entry['point_etag'],
        'fetched_at' => $entry['fetched_at'],
        'expires_at' => $entry['expires_at'],
        'stale_until' => $entry['stale_until'],
        'bytes' => $entry['bytes'],
        'point_bytes' => $entry['point_bytes'],
    ];

    $metaJson = json_encode(
        $meta,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if (file_put_contents($tmpData, $body, LOCK_EX) === false) {
        throw new RuntimeException('could not write cache data');
    }

    if (file_put_contents($tmpPointData, $pointBody, LOCK_EX) === false) {
        @unlink($tmpData);
        throw new RuntimeException('could not write point cache data');
    }

    if (file_put_contents($tmpMeta, $metaJson, LOCK_EX) === false) {
        @unlink($tmpData);
        @unlink($tmpPointData);
        throw new RuntimeException('could not write cache metadata');
    }

    if (!rename($tmpData, $dataPath)) {
        @unlink($tmpData);
        @unlink($tmpPointData);
        @unlink($tmpMeta);
        throw new RuntimeException('could not activate cache data');
    }

    if (!rename($tmpPointData, $pointDataPath)) {
        @unlink($tmpPointData);
        @unlink($tmpMeta);
        throw new RuntimeException('could not activate point cache data');
    }

    if (!rename($tmpMeta, $metaPath)) {
        @unlink($tmpMeta);
        throw new RuntimeException('could not activate cache metadata');
    }

    proxyLog('INFO', 'cache write completed', [
        'data_path' => $dataPath,
        'point_data_path' => $pointDataPath,
        'meta_path' => $metaPath,
        'body_bytes' => $entry['bytes'],
        'point_body_bytes' => $entry['point_bytes'],
        'etag' => $entry['etag'],
        'point_etag' => $entry['point_etag'],
    ]);

    return $entry;
}

function makeCacheKey(array $config): string
{
    return hash(
        'sha256',
        VERSION
        . "\n" . $config['source_url']
        . "\n" . $config['buffer_url']
        . "\n" . $config['transport_mode_property']
        . "\n" . json_encode(
            $config['allowed_transport_mode_types'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        )
    );
}

function serveCache(
    array $entry,
    string $cacheStatus,
    string $method,
    string $geometryMode
): void {
    $pointMode = $geometryMode === 'point';
    $etag = $pointMode ? $entry['point_etag'] : $entry['etag'];
    $bytes = cacheEntryBytes($entry, $geometryMode);
    $path = cacheEntryPath($entry, $geometryMode);
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $notModified = etagHeaderMatches($ifNoneMatch, $etag);
    $body = null;

    if (!$notModified && $method !== 'HEAD') {
        $body = file_get_contents($path);

        if ($body === false || strlen($body) !== $bytes) {
            sendError(500, 'could not read cached GeoJSON');
            return;
        }
    }

    proxyLog('INFO', 'serving response', [
        'cache_status' => $cacheStatus,
        'method' => $method,
        'geometry_mode' => $geometryMode,
        'response_bytes' => $bytes,
        'etag' => $etag,
    ]);

    setCORSHeaders();

    $remainingTtl = max(0, $entry['expires_at'] - time());

    header('Content-Type: application/geo+json; charset=utf-8');
    header('ETag: ' . $etag);
    header('X-Cache: ' . $cacheStatus);
    header('X-Geometry-Mode: ' . $geometryMode);
    header('X-Cache-Fetched-At: ' . gmdate(DATE_ATOM, $entry['fetched_at']));
    header('Age: ' . max(0, time() - $entry['fetched_at']));

    if ($cacheStatus === 'STALE') {
        header('Cache-Control: public, max-age=0, must-revalidate');
    } else {
        header('Cache-Control: public, max-age=' . $remainingTtl);
    }

    if ($notModified) {
        http_response_code(304);
        return;
    }

    http_response_code(200);
    header('Content-Length: ' . $bytes);

    if ($method === 'HEAD') {
        return;
    }

    echo $body;
}

function cacheEntryBytes(array $entry, string $geometryMode): int
{
    return (int) (
        $geometryMode === 'point' ? $entry['point_bytes'] : $entry['bytes']
    );
}

function cacheEntryPath(array $entry, string $geometryMode): string
{
    return (string) (
        $geometryMode === 'point'
            ? $entry['point_data_path']
            : $entry['data_path']
    );
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

function makeFileETag(string $path): ?string
{
    $hash = hash_file('sha256', $path);
    return $hash === false ? null : '"' . $hash . '"';
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
 * Applies the inexpensive attribute filter before the buffer data is loaded.
 * Invalid geometries are removed at the same time. The existing feature array
 * is compacted in place so rejected feature trees can be released immediately.
 */
function filterTransportModesInPlace(
    array &$source,
    string $transportModeProperty,
    array $allowedTransportModeTypes
): array {
    if (($source['type'] ?? null) !== 'FeatureCollection') {
        throw new RuntimeException('source GeoJSON must be a FeatureCollection');
    }

    if (!is_array($source['features'] ?? null)) {
        throw new RuntimeException('source FeatureCollection has no valid features array');
    }

    $allowedTransportModeLookup = createStringLookup($allowedTransportModeTypes);
    $transportModeFilterEnabled = $allowedTransportModeLookup !== [];
    $features =& $source['features'];
    $totalFeatures = count($features);
    $writeIndex = 0;
    $transportModeMatchedFeatures = 0;
    $transportModeRejectedFeatures = 0;
    $featuresWithoutValidGeometry = 0;

    for ($readIndex = 0; $readIndex < $totalFeatures; $readIndex++) {
        $feature = $features[$readIndex] ?? null;

        if (!is_array($feature)) {
            $transportModeRejectedFeatures++;
            unset($features[$readIndex]);
            continue;
        }

        if (!featureMatchesAllowedTransportModes(
            $feature,
            $transportModeProperty,
            $allowedTransportModeLookup
        )) {
            $transportModeRejectedFeatures++;
            unset($features[$readIndex]);
            continue;
        }

        $transportModeMatchedFeatures++;

        if (!is_array($feature['geometry'] ?? null)) {
            $featuresWithoutValidGeometry++;
            unset($features[$readIndex]);
            continue;
        }

        $features[$writeIndex] = $feature;

        if ($writeIndex !== $readIndex) {
            unset($features[$readIndex]);
        }

        $writeIndex++;
    }

    return [
        'source_features' => $totalFeatures,
        'candidate_features' => $writeIndex,
        'transport_mode_filter_enabled' => $transportModeFilterEnabled,
        'transport_mode_property' => $transportModeProperty,
        'allowed_transport_mode_types' => $allowedTransportModeTypes,
        'transport_mode_matched_features' => $transportModeMatchedFeatures,
        'transport_mode_rejected_features' => $transportModeRejectedFeatures,
        'features_without_valid_geometry' => $featuresWithoutValidGeometry,
    ];
}

/**
 * Tests the remaining source features against one compact buffer polygon at a
 * time. A bit set remembers matches, while every polygon index is discarded
 * before the next one is created.
 */
function filterGeoJsonByBufferPolygonsInPlace(
    array &$source,
    array &$polygonCoordinates,
    array $attributeStatistics
): array {
    if (($source['type'] ?? null) !== 'FeatureCollection') {
        throw new RuntimeException('source GeoJSON must be a FeatureCollection');
    }

    if (!is_array($source['features'] ?? null)) {
        throw new RuntimeException('source FeatureCollection has no valid features array');
    }

    $features =& $source['features'];
    $candidateCount = count($features);
    $featureBboxes = [];

    foreach ($features as $featureIndex => $feature) {
        $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
        $featureBboxes[$featureIndex] = is_array($geometry)
            ? geometryBoundingBox($geometry)
            : null;
    }

    $matched = str_repeat("\0", intdiv($candidateCount + 7, 8));
    $matchedCount = 0;
    $inputPolygonCount = count($polygonCoordinates);
    $bufferPolygonCount = 0;
    $bufferSegmentCount = 0;
    $boundaryGridCellCount = 0;
    $maxCompactIndexBytes = 0;

    setProxyStage('preparing-buffer-polygons', [
        'input_buffer_polygons' => $inputPolygonCount,
        'candidate_features' => $candidateCount,
    ]);

    foreach ($polygonCoordinates as $polygonIndex => $polygon) {
        $prepared = is_array($polygon)
            ? prepareCompactBufferPolygon($polygon, (int) $polygonIndex)
            : null;

        // prepareCompactBufferPolygon has copied only compact numeric data.
        unset($polygonCoordinates[$polygonIndex], $polygon);

        if ($prepared === null) {
            continue;
        }

        $bufferPolygonCount++;
        $bufferSegmentCount += $prepared['segment_count'];
        $boundaryGridCellCount += count($prepared['boundary_index']['cells']);
        $maxCompactIndexBytes = max(
            $maxCompactIndexBytes,
            $prepared['compact_index_bytes']
        );

        foreach ($features as $featureIndex => $feature) {
            if (bitSetContains($matched, (int) $featureIndex)) {
                continue;
            }

            $featureBbox = $featureBboxes[$featureIndex] ?? null;
            if (
                !is_array($featureBbox)
                || !boundingBoxesIntersect($featureBbox, $prepared['bbox'])
            ) {
                continue;
            }

            $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
            if (
                is_array($geometry)
                && geometryIntersectsPreparedBuffer($geometry, $prepared)
            ) {
                bitSetAdd($matched, (int) $featureIndex);
                $matchedCount++;
            }
        }

        $progress = [
            'processed_buffer_polygons' => $bufferPolygonCount,
            'input_buffer_polygons' => $inputPolygonCount,
            'candidate_features' => $candidateCount,
            'retained_features_so_far' => $matchedCount,
            'buffer_segments_so_far' => $bufferSegmentCount,
            'current_polygon_segments' => $prepared['segment_count'],
            'current_boundary_grid_cells' => count(
                $prepared['boundary_index']['cells']
            ),
            'current_point_index_reference_bytes' =>
                $prepared['point_index_reference_bytes'],
            'current_grid_index_reference_bytes' =>
                $prepared['grid_index_reference_bytes'],
            'current_compact_index_bytes' => $prepared['compact_index_bytes'],
            'percent' => $inputPolygonCount > 0
                ? round(($polygonIndex + 1) * 100 / $inputPolygonCount, 1)
                : 100.0,
        ];

        proxyLog('INFO', 'buffer polygon processed with compact spatial indexes', $progress);
        writeProxyStatus('filtering', $progress);

        unset($prepared);
    }

    if ($bufferPolygonCount === 0) {
        throw new RuntimeException(
            'buffer GeoJSON contains no valid Polygon or MultiPolygon geometry'
        );
    }

    $writeIndex = 0;
    for ($readIndex = 0; $readIndex < $candidateCount; $readIndex++) {
        if (!bitSetContains($matched, $readIndex)) {
            unset($features[$readIndex]);
            continue;
        }

        if ($writeIndex !== $readIndex) {
            $features[$writeIndex] = $features[$readIndex];
            unset($features[$readIndex]);
        }

        $writeIndex++;
    }

    unset($featureBboxes, $matched);

    // A source-level bbox may no longer describe the filtered result.
    unset($source['bbox']);

    return array_merge($attributeStatistics, [
        'retained_features' => $writeIndex,
        'spatially_rejected_features' => $candidateCount - $writeIndex,
        'buffer_polygons' => $bufferPolygonCount,
        'buffer_segments' => $bufferSegmentCount,
        'boundary_grid_cells' => $boundaryGridCellCount,
        'max_compact_index_bytes' => $maxCompactIndexBytes,
    ]);
}

function bitSetContains(string $bits, int $id): bool
{
    if ($id < 0) {
        return false;
    }

    $byteIndex = intdiv($id, 8);
    if ($byteIndex >= strlen($bits)) {
        return false;
    }

    return (ord($bits[$byteIndex]) & (1 << ($id & 7))) !== 0;
}

function bitSetAdd(string &$bits, int $id): void
{
    $byteIndex = intdiv($id, 8);
    $mask = 1 << ($id & 7);
    $bits[$byteIndex] = chr(ord($bits[$byteIndex]) | $mask);
}

/**
 * Converts a FeatureCollection in place so every retained feature is reduced
 * to one representative Point. The full representation must be encoded before
 * calling this function. Feature IDs, properties, and foreign members are
 * preserved; feature-level and collection-level bboxes are removed.
 *
 * Polygon centroids account for holes. Line centroids are weighted by segment
 * length. Multi-geometries and GeometryCollections use the highest-dimensional
 * non-empty components, matching common centroid semantics. Degenerate areas
 * fall back to line or point centroids where possible.
 *
 * @return array{point_features: int, point_features_omitted: int}
 */
function convertToPointRepresentationInPlace(array &$document): array
{
    if (($document['type'] ?? null) !== 'FeatureCollection') {
        throw new RuntimeException('point representation requires a FeatureCollection');
    }

    if (!is_array($document['features'] ?? null)) {
        throw new RuntimeException('point representation requires a features array');
    }

    $features =& $document['features'];
    $total = count($features);
    $writeIndex = 0;
    $omitted = 0;

    for ($readIndex = 0; $readIndex < $total; $readIndex++) {
        $feature = $features[$readIndex] ?? null;

        if (!is_array($feature) || !is_array($feature['geometry'] ?? null)) {
            $omitted++;
            unset($features[$readIndex]);
            continue;
        }

        $point = representativePointForGeometry($feature['geometry']);

        if ($point === null) {
            $omitted++;
            unset($features[$readIndex]);
            continue;
        }

        $feature['geometry'] = [
            'type' => 'Point',
            'coordinates' => $point,
        ];
        unset($feature['bbox']);

        $features[$writeIndex] = $feature;

        if ($writeIndex !== $readIndex) {
            unset($features[$readIndex]);
        }

        $writeIndex++;
    }

    unset($document['bbox']);

    return [
        'point_features' => $writeIndex,
        'point_features_omitted' => $omitted,
    ];
}

/** @return array{0: float, 1: float}|null */
function representativePointForGeometry(array $geometry): ?array
{
    $component = geometryCentroidComponent($geometry);

    if ($component === null || $component['weight'] <= GEO_EPSILON) {
        return null;
    }

    $x = $component['weighted_x'] / $component['weight'];
    $y = $component['weighted_y'] / $component['weight'];

    if (!is_finite($x) || !is_finite($y)) {
        return null;
    }

    return [$x, $y];
}

/**
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function geometryCentroidComponent(array $geometry): ?array
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;

    switch ($type) {
        case 'Point':
            return is_array($coordinates) && isCoordinate($coordinates)
                ? pointCentroidComponent([$coordinates])
                : null;

        case 'MultiPoint':
            return is_array($coordinates)
                ? pointCentroidComponent($coordinates)
                : null;

        case 'LineString':
            return is_array($coordinates)
                ? lineCentroidComponent($coordinates)
                : null;

        case 'MultiLineString':
            $components = [];
            foreach (is_array($coordinates) ? $coordinates : [] as $line) {
                if (is_array($line)) {
                    $components[] = lineCentroidComponent($line);
                }
            }
            return combineCentroidComponents($components);

        case 'Polygon':
            return is_array($coordinates)
                ? polygonCentroidComponent($coordinates)
                : null;

        case 'MultiPolygon':
            $components = [];
            foreach (is_array($coordinates) ? $coordinates : [] as $polygon) {
                if (is_array($polygon)) {
                    $components[] = polygonCentroidComponent($polygon);
                }
            }
            return combineCentroidComponents($components);

        case 'GeometryCollection':
            $components = [];
            foreach ($geometry['geometries'] ?? [] as $child) {
                if (is_array($child)) {
                    $components[] = geometryCentroidComponent($child);
                }
            }
            return combineCentroidComponents($components);
    }

    return null;
}

/**
 * @param array<int, array|null> $components
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function combineCentroidComponents(array $components): ?array
{
    $dimension = null;
    $weight = 0.0;
    $weightedX = 0.0;
    $weightedY = 0.0;

    foreach ($components as $component) {
        if (!is_array($component) || $component['weight'] <= GEO_EPSILON) {
            continue;
        }

        if ($dimension === null || $component['dimension'] > $dimension) {
            $dimension = $component['dimension'];
            $weight = 0.0;
            $weightedX = 0.0;
            $weightedY = 0.0;
        }

        if ($component['dimension'] !== $dimension) {
            continue;
        }

        $weight += $component['weight'];
        $weightedX += $component['weighted_x'];
        $weightedY += $component['weighted_y'];
    }

    if ($dimension === null || $weight <= GEO_EPSILON) {
        return null;
    }

    return [
        'dimension' => $dimension,
        'weight' => $weight,
        'weighted_x' => $weightedX,
        'weighted_y' => $weightedY,
    ];
}

/**
 * @param array<int, array> $points
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function pointCentroidComponent(array $points): ?array
{
    $count = 0;
    $sumX = 0.0;
    $sumY = 0.0;

    foreach ($points as $point) {
        if (!is_array($point) || !isCoordinate($point)) {
            continue;
        }

        $sumX += (float) $point[0];
        $sumY += (float) $point[1];
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    return [
        'dimension' => 0,
        'weight' => (float) $count,
        'weighted_x' => $sumX,
        'weighted_y' => $sumY,
    ];
}

/**
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function lineCentroidComponent(array $line): ?array
{
    $length = 0.0;
    $weightedX = 0.0;
    $weightedY = 0.0;
    $pointCount = 0;
    $pointSumX = 0.0;
    $pointSumY = 0.0;
    $count = count($line);

    for ($i = 0; $i < $count; $i++) {
        $point = $line[$i] ?? null;
        if (is_array($point) && isCoordinate($point)) {
            $pointSumX += (float) $point[0];
            $pointSumY += (float) $point[1];
            $pointCount++;
        }
    }

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $line[$i] ?? null;
        $b = $line[$i + 1] ?? null;

        if (!is_array($a) || !is_array($b) || !isCoordinate($a) || !isCoordinate($b)) {
            continue;
        }

        $dx = (float) $b[0] - (float) $a[0];
        $dy = (float) $b[1] - (float) $a[1];
        $segmentLength = hypot($dx, $dy);

        if ($segmentLength <= GEO_EPSILON) {
            continue;
        }

        $length += $segmentLength;
        $weightedX += (((float) $a[0] + (float) $b[0]) / 2.0) * $segmentLength;
        $weightedY += (((float) $a[1] + (float) $b[1]) / 2.0) * $segmentLength;
    }

    if ($length > GEO_EPSILON) {
        return [
            'dimension' => 1,
            'weight' => $length,
            'weighted_x' => $weightedX,
            'weighted_y' => $weightedY,
        ];
    }

    if ($pointCount === 0) {
        return null;
    }

    return [
        'dimension' => 0,
        'weight' => (float) $pointCount,
        'weighted_x' => $pointSumX,
        'weighted_y' => $pointSumY,
    ];
}

/**
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function polygonCentroidComponent(array $polygon): ?array
{
    $area = 0.0;
    $weightedX = 0.0;
    $weightedY = 0.0;
    $lineComponents = [];

    foreach ($polygon as $ringIndex => $ring) {
        if (!is_array($ring)) {
            continue;
        }

        $lineComponents[] = lineCentroidComponent($ring);
        $ringComponent = ringAreaCentroidComponent($ring);

        if ($ringComponent === null) {
            continue;
        }

        $sign = $ringIndex === 0 ? 1.0 : -1.0;
        $area += $sign * $ringComponent['weight'];
        $weightedX += $sign * $ringComponent['weighted_x'];
        $weightedY += $sign * $ringComponent['weighted_y'];
    }

    if ($area > GEO_EPSILON) {
        return [
            'dimension' => 2,
            'weight' => $area,
            'weighted_x' => $weightedX,
            'weighted_y' => $weightedY,
        ];
    }

    return combineCentroidComponents($lineComponents);
}

/**
 * Returns an orientation-independent area centroid for one ring.
 *
 * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
 */
function ringAreaCentroidComponent(array $ring): ?array
{
    $areaTwice = 0.0;
    $centroidNumeratorX = 0.0;
    $centroidNumeratorY = 0.0;
    $first = null;
    $previous = null;
    $validPointCount = 0;

    foreach ($ring as $point) {
        if (!is_array($point) || !isCoordinate($point)) {
            continue;
        }

        $current = [(float) $point[0], (float) $point[1]];
        if ($first === null) {
            $first = $current;
        }

        if ($previous !== null) {
            $cross = $previous[0] * $current[1]
                - $current[0] * $previous[1];
            $areaTwice += $cross;
            $centroidNumeratorX += ($previous[0] + $current[0]) * $cross;
            $centroidNumeratorY += ($previous[1] + $current[1]) * $cross;
        }

        $previous = $current;
        $validPointCount++;
    }

    if ($validPointCount < 3 || $first === null || $previous === null) {
        return null;
    }

    $cross = $previous[0] * $first[1] - $first[0] * $previous[1];
    if (abs($cross) > 0.0) {
        $areaTwice += $cross;
        $centroidNumeratorX += ($previous[0] + $first[0]) * $cross;
        $centroidNumeratorY += ($previous[1] + $first[1]) * $cross;
    }

    if (abs($areaTwice) <= GEO_EPSILON) {
        return null;
    }

    $centroidX = $centroidNumeratorX / (3.0 * $areaTwice);
    $centroidY = $centroidNumeratorY / (3.0 * $areaTwice);
    $area = abs($areaTwice) / 2.0;

    if (!is_finite($centroidX) || !is_finite($centroidY)) {
        return null;
    }

    return [
        'dimension' => 2,
        'weight' => $area,
        'weighted_x' => $centroidX * $area,
        'weighted_y' => $centroidY * $area,
    ];
}

/**
 * Normalizes the configured transport-mode list once during startup.
 *
 * Empty strings are rejected. Duplicate values are removed and the result is
 * sorted so a semantically identical configuration produces the same cache key.
 * Matching against feature values remains exact and case-sensitive.
 *
 * @return array<int, string>
 */
function normalizeAllowedTransportModeTypes(array $values): array
{
    $normalized = [];

    foreach ($values as $index => $value) {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'ALLOWED_TRANSPORT_MODE_TYPES entry %s must be a string',
                (string) $index
            ));
        }

        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException(sprintf(
                'ALLOWED_TRANSPORT_MODE_TYPES entry %s must not be empty',
                (string) $index
            ));
        }

        $normalized[$value] = true;
    }

    $result = array_keys($normalized);
    sort($result, SORT_STRING);

    return $result;
}

/**
 * @param array<int, string> $values
 * @return array<string, true>
 */
function createStringLookup(array $values): array
{
    $lookup = [];

    foreach ($values as $value) {
        $lookup[$value] = true;
    }

    return $lookup;
}

/**
 * Returns true when the attribute filter is disabled or when at least one
 * feature value occurs in the configured allow-list.
 *
 * The expected GeoJSON shape is:
 *
 *   properties[TRANSPORT_MODE_PROPERTY] = ['bus', 'tram', ...]
 *
 * A single string is accepted as a convenience, but an array of strings is
 * the intended representation. Missing, null, or non-string values do not
 * match when the filter is enabled.
 *
 * @param array<string, true> $allowedLookup
 */
function featureMatchesAllowedTransportModes(
    array $feature,
    string $propertyName,
    array $allowedLookup
): bool {
    if ($allowedLookup === []) {
        return true;
    }

    $properties = $feature['properties'] ?? null;

    if (!is_array($properties) || !array_key_exists($propertyName, $properties)) {
        return false;
    }

    $featureValues = $properties[$propertyName];

    if (is_string($featureValues)) {
        return isset($allowedLookup[trim($featureValues)]);
    }

    if (!is_array($featureValues)) {
        return false;
    }

    foreach ($featureValues as $featureValue) {
        if (is_string($featureValue) && isset($allowedLookup[trim($featureValue)])) {
            return true;
        }
    }

    return false;
}

/**
 * Builds one memory-bounded polygon index. Segment coordinates are copied into
 * one packed binary string and all index memberships refer to that shared store.
 */
function prepareCompactBufferPolygon(array $polygon, int $polygonIndex): ?array
{
    if ($polygon === []) {
        return null;
    }

    $bbox = coordinatesBoundingBox($polygon);
    if ($bbox === null) {
        return null;
    }

    $firstPoint = firstPolygonPoint($polygon);
    $segments = '';
    $segmentCount = 0;
    $ringDescriptors = [];

    foreach ($polygon as $ring) {
        if (!is_array($ring) || $ring === []) {
            continue;
        }

        $ringBbox = coordinatesBoundingBox($ring);
        if ($ringBbox === null) {
            continue;
        }

        $segmentStart = $segmentCount;
        $pointCount = count($ring);

        for ($i = 0; $i + 1 < $pointCount; $i++) {
            $a = $ring[$i] ?? null;
            $b = $ring[$i + 1] ?? null;

            if (
                is_array($a)
                && is_array($b)
                && appendPackedSegment($segments, $a, $b)
            ) {
                $segmentCount++;
            }
        }

        if ($pointCount >= 3) {
            $first = $ring[0] ?? null;
            $last = $ring[$pointCount - 1] ?? null;

            if (
                is_array($first)
                && is_array($last)
                && isCoordinate($first)
                && isCoordinate($last)
                && !pointsEqual($first, $last)
                && appendPackedSegment($segments, $last, $first)
            ) {
                $segmentCount++;
            }
        }

        $ringSegmentCount = $segmentCount - $segmentStart;
        if ($ringSegmentCount > 0) {
            $ringDescriptors[] = [
                'bbox' => $ringBbox,
                'segment_start' => $segmentStart,
                'segment_count' => $ringSegmentCount,
            ];
        }
    }

    if ($segmentCount === 0 || $ringDescriptors === []) {
        return null;
    }

    if ($segmentCount > intdiv(POINT_INDEX_MAX_REFERENCE_BYTES, PACKED_ID_BYTES)) {
        throw new RuntimeException(sprintf(
            'buffer polygon %d has %d segments; compact point-index minimum exceeds %d bytes',
            $polygonIndex + 1,
            $segmentCount,
            POINT_INDEX_MAX_REFERENCE_BYTES
        ));
    }

    if ($segmentCount > intdiv(SPATIAL_INDEX_MAX_REFERENCE_BYTES, PACKED_ID_BYTES)) {
        throw new RuntimeException(sprintf(
            'buffer polygon %d has %d segments; compact grid-index minimum exceeds %d bytes',
            $polygonIndex + 1,
            $segmentCount,
            SPATIAL_INDEX_MAX_REFERENCE_BYTES
        ));
    }

    $pointReferenceBudget = intdiv(
        POINT_INDEX_MAX_REFERENCE_BYTES,
        PACKED_ID_BYTES
    );
    $preparedRings = [];
    $pointReferenceCount = 0;

    foreach ($ringDescriptors as $descriptor) {
        $minimumForLaterRings = $segmentCount
            - $descriptor['segment_start']
            - $descriptor['segment_count'];
        $availableForRing = $pointReferenceBudget - $minimumForLaterRings;

        $preparedRing = buildCompactPointIndex(
            $segments,
            $descriptor,
            $availableForRing
        );
        $preparedRings[] = $preparedRing;
        $pointReferenceBudget -= $preparedRing['reference_count'];
        $pointReferenceCount += $preparedRing['reference_count'];
    }

    $boundaryIndex = buildCompactSegmentGridIndex(
        $segments,
        $segmentCount,
        $bbox,
        intdiv(SPATIAL_INDEX_MAX_REFERENCE_BYTES, PACKED_ID_BYTES)
    );

    $compactIndexBytes = strlen($segments)
        + $pointReferenceCount * PACKED_ID_BYTES
        + $boundaryIndex['reference_count'] * PACKED_ID_BYTES;

    assertCompactIndexMemoryReserve($polygonIndex, $compactIndexBytes);

    return [
        'bbox' => $bbox,
        'first_point' => $firstPoint,
        'segments' => $segments,
        'rings' => $preparedRings,
        'boundary_index' => $boundaryIndex,
        'segment_count' => $segmentCount,
        'point_index_reference_bytes' =>
            $pointReferenceCount * PACKED_ID_BYTES,
        'grid_index_reference_bytes' =>
            $boundaryIndex['reference_count'] * PACKED_ID_BYTES,
        'compact_index_bytes' => $compactIndexBytes,
    ];
}

function appendPackedSegment(string &$segments, array $a, array $b): bool
{
    if (!isCoordinate($a) || !isCoordinate($b)) {
        return false;
    }

    $segments .= pack(
        'd4',
        (float) $a[0],
        (float) $a[1],
        (float) $b[0],
        (float) $b[1]
    );

    if (strlen($segments) > COMPACT_SEGMENT_MAX_BYTES) {
        throw new RuntimeException(
            'compact buffer segment store exceeds COMPACT_SEGMENT_MAX_BYTES'
        );
    }

    return true;
}

/** @return array{0: float, 1: float, 2: float, 3: float} */
function readPackedSegment(string $segments, int $id): array
{
    $offset = $id * PACKED_SEGMENT_BYTES;
    if ($id < 0 || $offset + PACKED_SEGMENT_BYTES > strlen($segments)) {
        throw new LogicException('packed segment ID is out of bounds');
    }

    $values = unpack('d4', $segments, $offset);
    if (!is_array($values) || count($values) !== 4) {
        throw new LogicException('could not decode packed segment');
    }

    return [
        (float) $values[1],
        (float) $values[2],
        (float) $values[3],
        (float) $values[4],
    ];
}

function appendPackedId(array &$lists, int $key, int $id): void
{
    $packed = pack('V', $id);

    if (isset($lists[$key])) {
        $lists[$key] .= $packed;
    } else {
        $lists[$key] = $packed;
    }
}

function appendPackedOverflowId(string &$ids, int $id): void
{
    $ids .= pack('V', $id);
}

function packedIdAt(string $ids, int $offset): int
{
    return ord($ids[$offset])
        | (ord($ids[$offset + 1]) << 8)
        | (ord($ids[$offset + 2]) << 16)
        | (ord($ids[$offset + 3]) << 24);
}

function assertCompactIndexMemoryReserve(int $polygonIndex, int $indexBytes): void
{
    $limit = phpMemoryLimitBytes();
    if ($limit === null) {
        return;
    }

    $used = memory_get_usage(true);
    if ($used + MEMORY_SAFETY_RESERVE_BYTES > $limit) {
        throw new RuntimeException(sprintf(
            'buffer polygon %d compact index uses %d bytes; only %d bytes remain below the PHP memory limit',
            $polygonIndex + 1,
            $indexBytes,
            max(0, $limit - $used)
        ));
    }
}

function phpMemoryLimitBytes(): ?int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return null;
    }

    if (!preg_match('/^(\d+)\s*([kmgt]?)b?$/i', $value, $matches)) {
        return null;
    }

    $bytes = (int) $matches[1];
    $unit = strtolower($matches[2]);
    $multipliers = [
        '' => 1,
        'k' => 1024,
        'm' => 1024 ** 2,
        'g' => 1024 ** 3,
        't' => 1024 ** 4,
    ];

    return $bytes * $multipliers[$unit];
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
 * Recursively evaluates GeoJSON geometries without materializing a flattened
 * list of Multi* or GeometryCollection components.
 */
function geometryIntersectsPreparedBuffer(array $geometry, array $buffer): bool
{
    $type = $geometry['type'] ?? null;
    $coordinates = $geometry['coordinates'] ?? null;

    switch ($type) {
        case 'Point':
            return is_array($coordinates)
                && pointInPreparedPolygon($coordinates, $buffer);

        case 'LineString':
            return is_array($coordinates)
                && lineStringIntersectsPreparedPolygon($coordinates, $buffer);

        case 'Polygon':
            return is_array($coordinates)
                && polygonIntersectsPreparedPolygon($coordinates, $buffer);

        case 'MultiPoint':
            foreach (is_array($coordinates) ? $coordinates : [] as $point) {
                if (is_array($point) && pointInPreparedPolygon($point, $buffer)) {
                    return true;
                }
            }
            return false;

        case 'MultiLineString':
            foreach (is_array($coordinates) ? $coordinates : [] as $line) {
                if (
                    is_array($line)
                    && lineStringIntersectsPreparedPolygon($line, $buffer)
                ) {
                    return true;
                }
            }
            return false;

        case 'MultiPolygon':
            foreach (is_array($coordinates) ? $coordinates : [] as $polygon) {
                if (
                    is_array($polygon)
                    && polygonIntersectsPreparedPolygon($polygon, $buffer)
                ) {
                    return true;
                }
            }
            return false;

        case 'GeometryCollection':
            foreach ($geometry['geometries'] ?? [] as $child) {
                if (
                    is_array($child)
                    && geometryIntersectsPreparedBuffer($child, $buffer)
                ) {
                    return true;
                }
            }
            return false;
    }

    return false;
}

function geometryBoundingBox(array $geometry): ?array
{
    if (($geometry['type'] ?? null) === 'GeometryCollection') {
        $bbox = null;

        foreach ($geometry['geometries'] ?? [] as $child) {
            if (!is_array($child)) {
                continue;
            }

            $childBbox = geometryBoundingBox($child);
            if ($childBbox !== null) {
                mergeBoundingBox($bbox, $childBbox);
            }
        }

        return $bbox;
    }

    $coordinates = $geometry['coordinates'] ?? null;

    if (!is_array($coordinates)) {
        return null;
    }

    return coordinatesBoundingBox($coordinates);
}

function mergeBoundingBox(?array &$target, array $source): void
{
    if ($target === null) {
        $target = $source;
        return;
    }

    $target['minX'] = min($target['minX'], $source['minX']);
    $target['minY'] = min($target['minY'], $source['minY']);
    $target['maxX'] = max($target['maxX'], $source['maxX']);
    $target['maxY'] = max($target['maxY'], $source['maxY']);
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

    if (
        !isset($rings[0])
        || !pointInPreparedRing(
            $point,
            $rings[0],
            $polygon['segments'],
            true
        )
    ) {
        return false;
    }

    for ($i = 1, $count = count($rings); $i < $count; $i++) {
        // A boundary of a hole is still part of the polygon boundary and must
        // count as an intersection. Therefore boundaryCountsAsInside=false.
        if (pointInPreparedRing(
            $point,
            $rings[$i],
            $polygon['segments'],
            false
        )) {
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
 * Builds a Y-bucket index with the greatest resolution that still fits the
 * supplied packed-reference budget.
 */
function buildCompactPointIndex(
    string $segments,
    array $descriptor,
    int $maximumReferences
): array {
    $segmentCount = (int) $descriptor['segment_count'];
    if ($maximumReferences < $segmentCount) {
        throw new RuntimeException(
            'compact point-index reference budget is smaller than its minimum'
        );
    }

    $bucketCount = max(
        1,
        min(
            POINT_INDEX_MAX_BUCKETS,
            (int) ceil($segmentCount / POINT_INDEX_TARGET_EDGES_PER_BUCKET)
        )
    );

    if (($descriptor['bbox']['maxY'] - $descriptor['bbox']['minY']) <= GEO_EPSILON) {
        $bucketCount = 1;
    }

    while (
        estimateCompactPointReferences($segments, $descriptor, $bucketCount)
            > $maximumReferences
    ) {
        if ($bucketCount === 1) {
            throw new RuntimeException('compact point-index budget could not be satisfied');
        }

        $bucketCount = max(1, intdiv($bucketCount + 1, 2));
    }

    $buckets = [];
    $overflow = '';
    $referenceCount = 0;
    $start = (int) $descriptor['segment_start'];
    $end = $start + $segmentCount;

    for ($id = $start; $id < $end; $id++) {
        [$ax, $ay, $bx, $by] = readPackedSegment($segments, $id);
        $minBucket = pointIndexBucketForY(
            min($ay, $by),
            $descriptor['bbox'],
            $bucketCount
        );
        $maxBucket = pointIndexBucketForY(
            max($ay, $by),
            $descriptor['bbox'],
            $bucketCount
        );
        $span = $maxBucket - $minBucket + 1;

        if ($span > POINT_INDEX_MAX_BUCKETS_PER_EDGE) {
            appendPackedOverflowId($overflow, $id);
            $referenceCount++;
            continue;
        }

        for ($bucket = $minBucket; $bucket <= $maxBucket; $bucket++) {
            appendPackedId($buckets, $bucket, $id);
            $referenceCount++;
        }
    }

    return [
        'bbox' => $descriptor['bbox'],
        'segment_start' => $start,
        'segment_count' => $segmentCount,
        'bucket_count' => $bucketCount,
        'buckets' => $buckets,
        'overflow' => $overflow,
        'reference_count' => $referenceCount,
    ];
}

function estimateCompactPointReferences(
    string $segments,
    array $descriptor,
    int $bucketCount
): int {
    $references = 0;
    $start = (int) $descriptor['segment_start'];
    $end = $start + (int) $descriptor['segment_count'];

    for ($id = $start; $id < $end; $id++) {
        [, $ay, , $by] = readPackedSegment($segments, $id);
        $minBucket = pointIndexBucketForY(
            min($ay, $by),
            $descriptor['bbox'],
            $bucketCount
        );
        $maxBucket = pointIndexBucketForY(
            max($ay, $by),
            $descriptor['bbox'],
            $bucketCount
        );
        $span = $maxBucket - $minBucket + 1;
        $references += $span > POINT_INDEX_MAX_BUCKETS_PER_EDGE ? 1 : $span;
    }

    return $references;
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

function pointInPreparedRing(
    array $point,
    array $ring,
    string $segments,
    bool $boundaryCountsAsInside
): bool
{
    if (!pointInBoundingBox($point, $ring['bbox'])) {
        return false;
    }

    $bucket = pointIndexBucketForY((float) $point[1], $ring['bbox'], $ring['bucket_count']);
    $inside = false;

    if (evaluatePointAgainstPackedSegments(
        $point,
        $ring['buckets'][$bucket] ?? '',
        $segments,
        $inside
    )) {
        return $boundaryCountsAsInside;
    }

    if (evaluatePointAgainstPackedSegments(
        $point,
        $ring['overflow'],
        $segments,
        $inside
    )) {
        return $boundaryCountsAsInside;
    }

    return $inside;
}

/**
 * Toggles $inside for ray crossings and returns true when the point lies on a
 * candidate boundary segment.
 */
function evaluatePointAgainstPackedSegments(
    array $point,
    string $ids,
    string $segments,
    bool &$inside
): bool {
    $px = (float) $point[0];
    $py = (float) $point[1];
    $length = strlen($ids);

    for ($offset = 0; $offset < $length; $offset += PACKED_ID_BYTES) {
        $id = packedIdAt($ids, $offset);
        [$ax, $ay, $bx, $by] = readPackedSegment($segments, $id);

        if (pointOnSegmentCoordinates($px, $py, $ax, $ay, $bx, $by)) {
            return true;
        }

        $crosses = ($by > $py) !== ($ay > $py);
        if (!$crosses) {
            continue;
        }

        $intersectionX = ($ax - $bx) * ($py - $by) / ($ay - $by) + $bx;
        if ($px < $intersectionX) {
            $inside = !$inside;
        }
    }

    return false;
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

    return lineBoundaryIntersectsIndex($line, $buffer);
}

function polygonIntersectsPreparedPolygon(array $sourcePolygon, array $buffer): bool
{
    if (polygonBoundaryIntersectsIndex($sourcePolygon, $buffer)) {
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

function lineBoundaryIntersectsIndex(array $line, array $buffer): bool
{
    $count = count($line);

    for ($i = 0; $i + 1 < $count; $i++) {
        $a = $line[$i] ?? null;
        $b = $line[$i + 1] ?? null;

        if (!is_array($a) || !is_array($b) || !isCoordinate($a) || !isCoordinate($b)) {
            continue;
        }

        if (segmentIntersectsGridIndex($a, $b, $buffer)) {
            return true;
        }
    }

    return false;
}

function polygonBoundaryIntersectsIndex(array $polygon, array $buffer): bool
{
    foreach ($polygon as $ring) {
        if (!is_array($ring)) {
            continue;
        }

        if (ringBoundaryIntersectsIndex($ring, $buffer)) {
            return true;
        }
    }

    return false;
}

function ringBoundaryIntersectsIndex(array $ring, array $buffer): bool
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
            && segmentIntersectsGridIndex($a, $b, $buffer)
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
            && segmentIntersectsGridIndex($last, $first, $buffer)
        ) {
            return true;
        }
    }

    return false;
}

function buildCompactSegmentGridIndex(
    string $segments,
    int $segmentCount,
    array $bbox,
    int $maximumReferences
): array
{
    if ($maximumReferences < $segmentCount) {
        throw new RuntimeException(
            'compact grid-index reference budget is smaller than its minimum'
        );
    }

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

    while (
        estimateCompactGridReferences(
            $segments,
            $segmentCount,
            $bbox,
            $columns,
            $rows
        ) > $maximumReferences
    ) {
        if ($columns === 1 && $rows === 1) {
            throw new RuntimeException('compact grid-index budget could not be satisfied');
        }

        $columns = max(1, intdiv($columns + 1, 2));
        $rows = max(1, intdiv($rows + 1, 2));
    }

    $cells = [];
    $overflow = '';
    $referenceCount = 0;

    for ($id = 0; $id < $segmentCount; $id++) {
        [$ax, $ay, $bx, $by] = readPackedSegment($segments, $id);
        $segmentBbox = [
            'minX' => min($ax, $bx),
            'minY' => min($ay, $by),
            'maxX' => max($ax, $bx),
            'maxY' => max($ay, $by),
        ];
        [$minColumn, $maxColumn, $minRow, $maxRow] = gridRangeForBoundingBox(
            $segmentBbox,
            $bbox,
            $columns,
            $rows
        );

        $cellSpan = ($maxColumn - $minColumn + 1) * ($maxRow - $minRow + 1);

        if ($cellSpan > SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT) {
            appendPackedOverflowId($overflow, $id);
            $referenceCount++;
            continue;
        }

        for ($row = $minRow; $row <= $maxRow; $row++) {
            for ($column = $minColumn; $column <= $maxColumn; $column++) {
                appendPackedId($cells, $row * $columns + $column, $id);
                $referenceCount++;
            }
        }
    }

    return [
        'bbox' => $bbox,
        'columns' => $columns,
        'rows' => $rows,
        'cells' => $cells,
        'overflow' => $overflow,
        'reference_count' => $referenceCount,
    ];
}

function estimateCompactGridReferences(
    string $segments,
    int $segmentCount,
    array $bbox,
    int $columns,
    int $rows
): int {
    $references = 0;

    for ($id = 0; $id < $segmentCount; $id++) {
        [$ax, $ay, $bx, $by] = readPackedSegment($segments, $id);
        $segmentBbox = [
            'minX' => min($ax, $bx),
            'minY' => min($ay, $by),
            'maxX' => max($ax, $bx),
            'maxY' => max($ay, $by),
        ];
        [$minColumn, $maxColumn, $minRow, $maxRow] = gridRangeForBoundingBox(
            $segmentBbox,
            $bbox,
            $columns,
            $rows
        );
        $span = ($maxColumn - $minColumn + 1) * ($maxRow - $minRow + 1);
        $references += $span > SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT ? 1 : $span;
    }

    return $references;
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

function segmentIntersectsGridIndex(array $a, array $b, array $buffer): bool
{
    $index = $buffer['boundary_index'];
    $segments = $buffer['segments'];
    $segmentCount = (int) $buffer['segment_count'];
    $ax = (float) $a[0];
    $ay = (float) $a[1];
    $bx = (float) $b[0];
    $by = (float) $b[1];
    $segmentBbox = [
        'minX' => min($ax, $bx),
        'minY' => min($ay, $by),
        'maxX' => max($ax, $bx),
        'maxY' => max($ay, $by),
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

    $seen = str_repeat("\0", intdiv($segmentCount + 7, 8));

    if (packedCandidatesIntersectSourceSegment(
        $index['overflow'],
        $segments,
        $seen,
        $segmentBbox,
        $ax,
        $ay,
        $bx,
        $by
    )) {
        return true;
    }

    for ($row = $minRow; $row <= $maxRow; $row++) {
        for ($column = $minColumn; $column <= $maxColumn; $column++) {
            $key = $row * $index['columns'] + $column;
            if (packedCandidatesIntersectSourceSegment(
                $index['cells'][$key] ?? '',
                $segments,
                $seen,
                $segmentBbox,
                $ax,
                $ay,
                $bx,
                $by
            )) {
                return true;
            }
        }
    }

    return false;
}

function packedCandidatesIntersectSourceSegment(
    string $ids,
    string $segments,
    string &$seen,
    array $sourceBbox,
    float $ax,
    float $ay,
    float $bx,
    float $by
): bool {
    $length = strlen($ids);

    for ($offset = 0; $offset < $length; $offset += PACKED_ID_BYTES) {
        $id = packedIdAt($ids, $offset);
        if (packedBitSetTestAndAdd($seen, $id)) {
            continue;
        }

        [$cx, $cy, $dx, $dy] = readPackedSegment($segments, $id);

        if (
            max($cx, $dx) + GEO_EPSILON < $sourceBbox['minX']
            || min($cx, $dx) - GEO_EPSILON > $sourceBbox['maxX']
            || max($cy, $dy) + GEO_EPSILON < $sourceBbox['minY']
            || min($cy, $dy) - GEO_EPSILON > $sourceBbox['maxY']
        ) {
            continue;
        }

        if (segmentsIntersectCoordinates(
            $ax,
            $ay,
            $bx,
            $by,
            $cx,
            $cy,
            $dx,
            $dy
        )) {
            return true;
        }
    }

    return false;
}

/** Returns true when the ID was already present. */
function packedBitSetTestAndAdd(string &$bits, int $id): bool
{
    $byteIndex = intdiv($id, 8);
    $mask = 1 << ($id & 7);
    $byte = ord($bits[$byteIndex]);

    if (($byte & $mask) !== 0) {
        return true;
    }

    $bits[$byteIndex] = chr($byte | $mask);
    return false;
}

function segmentsIntersect(array $a, array $b, array $c, array $d): bool
{
    return segmentsIntersectCoordinates(
        (float) $a[0],
        (float) $a[1],
        (float) $b[0],
        (float) $b[1],
        (float) $c[0],
        (float) $c[1],
        (float) $d[0],
        (float) $d[1]
    );
}

function segmentsIntersectCoordinates(
    float $ax,
    float $ay,
    float $bx,
    float $by,
    float $cx,
    float $cy,
    float $dx,
    float $dy
): bool {
    if (
        max($ax, $bx) + GEO_EPSILON < min($cx, $dx)
        || max($cx, $dx) + GEO_EPSILON < min($ax, $bx)
        || max($ay, $by) + GEO_EPSILON < min($cy, $dy)
        || max($cy, $dy) + GEO_EPSILON < min($ay, $by)
    ) {
        return false;
    }

    $o1 = orientationCoordinates($ax, $ay, $bx, $by, $cx, $cy);
    $o2 = orientationCoordinates($ax, $ay, $bx, $by, $dx, $dy);
    $o3 = orientationCoordinates($cx, $cy, $dx, $dy, $ax, $ay);
    $o4 = orientationCoordinates($cx, $cy, $dx, $dy, $bx, $by);

    if (oppositeSigns($o1, $o2) && oppositeSigns($o3, $o4)) {
        return true;
    }

    return (
        abs($o1) <= GEO_EPSILON
        && pointOnSegmentCoordinates($cx, $cy, $ax, $ay, $bx, $by)
    ) || (
        abs($o2) <= GEO_EPSILON
        && pointOnSegmentCoordinates($dx, $dy, $ax, $ay, $bx, $by)
    ) || (
        abs($o3) <= GEO_EPSILON
        && pointOnSegmentCoordinates($ax, $ay, $cx, $cy, $dx, $dy)
    ) || (
        abs($o4) <= GEO_EPSILON
        && pointOnSegmentCoordinates($bx, $by, $cx, $cy, $dx, $dy)
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

    return pointOnSegmentCoordinates(
        (float) $point[0],
        (float) $point[1],
        (float) $a[0],
        (float) $a[1],
        (float) $b[0],
        (float) $b[1]
    );
}

function pointOnSegmentCoordinates(
    float $px,
    float $py,
    float $ax,
    float $ay,
    float $bx,
    float $by
): bool {
    if (abs(orientationCoordinates($ax, $ay, $bx, $by, $px, $py)) > GEO_EPSILON) {
        return false;
    }

    return $px >= min($ax, $bx) - GEO_EPSILON
        && $px <= max($ax, $bx) + GEO_EPSILON
        && $py >= min($ay, $by) - GEO_EPSILON
        && $py <= max($ay, $by) + GEO_EPSILON;
}

function orientationValue(array $a, array $b, array $c): float
{
    return orientationCoordinates(
        (float) $a[0],
        (float) $a[1],
        (float) $b[0],
        (float) $b[1],
        (float) $c[0],
        (float) $c[1]
    );
}

function orientationCoordinates(
    float $ax,
    float $ay,
    float $bx,
    float $by,
    float $cx,
    float $cy
): float {
    return ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
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