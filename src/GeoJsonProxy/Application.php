<?php

declare(strict_types=1);

namespace GeoJsonProxy;

use RuntimeException;
use GeoJsonProxy\Config;
use GeoJsonProxy\Cache\Manager as CacheManager;
use GeoJsonProxy\Http\Fetcher;
use GeoJsonProxy\Http\GzipDetector;
use GeoJsonProxy\GeoJson\Parser;
use GeoJsonProxy\GeoJson\FeatureFilter;
use GeoJsonProxy\GeoJson\GeometryExtractor;
use GeoJsonProxy\GeoJson\Centroid;
use GeoJsonProxy\GeoJson\BoundingBox;
use GeoJsonProxy\GeoJson\SpatialFilter;
use GeoJsonProxy\Diagnostics\Logger;

/**
 * Main application class for GeoJSON proxy
 */
final class Application
{
    private Config $config;
    private CacheManager $cacheManager;
    private Fetcher $fetcher;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->cacheManager = new CacheManager($config);
        $this->fetcher = new Fetcher($config);
    }

    /**
     * Run the web application
     */
    public function runWeb(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'OPTIONS') {
            $this->handleOptions();
            return;
        }

        if ($method !== 'GET' && $method !== 'HEAD') {
            $this->handleMethodNotAllowed();
            return;
        }

        $this->cacheManager->ensureCacheDir();

        if ($method === 'GET' && $this->config->getBool('status_endpoint_enabled') && isset($_GET['status'])) {
            $this->handleStatus();
            return;
        }

        $geometryMode = $this->getRequestedGeometryMode();

        Logger::initialize($this->config->toArray(), 'web');
        Logger::setStage('request-start', [
            'method' => $method,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'geometry_mode' => $geometryMode,
        ]);

        $entry = $this->cacheManager->load();

        if ($entry !== null && time() < $entry['expires_at']) {
            Logger::setStage('serving-fresh-cache', [
                'cache_age_seconds' => max(0, time() - $entry['fetched_at']),
                'response_bytes' => $this->cacheManager->getEntryBytes($entry, $geometryMode),
                'geometry_mode' => $geometryMode,
            ]);
            $this->serveCache($entry, 'HIT', $method, $geometryMode);
            return;
        }

        Logger::log('INFO', 'no fresh cache available', [
            'cache_present' => $entry !== null,
        ]);

        $this->handleRefresh($entry, $method, $geometryMode);
    }

    /**
     * Run the CLI application
     */
    public function runCli(array $argv): void
    {
        $args = array_slice($argv, 1);

        if (in_array('-h', $args, true) || in_array('--help', $args, true)) {
            echo $this->getHelpText();
            return;
        }

        if (in_array('--version', $args, true)) {
            echo VERSION . PHP_EOL;
            return;
        }

        if (in_array('--warm-cache', $args, true)) {
            $this->cacheManager->ensureCacheDir();
            Logger::initialize($this->config->toArray(), 'cli');
            Logger::setStage('cli-warm-cache-start');

            $this->warmCache();
            return;
        }

        echo $this->getHelpText();
    }

    /**
     * Handle cache refresh with locking
     */
    private function handleRefresh(?array $entry, string $method, string $geometryMode): void
    {
        $cacheDir = $this->config->getString('cache_dir');
        $lockPath = $cacheDir . '/refresh.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            Logger::setStage('lock-open-failed', ['lock_path' => $lockPath]);
            $this->sendError(500, 'could not open cache lock file');
            return;
        }

        $lockWaitStarted = microtime(true);
        Logger::setStage('waiting-for-refresh-lock', ['lock_path' => $lockPath]);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('could not acquire cache lock');
            }

            Logger::setStage('refresh-lock-acquired', [
                'wait_seconds' => round(microtime(true) - $lockWaitStarted, 3),
            ]);

            // Another request may have refreshed the cache while this request waited.
            $entry = $this->cacheManager->load();

            if ($entry !== null && time() < $entry['expires_at']) {
                Logger::setStage('serving-cache-refreshed-by-other-request', [
                    'cache_age_seconds' => max(0, time() - $entry['fetched_at']),
                    'response_bytes' => $this->cacheManager->getEntryBytes($entry, $geometryMode),
                    'geometry_mode' => $geometryMode,
                ]);
                $this->serveCache($entry, 'HIT', $method, $geometryMode);
                return;
            }

            try {
                Logger::setStage('refresh-started');
                $representations = $this->fetchAndPrepare();

                Logger::setStage('writing-cache', [
                    'result_bytes' => strlen($representations['full']),
                    'point_result_bytes' => strlen($representations['point']),
                ]);

                $entry = $this->cacheManager->save($representations['full'], $representations['point']);
                unset($representations);

                Logger::setStage('refresh-complete', [
                    'response_bytes' => $entry['bytes'],
                    'point_response_bytes' => $entry['point_bytes'],
                    'etag' => $entry['etag'],
                    'point_etag' => $entry['point_etag'],
                ]);

                $this->serveCache($entry, 'MISS', $method, $geometryMode);
                return;
            } catch (\Throwable $e) {
                Logger::logException('GeoJSON proxy refresh failed', $e);
                Logger::setStage('refresh-failed', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $entry = $this->cacheManager->load();

                if ($entry !== null && time() < $entry['stale_until']) {
                    header('Warning: 110 - "Response is stale because source refresh failed"');
                    Logger::log('WARNING', 'serving stale cache after refresh failure');
                    $this->serveCache($entry, 'STALE', $method, $geometryMode);
                    return;
                }

                $this->sendError(502, 'could not fetch and filter valid GeoJSON', $e->getMessage());
                return;
            }
        } finally {
            Logger::log('INFO', 'releasing refresh lock');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Warm the cache (CLI)
     */
    private function warmCache(): void
    {
        $cacheDir = $this->config->getString('cache_dir');
        $lockPath = $cacheDir . '/refresh.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('could not open cache lock file');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('could not acquire cache lock');
            }

            Logger::setStage('cli-refresh-lock-acquired');
            $representations = $this->fetchAndPrepare();

            Logger::setStage('writing-cache', [
                'result_bytes' => strlen($representations['full']),
                'point_result_bytes' => strlen($representations['point']),
            ]);

            $entry = $this->cacheManager->save($representations['full'], $representations['point']);
            unset($representations);

            Logger::setStage('cli-warm-cache-complete', [
                'result_bytes' => $entry['bytes'],
                'point_result_bytes' => $entry['point_bytes'],
                'etag' => $entry['etag'],
                'point_etag' => $entry['point_etag'],
            ]);

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
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Fetch and prepare GeoJSON data
     *
     * @return array{full: string, point: string}
     */
    private function fetchAndPrepare(): array
    {
        $startedAt = microtime(true);

        Logger::setStage('downloading-source');
        $sourceDocument = $this->fetchGeoJsonDocument(
            $this->config->getString('source_url'),
            'source GeoJSON'
        );

        Logger::setStage('source-decoded', [
            'geojson_type' => $sourceDocument['type'] ?? null,
            'feature_count' => is_array($sourceDocument['features'] ?? null)
                ? count($sourceDocument['features'])
                : null,
        ]);

        Logger::log('INFO', 'transport-mode filter configured', [
            'enabled' => $this->config->getArray('allowed_transport_mode_types') !== [],
            'property' => $this->config->getString('transport_mode_property'),
            'allowed_values' => $this->config->getArray('allowed_transport_mode_types'),
            'match_semantics' => 'any',
            'case_sensitive' => true,
        ]);

        Logger::setStage('filtering-transport-modes');
        $attributeStatistics = FeatureFilter::filterByTransportMode(
            $sourceDocument,
            $this->config->getString('transport_mode_property'),
            $this->config->getArray('allowed_transport_mode_types')
        );
        Logger::setStage('transport-mode-filter-complete', $attributeStatistics);

        Logger::setStage('downloading-buffer');
        $bufferDocument = $this->fetchGeoJsonDocument(
            $this->config->getString('buffer_url'),
            'buffer GeoJSON'
        );

        Logger::setStage('buffer-decoded', [
            'geojson_type' => $bufferDocument['type'] ?? null,
        ]);

        $polygonCoordinates = GeometryExtractor::extractPolygonCoordinates($bufferDocument);
        unset($bufferDocument);

        if ($polygonCoordinates === []) {
            throw new RuntimeException('buffer GeoJSON contains no Polygon or MultiPolygon geometry');
        }

        Logger::setStage('spatial-filter-start');
        $statistics = SpatialFilter::filterByBufferPolygons(
            $sourceDocument,
            $polygonCoordinates,
            $attributeStatistics
        );
        unset($polygonCoordinates);

        Logger::setStage('spatial-filter-complete', $statistics);

        try {
            Logger::setStage('encoding-full-result', $statistics);
            $body = $this->encodeFeatureCollectionForApi($sourceDocument);

            if (strlen($body) > $this->config->getInt('max_bytes')) {
                throw new RuntimeException('filtered result exceeds MAX_BYTES');
            }

            Logger::setStage('building-point-representation', $statistics);
            $pointStatistics = $this->convertToPointRepresentationInPlace($sourceDocument);
            Logger::setStage('encoding-point-result', array_merge($statistics, $pointStatistics));

            $pointBody = $this->encodeFeatureCollectionForApi($sourceDocument);
        } catch (\JsonException $e) {
            throw new RuntimeException('could not encode filtered GeoJSON: ' . $e->getMessage(), 0, $e);
        }

        if (strlen($pointBody) > $this->config->getInt('max_bytes')) {
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

        Logger::log('INFO', 'GeoJSON filter representations prepared successfully', $summary);
        Logger::setStage('ready-to-cache', $summary);

        return [
            'full' => $body,
            'point' => $pointBody,
        ];
    }

    /**
     * Fetch and decode a GeoJSON document
     */
    private function fetchGeoJsonDocument(string $url, string $label): array
    {
        Logger::log('INFO', 'starting GeoJSON download', [
            'label' => $label,
            'url' => $url,
        ]);

        $downloaded = $this->fetcher->fetch($url);
        $downloadedBytes = strlen($downloaded);
        $gzip = GzipDetector::isGzip($downloaded);

        Logger::log('INFO', 'GeoJSON download completed', [
            'label' => $label,
            'downloaded_bytes' => $downloadedBytes,
            'gzip_payload' => $gzip,
        ]);

        if (strlen($downloaded) > $this->config->getInt('max_bytes')) {
            throw new RuntimeException($label . ' response exceeds MAX_BYTES');
        }

        if ($gzip) {
            Logger::log('INFO', 'decompressing gzip payload', [
                'label' => $label,
                'compressed_bytes' => $downloadedBytes,
            ]);

            $body = GzipDetector::decompress($downloaded);

            Logger::log('INFO', 'gzip payload decompressed', [
                'label' => $label,
                'compressed_bytes' => $downloadedBytes,
                'decompressed_bytes' => strlen($body),
            ]);
        } else {
            $body = $downloaded;
        }

        unset($downloaded);

        if (strlen($body) > $this->config->getInt('max_bytes')) {
            throw new RuntimeException($label . ' decompressed response exceeds MAX_BYTES');
        }

        Logger::log('INFO', 'decoding GeoJSON JSON', [
            'label' => $label,
            'json_bytes' => strlen($body),
        ]);

        $document = Parser::parse($body);
        unset($body);

        Logger::log('INFO', 'GeoJSON JSON decoded', [
            'label' => $label,
            'geojson_type' => $document['type'],
            'feature_count' => is_array($document['features'] ?? null)
                ? count($document['features'])
                : null,
        ]);

        return $document;
    }

    /**
     * Encode feature collection for API
     */
    private function encodeFeatureCollectionForApi(array &$document): string
    {
        if (($document['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('API result must be a GeoJSON FeatureCollection');
        }

        if (!is_array($document['features'] ?? null)) {
            throw new RuntimeException('API result FeatureCollection has no valid features array');
        }

        if ($document['features'] === []) {
            return EMPTY_FEATURE_COLLECTION_JSON;
        }

        return Parser::encode($document);
    }

    /**
     * Convert to point representation in place
     *
     * @return array{point_features: int, point_features_omitted: int}
     */
    private function convertToPointRepresentationInPlace(array &$document): array
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

            $point = Centroid::forGeometry($feature['geometry']);

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

    /**
     * Get requested geometry mode
     */
    private function getRequestedGeometryMode(): string
    {
        if (!array_key_exists('geometry', $_GET)) {
            return 'full';
        }

        $value = $_GET['geometry'];

        if (!is_string($value)) {
            $this->sendError(400, 'invalid geometry parameter', 'geometry must be a string');
            exit;
        }

        $value = strtolower(trim($value));

        if ($value === '' || $value === 'full') {
            return 'full';
        }

        if ($value === 'point' || $value === 'centroid') {
            return 'point';
        }

        $this->sendError(400, 'invalid geometry parameter', 'supported values are full, point, and centroid');
        exit;
    }

    /**
     * Serve cached response
     */
    private function serveCache(array $entry, string $cacheStatus, string $method, string $geometryMode): void
    {
        $pointMode = $geometryMode === 'point';
        $etag = $pointMode ? $entry['point_etag'] : $entry['etag'];
        $bytes = $this->cacheManager->getEntryBytes($entry, $geometryMode);
        $path = $this->cacheManager->getEntryPath($entry, $geometryMode);
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $notModified = $this->etagHeaderMatches($ifNoneMatch, $etag);
        $body = null;

        if (!$notModified && $method !== 'HEAD') {
            $body = file_get_contents($path);
            if ($body === false || strlen($body) !== $bytes) {
                $this->sendError(500, 'could not read cached GeoJSON');
                return;
            }
        }

        Logger::log('INFO', 'serving response', [
            'cache_status' => $cacheStatus,
            'method' => $method,
            'geometry_mode' => $geometryMode,
            'response_bytes' => $bytes,
            'etag' => $etag,
        ]);

        $this->setCORSHeaders();

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

    /**
     * Check if ETag header matches
     */
    private function etagHeaderMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set CORS headers
     */
    private function setCORSHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, If-None-Match');
    }

    /**
     * Send error response
     */
    private function sendError(int $status, string $message, ?string $details = null): void
    {
        $this->setCORSHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $payload = ['error' => $message];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Handle OPTIONS request
     */
    private function handleOptions(): void
    {
        $this->setCORSHeaders();
        http_response_code(204);
    }

    /**
     * Handle method not allowed
     */
    private function handleMethodNotAllowed(): void
    {
        $this->setCORSHeaders();
        header('Allow: GET, HEAD, OPTIONS');
        $this->sendError(405, 'method not allowed');
    }

    /**
     * Handle status endpoint
     */
    private function handleStatus(): void
    {
        $this->setCORSHeaders();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $status = null;
        $statusFile = $this->config->getString('status_file');
        if (is_file($statusFile)) {
            $raw = file_get_contents($statusFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $status = $decoded;
                }
            }
        }

        $meta = null;
        $cacheDir = $this->config->getString('cache_dir');
        $metaPath = $cacheDir . '/meta.json';
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
                'data_exists' => is_file($cacheDir . '/data.geojson'),
                'meta_exists' => is_file($metaPath),
                'metadata' => $meta,
            ],
            'log_file' => DEBUG_LOG_FILENAME,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get help text
     */
    private function getHelpText(): string
    {
        return <<<TEXT
uMap GeoJSON spatial filter proxy - PHP variant

Downloads a source GeoJSON and a buffer GeoJSON, decompresses gzip payloads,
keeps only source features intersecting a Polygon or MultiPolygon buffer,
caches the filtered result, and serves plain GeoJSON.

Configuration:

  Edit the constants in config/config.php:

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
  https://your-domain.example/public/index.php

  Point representation (centroid or robust fallback per feature):
  https://your-domain.example/public/index.php?geometry=point

Status endpoint:

  https://your-domain.example/public/index.php?status=1

  The normal endpoint remains GeoJSON-only. The status endpoint reports the
  current processing stage without interrupting the running refresh.

uMap configuration:

  Full geometry layer:
  URL:    https://your-domain.example/public/index.php
  Format: GeoJSON

  Symbol/point layer:
  URL:    https://your-domain.example/public/index.php?geometry=point
  Format: GeoJSON

CLI usage:

  php public/index.php --help
  php public/index.php --version
  php public/index.php --warm-cache

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
  Buffer polygons are processed one at a time.
  Both datasets must use the same coordinate reference system.
  The point representation is calculated in that coordinate plane.

TEXT;
    }
}
