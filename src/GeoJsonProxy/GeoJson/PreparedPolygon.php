<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use RuntimeException;
use GeoJsonProxy\SpatialIndex\GridIndex;
use GeoJsonProxy\SpatialIndex\PointIndex;

use const PACKED_ID_BYTES;
use const POINT_INDEX_MAX_REFERENCE_BYTES;
use const SPATIAL_INDEX_MAX_REFERENCE_BYTES;
use const COMPACT_SEGMENT_MAX_BYTES;
use const MEMORY_SAFETY_RESERVE_BYTES;

/**
 * Builds and queries a compact spatial index for one buffer polygon.
 */
final class PreparedPolygon
{
    /**
     * @return array|null Compact prepared polygon, or null for invalid input
     */
    public static function prepare(array $polygon, int $polygonIndex): ?array
    {
        if ($polygon === []) {
            return null;
        }

        $bbox = BoundingBox::fromCoordinates($polygon);
        if ($bbox === null) {
            return null;
        }

        $firstPoint = GeometryExtractor::firstPolygonPoint($polygon);
        $segments = '';
        $segmentCount = 0;
        $ringDescriptors = [];

        foreach ($polygon as $ring) {
            if (!is_array($ring) || $ring === []) {
                continue;
            }

            $ringBbox = BoundingBox::fromCoordinates($ring);
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
                    && self::appendPackedSegment($segments, $a, $b)
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
                    && Point::isCoordinate($first)
                    && Point::isCoordinate($last)
                    && !Point::equals($first, $last)
                    && self::appendPackedSegment($segments, $last, $first)
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

            $preparedRing = PointIndex::build(
                $segments,
                $descriptor,
                $availableForRing
            );
            $preparedRings[] = $preparedRing;
            $pointReferenceBudget -= $preparedRing['reference_count'];
            $pointReferenceCount += $preparedRing['reference_count'];
        }

        $boundaryIndex = GridIndex::build(
            $segments,
            $segmentCount,
            $bbox,
            intdiv(SPATIAL_INDEX_MAX_REFERENCE_BYTES, PACKED_ID_BYTES)
        );

        $compactIndexBytes = strlen($segments)
            + $pointReferenceCount * PACKED_ID_BYTES
            + $boundaryIndex['reference_count'] * PACKED_ID_BYTES;

        self::assertMemoryReserve($polygonIndex, $compactIndexBytes);

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

    public static function geometryIntersects(array $geometry, array $buffer): bool
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        switch ($type) {
            case 'Point':
                return is_array($coordinates)
                    && self::containsPoint($coordinates, $buffer);

            case 'LineString':
                return is_array($coordinates)
                    && self::lineStringIntersects($coordinates, $buffer);

            case 'Polygon':
                return is_array($coordinates)
                    && self::polygonIntersects($coordinates, $buffer);

            case 'MultiPoint':
                foreach (is_array($coordinates) ? $coordinates : [] as $point) {
                    if (is_array($point) && self::containsPoint($point, $buffer)) {
                        return true;
                    }
                }
                return false;

            case 'MultiLineString':
                foreach (is_array($coordinates) ? $coordinates : [] as $line) {
                    if (is_array($line) && self::lineStringIntersects($line, $buffer)) {
                        return true;
                    }
                }
                return false;

            case 'MultiPolygon':
                foreach (is_array($coordinates) ? $coordinates : [] as $polygon) {
                    if (is_array($polygon) && self::polygonIntersects($polygon, $buffer)) {
                        return true;
                    }
                }
                return false;

            case 'GeometryCollection':
                foreach ($geometry['geometries'] ?? [] as $child) {
                    if (is_array($child) && self::geometryIntersects($child, $buffer)) {
                        return true;
                    }
                }
                return false;
        }

        return false;
    }

    private static function containsPoint(array $point, array $polygon): bool
    {
        if (!Point::isCoordinate($point) || !BoundingBox::containsPoint($point, $polygon['bbox'])) {
            return false;
        }

        $rings = $polygon['rings'];
        if (
            !isset($rings[0])
            || !PointIndex::pointInPreparedRing(
                $point,
                $rings[0],
                $polygon['segments'],
                true
            )
        ) {
            return false;
        }

        for ($i = 1, $count = count($rings); $i < $count; $i++) {
            if (PointIndex::pointInPreparedRing(
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

    private static function lineStringIntersects(array $line, array $buffer): bool
    {
        $firstPoint = GeometryExtractor::firstLinePoint($line);
        if ($firstPoint === null) {
            return false;
        }

        if (self::containsPoint($firstPoint, $buffer)) {
            return true;
        }

        return self::lineBoundaryIntersects($line, $buffer);
    }

    private static function polygonIntersects(array $sourcePolygon, array $buffer): bool
    {
        if (self::polygonBoundaryIntersects($sourcePolygon, $buffer)) {
            return true;
        }

        $sourcePoint = GeometryExtractor::firstPolygonPoint($sourcePolygon);
        if ($sourcePoint !== null && self::containsPoint($sourcePoint, $buffer)) {
            return true;
        }

        $bufferPoint = $buffer['first_point'];
        return $bufferPoint !== null
            && SpatialFilter::pointInPolygon($bufferPoint, $sourcePolygon);
    }

    private static function lineBoundaryIntersects(array $line, array $buffer): bool
    {
        $count = count($line);

        for ($i = 0; $i + 1 < $count; $i++) {
            $a = $line[$i] ?? null;
            $b = $line[$i + 1] ?? null;

            if (
                is_array($a)
                && is_array($b)
                && Point::isCoordinate($a)
                && Point::isCoordinate($b)
                && GridIndex::segmentIntersectsGridIndex($a, $b, $buffer)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function polygonBoundaryIntersects(array $polygon, array $buffer): bool
    {
        foreach ($polygon as $ring) {
            if (is_array($ring) && self::ringBoundaryIntersects($ring, $buffer)) {
                return true;
            }
        }

        return false;
    }

    private static function ringBoundaryIntersects(array $ring, array $buffer): bool
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
                && GridIndex::segmentIntersectsGridIndex($a, $b, $buffer)
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
                && Point::isCoordinate($first)
                && Point::isCoordinate($last)
                && !Point::equals($first, $last)
                && GridIndex::segmentIntersectsGridIndex($last, $first, $buffer)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function appendPackedSegment(string &$segments, array $a, array $b): bool
    {
        if (!Point::isCoordinate($a) || !Point::isCoordinate($b)) {
            return false;
        }

        $segments .= Segment::pack($a, $b);
        if (strlen($segments) > COMPACT_SEGMENT_MAX_BYTES) {
            throw new RuntimeException(
                'compact buffer segment store exceeds COMPACT_SEGMENT_MAX_BYTES'
            );
        }

        return true;
    }

    private static function assertMemoryReserve(int $polygonIndex, int $indexBytes): void
    {
        $limit = self::phpMemoryLimitBytes();
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

    private static function phpMemoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return null;
        }

        if (!preg_match('/^(\d+)\s*([kmgt]?)b?$/i', $value, $matches)) {
            return null;
        }

        $multipliers = [
            '' => 1,
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
        ];

        return (int) $matches[1] * $multipliers[strtolower($matches[2])];
    }
}
