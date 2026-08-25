<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use const GEO_EPSILON;

/**
 * Bounding box utilities
 */
final class BoundingBox
{
    /**
     * Calculate bounding box from coordinates
     *
     * @return array{minX: float, minY: float, maxX: float, maxY: float}|null
     */
    public static function fromCoordinates(array $coordinates): ?array
    {
        $bbox = null;
        self::expand($coordinates, $bbox);
        return $bbox;
    }

    /**
     * Expand bounding box with coordinates
     */
    public static function expand(array $coordinates, ?array &$bbox): void
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
                self::expand($child, $bbox);
            }
        }
    }

    /**
     * Check if two bounding boxes intersect
     */
    public static function intersect(array $a, array $b): bool
    {
        return !(
            $a['maxX'] < $b['minX']
            || $a['minX'] > $b['maxX']
            || $a['maxY'] < $b['minY']
            || $a['minY'] > $b['maxY']
        );
    }

    /**
     * Check if point is in bounding box
     */
    public static function containsPoint(array $point, array $bbox): bool
    {
        return (float) $point[0] >= $bbox['minX'] - GEO_EPSILON
            && (float) $point[0] <= $bbox['maxX'] + GEO_EPSILON
            && (float) $point[1] >= $bbox['minY'] - GEO_EPSILON
            && (float) $point[1] <= $bbox['maxY'] + GEO_EPSILON;
    }

    /**
     * Merge two bounding boxes
     */
    public static function merge(?array &$target, array $source): void
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
}
