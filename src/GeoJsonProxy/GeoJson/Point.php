<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use const GEO_EPSILON;

/**
 * Point geometry utilities
 */
final class Point
{
    /**
     * Check if a value is a valid coordinate
     */
    public static function isCoordinate(array $value): bool
    {
        return count($value) >= 2
            && is_numeric($value[0])
            && is_numeric($value[1])
            && is_finite((float) $value[0])
            && is_finite((float) $value[1]);
    }

    /**
     * Check if two points are equal within epsilon
     */
    public static function equals(array $a, array $b): bool
    {
        return abs((float) $a[0] - (float) $b[0]) <= GEO_EPSILON
            && abs((float) $a[1] - (float) $b[1]) <= GEO_EPSILON;
    }

    /**
     * Check if point is on segment
     */
    public static function onSegment(array $point, array $a, array $b): bool
    {
        if (!self::isCoordinate($point) || !self::isCoordinate($a) || !self::isCoordinate($b)) {
            return false;
        }

        return self::onSegmentCoordinates(
            (float) $point[0],
            (float) $point[1],
            (float) $a[0],
            (float) $a[1],
            (float) $b[0],
            (float) $b[1]
        );
    }

    /**
     * Check if point is on segment using coordinates
     */
    public static function onSegmentCoordinates(
        float $px,
        float $py,
        float $ax,
        float $ay,
        float $bx,
        float $by
    ): bool {
        if (abs(self::orientationCoordinates($ax, $ay, $bx, $by, $px, $py)) > GEO_EPSILON) {
            return false;
        }

        return $px >= min($ax, $bx) - GEO_EPSILON
            && $px <= max($ax, $bx) + GEO_EPSILON
            && $py >= min($ay, $by) - GEO_EPSILON
            && $py <= max($ay, $by) + GEO_EPSILON;
    }

    /**
     * Calculate orientation value
     */
    public static function orientationCoordinates(
        float $ax,
        float $ay,
        float $bx,
        float $by,
        float $cx,
        float $cy
    ): float {
        return ($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax);
    }

    /**
     * Check if two values have opposite signs
     */
    public static function oppositeSigns(float $a, float $b): bool
    {
        return (
            $a > GEO_EPSILON && $b < -GEO_EPSILON
        ) || (
            $a < -GEO_EPSILON && $b > GEO_EPSILON
        );
    }
}
