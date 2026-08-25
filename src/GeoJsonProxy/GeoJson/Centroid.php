<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use const GEO_EPSILON;

/**
 * Centroid calculation utilities for various geometry types
 */
final class Centroid
{
    /**
     * Calculate representative point for a geometry
     *
     * @return array{0: float, 1: float}|null
     */
    public static function forGeometry(array $geometry): ?array
    {
        $component = self::centroidComponent($geometry);

        if ($component === null || $component['weight'] <= GEO_EPSILON) {
            return null;
        }

        $x = $component['weighted_x'] / $component['weight'];
        $y = $component['weighted_y'] / $component['weight'];

        if (!is_finite($x) || !is_finite($y)) {
            return null;
        }

        return [$x, $y];
    }

    /**
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function centroidComponent(array $geometry): ?array
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        switch ($type) {
            case 'Point':
                return is_array($coordinates) && Point::isCoordinate($coordinates)
                    ? self::pointCentroidComponent([$coordinates])
                    : null;

            case 'MultiPoint':
                return is_array($coordinates)
                    ? self::pointCentroidComponent($coordinates)
                    : null;

            case 'LineString':
                return is_array($coordinates)
                    ? self::lineCentroidComponent($coordinates)
                    : null;

            case 'MultiLineString':
                $components = [];
                foreach (is_array($coordinates) ? $coordinates : [] as $line) {
                    if (is_array($line)) {
                        $components[] = self::lineCentroidComponent($line);
                    }
                }
                return self::combineCentroidComponents($components);

            case 'Polygon':
                return is_array($coordinates)
                    ? self::polygonCentroidComponent($coordinates)
                    : null;

            case 'MultiPolygon':
                $components = [];
                foreach (is_array($coordinates) ? $coordinates : [] as $polygon) {
                    if (is_array($polygon)) {
                        $components[] = self::polygonCentroidComponent($polygon);
                    }
                }
                return self::combineCentroidComponents($components);

            case 'GeometryCollection':
                $components = [];
                foreach ($geometry['geometries'] ?? [] as $child) {
                    if (is_array($child)) {
                        $components[] = self::centroidComponent($child);
                    }
                }
                return self::combineCentroidComponents($components);
        }

        return null;
    }

    /**
     * @param array<int, array|null> $components
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function combineCentroidComponents(array $components): ?array
    {
        $dimension = null;
        $weight = 0.0;
        $weightedX = 0.0;
        $weightedY = 0.0;

        foreach ($components as $component) {
            if (!is_array($component) || $component['weight'] <= GEO_EPSILON) {
                continue;
            }

            if ($dimension === null || $component['dimension'] > $dimension) {
                $dimension = $component['dimension'];
                $weight = 0.0;
                $weightedX = 0.0;
                $weightedY = 0.0;
            }

            if ($component['dimension'] !== $dimension) {
                continue;
            }

            $weight += $component['weight'];
            $weightedX += $component['weighted_x'];
            $weightedY += $component['weighted_y'];
        }

        if ($dimension === null || $weight <= GEO_EPSILON) {
            return null;
        }

        return [
            'dimension' => $dimension,
            'weight' => $weight,
            'weighted_x' => $weightedX,
            'weighted_y' => $weightedY,
        ];
    }

    /**
     * @param array<int, array> $points
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function pointCentroidComponent(array $points): ?array
    {
        $count = 0;
        $sumX = 0.0;
        $sumY = 0.0;

        foreach ($points as $point) {
            if (!is_array($point) || !Point::isCoordinate($point)) {
                continue;
            }

            $sumX += (float) $point[0];
            $sumY += (float) $point[1];
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return [
            'dimension' => 0,
            'weight' => (float) $count,
            'weighted_x' => $sumX,
            'weighted_y' => $sumY,
        ];
    }

    /**
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function lineCentroidComponent(array $line): ?array
    {
        $length = 0.0;
        $weightedX = 0.0;
        $weightedY = 0.0;
        $pointCount = 0;
        $pointSumX = 0.0;
        $pointSumY = 0.0;
        $count = count($line);

        for ($i = 0; $i < $count; $i++) {
            $point = $line[$i] ?? null;
            if (is_array($point) && Point::isCoordinate($point)) {
                $pointSumX += (float) $point[0];
                $pointSumY += (float) $point[1];
                $pointCount++;
            }
        }

        for ($i = 0; $i + 1 < $count; $i++) {
            $a = $line[$i] ?? null;
            $b = $line[$i + 1] ?? null;

            if (!is_array($a) || !is_array($b) || !Point::isCoordinate($a) || !Point::isCoordinate($b)) {
                continue;
            }

            $dx = (float) $b[0] - (float) $a[0];
            $dy = (float) $b[1] - (float) $a[1];
            $segmentLength = hypot($dx, $dy);

            if ($segmentLength <= GEO_EPSILON) {
                continue;
            }

            $length += $segmentLength;
            $weightedX += (((float) $a[0] + (float) $b[0]) / 2.0) * $segmentLength;
            $weightedY += (((float) $a[1] + (float) $b[1]) / 2.0) * $segmentLength;
        }

        if ($length > GEO_EPSILON) {
            return [
                'dimension' => 1,
                'weight' => $length,
                'weighted_x' => $weightedX,
                'weighted_y' => $weightedY,
            ];
        }

        if ($pointCount === 0) {
            return null;
        }

        return [
            'dimension' => 0,
            'weight' => (float) $pointCount,
            'weighted_x' => $pointSumX,
            'weighted_y' => $pointSumY,
        ];
    }

    /**
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function polygonCentroidComponent(array $polygon): ?array
    {
        $area = 0.0;
        $weightedX = 0.0;
        $weightedY = 0.0;
        $lineComponents = [];

        foreach ($polygon as $ringIndex => $ring) {
            if (!is_array($ring)) {
                continue;
            }

            $lineComponents[] = self::lineCentroidComponent($ring);
            $ringComponent = self::ringAreaCentroidComponent($ring);

            if ($ringComponent === null) {
                continue;
            }

            $sign = $ringIndex === 0 ? 1.0 : -1.0;
            $area += $sign * $ringComponent['weight'];
            $weightedX += $sign * $ringComponent['weighted_x'];
            $weightedY += $sign * $ringComponent['weighted_y'];
        }

        if ($area > GEO_EPSILON) {
            return [
                'dimension' => 2,
                'weight' => $area,
                'weighted_x' => $weightedX,
                'weighted_y' => $weightedY,
            ];
        }

        return self::combineCentroidComponents($lineComponents);
    }

    /**
     * Returns an orientation-independent area centroid for one ring.
     *
     * @return array{dimension: int, weight: float, weighted_x: float, weighted_y: float}|null
     */
    private static function ringAreaCentroidComponent(array $ring): ?array
    {
        $areaTwice = 0.0;
        $centroidNumeratorX = 0.0;
        $centroidNumeratorY = 0.0;
        $first = null;
        $previous = null;
        $validPointCount = 0;

        foreach ($ring as $point) {
            if (!is_array($point) || !Point::isCoordinate($point)) {
                continue;
            }

            $current = [(float) $point[0], (float) $point[1]];
            if ($first === null) {
                $first = $current;
            }

            if ($previous !== null) {
                $cross = $previous[0] * $current[1]
                    - $current[0] * $previous[1];
                $areaTwice += $cross;
                $centroidNumeratorX += ($previous[0] + $current[0]) * $cross;
                $centroidNumeratorY += ($previous[1] + $current[1]) * $cross;
            }

            $previous = $current;
            $validPointCount++;
        }

        if ($validPointCount < 3 || $first === null || $previous === null) {
            return null;
        }

        $cross = $previous[0] * $first[1] - $first[0] * $previous[1];
        if (abs($cross) > 0.0) {
            $areaTwice += $cross;
            $centroidNumeratorX += ($previous[0] + $first[0]) * $cross;
            $centroidNumeratorY += ($previous[1] + $first[1]) * $cross;
        }

        if (abs($areaTwice) <= GEO_EPSILON) {
            return null;
        }

        $centroidX = $centroidNumeratorX / (3.0 * $areaTwice);
        $centroidY = $centroidNumeratorY / (3.0 * $areaTwice);
        $area = abs($areaTwice) / 2.0;

        if (!is_finite($centroidX) || !is_finite($centroidY)) {
            return null;
        }

        return [
            'dimension' => 2,
            'weight' => $area,
            'weighted_x' => $centroidX * $area,
            'weighted_y' => $centroidY * $area,
        ];
    }
}
