<?php

declare(strict_types=1);

namespace GeoJsonProxy\Http;

/**
 * Gzip detection utilities
 */
final class GzipDetector
{
    /**
     * Check if data is gzip compressed
     */
    public static function isGzip(string $data): bool
    {
        return strlen($data) >= 2
            && ord($data[0]) === 0x1f
            && ord($data[1]) === 0x8b;
    }

    /**
     * Decompress gzip data
     */
    public static function decompress(string $data): string
    {
        if (!function_exists('gzdecode')) {
            throw new \RuntimeException('PHP zlib support is required for gzip data');
        }

        $body = gzdecode($data);
        if ($body === false) {
            throw new \RuntimeException('could not decompress gzip payload');
        }

        return $body;
    }
}
