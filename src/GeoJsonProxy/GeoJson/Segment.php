<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use const GEO_EPSILON;
use const PACKED_SEGMENT_BYTES;

/**
 * Segment intersection utilities
 */
final class Segment
{
    /**
     * Check if two segments intersect
     */
    public static function intersect(array $a, array $b, array $c, array $d): bool
    {
        return self::intersectCoordinates(
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

    /**
     * Check if two segments intersect using coordinates
     */
    public static function intersectCoordinates(
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

        $o1 = Point::orientationCoordinates($ax, $ay, $bx, $by, $cx, $cy);
        $o2 = Point::orientationCoordinates($ax, $ay, $bx, $by, $dx, $dy);
        $o3 = Point::orientationCoordinates($cx, $cy, $dx, $dy, $ax, $ay);
        $o4 = Point::orientationCoordinates($cx, $cy, $dx, $dy, $bx, $by);

        if (Point::oppositeSigns($o1, $o2) && Point::oppositeSigns($o3, $o4)) {
            return true;
        }

        return (
            abs($o1) <= GEO_EPSILON
            && Point::onSegmentCoordinates($cx, $cy, $ax, $ay, $bx, $by)
        ) || (
            abs($o2) <= GEO_EPSILON
            && Point::onSegmentCoordinates($dx, $dy, $ax, $ay, $bx, $by)
        ) || (
            abs($o3) <= GEO_EPSILON
            && Point::onSegmentCoordinates($ax, $ay, $cx, $cy, $dx, $dy)
        ) || (
            abs($o4) <= GEO_EPSILON
            && Point::onSegmentCoordinates($bx, $by, $cx, $cy, $dx, $dy)
        );
    }

    /**
     * Pack segment coordinates into binary string
     */
    public static function pack(array $a, array $b): string
    {
        return pack(
            'd4',
            (float) $a[0],
            (float) $a[1],
            (float) $b[0],
            (float) $b[1]
        );
    }

    /**
     * Read packed segment from binary string
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function unpack(string $segments, int $id): array
    {
        $offset = $id * PACKED_SEGMENT_BYTES;
        if ($id < 0 || $offset + PACKED_SEGMENT_BYTES > strlen($segments)) {
            throw new \LogicException('packed segment ID is out of bounds');
        }

        $values = unpack('d4', $segments, $offset);
        if (!is_array($values) || count($values) !== 4) {
            throw new \LogicException('could not decode packed segment');
        }

        return [
            (float) $values[1],
            (float) $values[2],
            (float) $values[3],
            (float) $values[4],
        ];
    }

    /**
     * Get bounding box for a segment
     *
     * @return array{minX: float, minY: float, maxX: float, maxY: float}
     */
    public static function boundingBox(array $a, array $b): array
    {
        return [
            'minX' => min((float) $a[0], (float) $b[0]),
            'minY' => min((float) $a[1], (float) $b[1]),
            'maxX' => max((float) $a[0], (float) $b[0]),
            'maxY' => max((float) $a[1], (float) $b[1]),
        ];
    }
}
