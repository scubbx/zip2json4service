<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use RuntimeException;
use JsonException;

/**
 * GeoJSON parsing utilities
 */
final class Parser
{
    /**
     * Parse GeoJSON from string
     */
    public static function parse(string $json): array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('does not contain valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($document)) {
            throw new RuntimeException('does not contain a GeoJSON object');
        }

        if (!is_string($document['type'] ?? null)) {
            throw new RuntimeException('has no valid GeoJSON type');
        }

        return $document;
    }

    /**
     * Encode GeoJSON to string
     */
    public static function encode(array $document): string
    {
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('could not encode GeoJSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Encode with pretty print
     */
    public static function encodePretty(array $document): string
    {
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('could not encode GeoJSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
