<?php

declare(strict_types=1);

namespace GeoJsonProxy\Cache;

use RuntimeException;
use GeoJsonProxy\Config;

/**
 * Cache management for GeoJSON proxy
 */
final class Manager
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Load cache entry if valid
     *
     * @return array|null Cache entry or null if not found/expired
     */
    public function load(): ?array
    {
        $cacheDir = $this->config->getString('cache_dir');
        $metaPath = $cacheDir . '/meta.json';
        $dataPath = $cacheDir . '/data.geojson';
        $pointDataPath = $cacheDir . '/points.geojson';

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

        foreach ([ 'etag', 'point_etag', 'cache_key', 'fetched_at', 'expires_at', 'stale_until', 'bytes', 'point_bytes' ] as $key) {
            if (!array_key_exists($key, $meta)) {
                return null;
            }
        }

        if (!hash_equals($this->makeCacheKey(), (string) $meta['cache_key'])) {
            return null;
        }

        $bytes = filesize($dataPath);
        $pointBytes = filesize($pointDataPath);
        if ($bytes === false || $pointBytes === false || $bytes !== (int) $meta['bytes'] || $pointBytes !== (int) $meta['point_bytes']) {
            return null;
        }

        $etag = $this->makeFileETag($dataPath);
        $pointEtag = $this->makeFileETag($pointDataPath);
        if ($etag === null || $pointEtag === null || !hash_equals((string) $meta['etag'], $etag) || !hash_equals((string) $meta['point_etag'], $pointEtag)) {
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

    /**
     * Save cache entry
     *
     * @return array Saved cache entry
     */
    public function save(string $body, string $pointBody): array
    {
        $cacheDir = $this->config->getString('cache_dir');
        $now = time();
        $dataPath = $cacheDir . '/data.geojson';
        $pointDataPath = $cacheDir . '/points.geojson';
        $metaPath = $cacheDir . '/meta.json';

        $entry = [
            'data_path' => $dataPath,
            'point_data_path' => $pointDataPath,
            'bytes' => strlen($body),
            'point_bytes' => strlen($pointBody),
            'etag' => $this->makeETag($body),
            'point_etag' => $this->makeETag($pointBody),
            'fetched_at' => $now,
            'expires_at' => $now + $this->config->getInt('cache_ttl'),
            'stale_until' => $now + $this->config->getInt('cache_ttl') + $this->config->getInt('stale_ttl'),
        ];

        $suffix = '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $tmpData = $dataPath . $suffix;
        $tmpPointData = $pointDataPath . $suffix;
        $tmpMeta = $metaPath . $suffix;

        $meta = [
            'version' => VERSION,
            'cache_key' => $this->makeCacheKey(),
            'etag' => $entry['etag'],
            'point_etag' => $entry['point_etag'],
            'fetched_at' => $entry['fetched_at'],
            'expires_at' => $entry['expires_at'],
            'stale_until' => $entry['stale_until'],
            'bytes' => $entry['bytes'],
            'point_bytes' => $entry['point_bytes'],
        ];

        $metaJson = json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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

        return $entry;
    }

    /**
     * Get cache entry bytes for geometry mode
     */
    public function getEntryBytes(array $entry, string $geometryMode): int
    {
        return (int) ($geometryMode === 'point' ? $entry['point_bytes'] : $entry['bytes']);
    }

    /**
     * Get cache entry path for geometry mode
     */
    public function getEntryPath(array $entry, string $geometryMode): string
    {
        return (string) ($geometryMode === 'point' ? $entry['point_data_path'] : $entry['data_path']);
    }

    /**
     * Ensure cache directory exists and is writable
     */
    public function ensureCacheDir(): void
    {
        $cacheDir = $this->config->getString('cache_dir');
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                throw new RuntimeException('could not create cache directory: ' . $cacheDir);
            }
        }
        if (!is_writable($cacheDir)) {
            throw new RuntimeException('cache directory is not writable: ' . $cacheDir);
        }
    }

    /**
     * Make cache key from configuration
     */
    private function makeCacheKey(): string
    {
        return hash(
            'sha256',
            VERSION
            . "\n" . $this->config->getString('source_url')
            . "\n" . $this->config->getString('buffer_url')
            . "\n" . $this->config->getString('transport_mode_property')
            . "\n" . json_encode(
                $this->config->getArray('allowed_transport_mode_types'),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Make ETag from body content
     */
    private function makeETag(string $body): string
    {
        return '"' . hash('sha256', $body) . '"';
    }

    /**
     * Make file ETag from file path
     */
    private function makeFileETag(string $path): ?string
    {
        $hash = hash_file('sha256', $path);
        return $hash === false ? null : '"' . $hash . '"';
    }
}
