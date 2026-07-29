<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use RuntimeException;
use GeoJsonProxy\GeoJson\BoundingBox;
use GeoJsonProxy\GeoJson\GeometryExtractor;
use GeoJsonProxy\GeoJson\Point;
use GeoJsonProxy\GeoJson\Segment;

/**
 * Spatial filtering utilities
 */
final class SpatialFilter
{
    /**
     * Filter features by buffer polygons (simplified version)
     *
     * @param array &$source Source GeoJSON document
     * @param array $polygonCoordinates List of polygon coordinates
     * @param array $attributeStatistics Statistics from attribute filtering
     * @return array Statistics about spatial filtering
     */
    public static function filterByBufferPolygons(
        array &$source,
        array $polygonCoordinates,
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

        // For now, use a simple bounding box intersection check
        // This is a simplified version - the full implementation would use spatial indexes
        $bufferBboxes = [];
        foreach ($polygonCoordinates as $polygon) {
            $bbox = BoundingBox::fromCoordinates($polygon);
            if ($bbox !== null) {
                $bufferBboxes[] = $bbox;
            }
        }

        if ($bufferBboxes === []) {
            throw new RuntimeException('buffer GeoJSON contains no valid Polygon or MultiPolygon geometry');
        }

        $matched = [];
        $matchedCount = 0;

        foreach ($features as $featureIndex => $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (!is_array($geometry)) {
                continue;
            }

            $featureBbox = GeometryExtractor::geometryBoundingBox($geometry);
            if ($featureBbox === null) {
                continue;
            }

            // Check if feature bbox intersects any buffer bbox
            foreach ($bufferBboxes as $bufferBbox) {
                if (BoundingBox::intersect($featureBbox, $bufferBbox)) {
                    $matched[$featureIndex] = true;
                    $matchedCount++;
                    break;
                }
            }
        }

        // Compact the features array
        $writeIndex = 0;
        for ($readIndex = 0; $readIndex < $candidateCount; $readIndex++) {
            if (!isset($matched[$readIndex])) {
                unset($features[$readIndex]);
                continue;
            }

            if ($writeIndex !== $readIndex) {
                $features[$writeIndex] = $features[$readIndex];
                unset($features[$readIndex]);
            }

            $writeIndex++;
        }

        unset($features[$writeIndex] ?? null);

        // Remove source-level bbox as it may no longer be valid
        unset($source['bbox']);

        return array_merge($attributeStatistics, [
            'retained_features' => $writeIndex,
            'spatially_rejected_features' => $candidateCount - $writeIndex,
            'buffer_polygons' => count($bufferBboxes),
        ]);
    }

    /**
     * Check if point is in polygon (simplified version)
     */
    public static function pointInPolygon(array $point, array $polygon): bool
    {
        if (!Point::isCoordinate($point) || !isset($polygon[0]) || !is_array($polygon[0])) {
            return false;
        }

        if (!self::pointInRing($point, $polygon[0], true)) {
            return false;
        }

        // Check holes
        for ($i = 1, $count = count($polygon); $i < $count; $i++) {
            if (is_array($polygon[$i]) && self::pointInRing($point, $polygon[$i], false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if point is in ring
     */
    private static function pointInRing(array $point, array $ring, bool $boundaryCountsAsInside): bool
    {
        $count = count($ring);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $a = $ring[$j] ?? null;
            $b = $ring[$i] ?? null;

            if (!is_array($a) || !is_array($b) || !Point::isCoordinate($a) || !Point::isCoordinate($b)) {
                continue;
            }

            if (Point::onSegment($point, $a, $b)) {
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
     * Check if line string intersects polygon
     */
    public static function lineStringIntersectsPolygon(array $line, array $polygon): bool
    {
        $firstPoint = GeometryExtractor::firstLinePoint($line);

        if ($firstPoint === null) {
            return false;
        }

        // For a connected line it is sufficient to test one point for containment
        if (self::pointInPolygon($firstPoint, $polygon)) {
            return true;
        }

        // Check if any segment of the line intersects any segment of the polygon
        return self::lineBoundaryIntersectsPolygon($line, $polygon);
    }

    /**
     * Check if line boundary intersects polygon
     */
    private static function lineBoundaryIntersectsPolygon(array $line, array $polygon): bool
    {
        $count = count($line);

        for ($i = 0; $i + 1 < $count; $i++) {
            $a = $line[$i] ?? null;
            $b = $line[$i + 1] ?? null;

            if (!is_array($a) || !is_array($b) || !Point::isCoordinate($a) || !Point::isCoordinate($b)) {
                continue;
            }

            if (self::segmentIntersectsPolygon($a, $b, $polygon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if segment intersects polygon
     */
    private static function segmentIntersectsPolygon(array $a, array $b, array $polygon): bool
    {
        foreach ($polygon as $ring) {
            if (!is_array($ring)) {
                continue;
            }

            if (self::segmentIntersectsRing($a, $b, $ring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if segment intersects ring
     */
    private static function segmentIntersectsRing(array $a, array $b, array $ring): bool
    {
        $count = count($ring);

        for ($i = 0; $i + 1 < $count; $i++) {
            $c = $ring[$i] ?? null;
            $d = $ring[$i + 1] ?? null;

            if (
                is_array($c)
                && is_array($d)
                && Point::isCoordinate($c)
                && Point::isCoordinate($d)
                && Segment::intersect($a, $b, $c, $d)
            ) {
                return true;
            }
        }

        // Check closing segment
        if ($count >= 3) {
            $first = $ring[0] ?? null;
            $last = $ring[$count - 1] ?? null;

            if (
                is_array($first)
                && is_array($last)
                && Point::isCoordinate($first)
                && Point::isCoordinate($last)
                && !Point::equals($first, $last)
                && Segment::intersect($a, $b, $last, $first)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if polygon intersects polygon
     */
    public static function polygonIntersectsPolygon(array $sourcePolygon, array $bufferPolygon): bool
    {
        // Check if boundaries intersect
        if (self::polygonBoundaryIntersectsPolygon($sourcePolygon, $bufferPolygon)) {
            return true;
        }

        // Check if first point of source is in buffer
        $sourcePoint = GeometryExtractor::firstPolygonPoint($sourcePolygon);
        if ($sourcePoint !== null && self::pointInPolygon($sourcePoint, $bufferPolygon)) {
            return true;
        }

        // Check if first point of buffer is in source
        $bufferPoint = GeometryExtractor::firstPolygonPoint($bufferPolygon);
        return $bufferPoint !== null && self::pointInPolygon($bufferPoint, $sourcePolygon);
    }

    /**
     * Check if polygon boundary intersects polygon
     */
    private static function polygonBoundaryIntersectsPolygon(array $sourcePolygon, array $bufferPolygon): bool
    {
        foreach ($sourcePolygon as $ring) {
            if (!is_array($ring)) {
                continue;
            }

            if (self::ringBoundaryIntersectsPolygon($ring, $bufferPolygon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if ring boundary intersects polygon
     */
    private static function ringBoundaryIntersectsPolygon(array $ring, array $polygon): bool
    {
        $count = count($ring);

        for ($i = 0; $i + 1 < $count; $i++) {
            $a = $ring[$i] ?? null;
            $b = $ring[$i + 1] ?? null;

            if (
                is_array($a)
                && is_array($b)
                && Point::isCoordinate($a)
                && Point::isCoordinate($b)
                && self::segmentIntersectsPolygon($a, $b, $polygon)
            ) {
                return true;
            }
        }

        // Check closing segment
        if ($count >= 3) {
            $first = $ring[0] ?? null;
            $last = $ring[$count - 1] ?? null;

            if (
                is_array($first)
                && is_array($last)
                && Point::isCoordinate($first)
                && Point::isCoordinate($last)
                && !Point::equals($first, $last)
                && self::segmentIntersectsPolygon($last, $first, $polygon)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if geometry intersects polygon
     */
    public static function geometryIntersectsPolygon(array $geometry, array $polygon): bool
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        switch ($type) {
            case 'Point':
                return is_array($coordinates) && self::pointInPolygon($coordinates, $polygon);

            case 'LineString':
                return is_array($coordinates) && self::lineStringIntersectsPolygon($coordinates, $polygon);

            case 'Polygon':
                return is_array($coordinates) && self::polygonIntersectsPolygon($coordinates, $polygon);

            case 'MultiPoint':
                foreach (is_array($coordinates) ? $coordinates : [] as $point) {
                    if (is_array($point) && self::pointInPolygon($point, $polygon)) {
                        return true;
                    }
                }
                return false;

            case 'MultiLineString':
                foreach (is_array($coordinates) ? $coordinates : [] as $line) {
                    if (is_array($line) && self::lineStringIntersectsPolygon($line, $polygon)) {
                        return true;
                    }
                }
                return false;

            case 'MultiPolygon':
                foreach (is_array($coordinates) ? $coordinates : [] as $poly) {
                    if (is_array($poly) && self::polygonIntersectsPolygon($poly, $polygon)) {
                        return true;
                    }
                }
                return false;

            case 'GeometryCollection':
                foreach ($geometry['geometries'] ?? [] as $child) {
                    if (is_array($child) && self::geometryIntersectsPolygon($child, $polygon)) {
                        return true;
                    }
                }
                return false;
        }

        return false;
    }
}
