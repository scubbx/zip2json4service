<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

/**
 * Extract polygon coordinates from GeoJSON structures
 */
final class GeometryExtractor
{
    /**
     * Extract all polygon coordinates from a GeoJSON object
     *
     * @return array<int, array> List of polygon coordinate arrays
     */
    public static function extractPolygonCoordinates(array $object): array
    {
        $type = $object['type'] ?? null;
        $result = [];

        switch ($type) {
            case 'FeatureCollection':
                foreach ($object['features'] ?? [] as $feature) {
                    if (is_array($feature)) {
                        foreach (self::extractPolygonCoordinates($feature) as $polygon) {
                            $result[] = $polygon;
                        }
                    }
                }
                break;

            case 'Feature':
                if (is_array($object['geometry'] ?? null)) {
                    $result = self::extractPolygonCoordinates($object['geometry']);
                }
                break;

            case 'GeometryCollection':
                foreach ($object['geometries'] ?? [] as $geometry) {
                    if (is_array($geometry)) {
                        foreach (self::extractPolygonCoordinates($geometry) as $polygon) {
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
     * Get first point from a polygon
     */
    public static function firstPolygonPoint(array $polygon): ?array
    {
        foreach ($polygon as $ring) {
            if (!is_array($ring)) {
                continue;
            }

            foreach ($ring as $point) {
                if (is_array($point) && Point::isCoordinate($point)) {
                    return $point;
                }
            }
        }

        return null;
    }

    /**
     * Get first point from a line
     */
    public static function firstLinePoint(array $line): ?array
    {
        foreach ($line as $point) {
            if (is_array($point) && Point::isCoordinate($point)) {
                return $point;
            }
        }

        return null;
    }

    /**
     * Calculate bounding box for a geometry
     *
     * @return array{minX: float, minY: float, maxX: float, maxY: float}|null
     */
    public static function geometryBoundingBox(array $geometry): ?array
    {
        if (($geometry['type'] ?? null) === 'GeometryCollection') {
            $bbox = null;
            foreach ($geometry['geometries'] ?? [] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childBbox = self::geometryBoundingBox($child);
                if ($childBbox !== null) {
                    BoundingBox::merge($bbox, $childBbox);
                }
            }
            return $bbox;
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            return null;
        }

        return BoundingBox::fromCoordinates($coordinates);
    }
}
