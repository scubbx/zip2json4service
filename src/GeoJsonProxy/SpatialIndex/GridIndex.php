<?php

declare(strict_types=1);

namespace GeoJsonProxy\SpatialIndex;

use const GEO_EPSILON;
use const PACKED_ID_BYTES;
use const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL;
use const SPATIAL_INDEX_MAX_TOTAL_CELLS;
use const SPATIAL_INDEX_MAX_GRID_DIMENSION;
use const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT;
use const SPATIAL_INDEX_MAX_REFERENCE_BYTES;

use GeoJsonProxy\GeoJson\Segment;
use GeoJsonProxy\GeoJson\BoundingBox;

/**
 * Grid-based spatial index for segment intersection tests
 */
final class GridIndex
{
    /**
     * Build a compact grid index
     *
     * @param string $segments Packed segment data
     * @param int $segmentCount Number of segments
     * @param array $bbox Bounding box of all segments
     * @param int $maximumReferences Maximum number of references
     * @return array Index structure
     */
    public static function build(
        string $segments,
        int $segmentCount,
        array $bbox,
        int $maximumReferences
    ): array {
        if ($maximumReferences < $segmentCount) {
            throw new \RuntimeException(
                'compact grid-index reference budget is smaller than its minimum'
            );
        }

        $dimensions = self::calculateDimensions($bbox, $segmentCount);
        $columns = $dimensions['columns'];
        $rows = $dimensions['rows'];

        while (self::estimateReferences($segments, $segmentCount, $bbox, $columns, $rows) > $maximumReferences) {
            if ($columns === 1 && $rows === 1) {
                throw new \RuntimeException('compact grid-index budget could not be satisfied');
            }

            $columns = max(1, intdiv($columns + 1, 2));
            $rows = max(1, intdiv($rows + 1, 2));
        }

        $cells = [];
        $overflow = '';
        $referenceCount = 0;

        for ($id = 0; $id < $segmentCount; $id++) {
            [$ax, $ay, $bx, $by] = Segment::unpack($segments, $id);
            $segmentBbox = [
                'minX' => min($ax, $bx),
                'minY' => min($ay, $by),
                'maxX' => max($ax, $bx),
                'maxY' => max($ay, $by),
            ];

            [$minColumn, $maxColumn, $minRow, $maxRow] = self::gridRangeForBoundingBox(
                $segmentBbox,
                $bbox,
                $columns,
                $rows
            );

            $cellSpan = ($maxColumn - $minColumn + 1) * ($maxRow - $minRow + 1);

            if ($cellSpan > SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT) {
                $overflow .= pack('V', $id);
                $referenceCount++;
                continue;
            }

            for ($row = $minRow; $row <= $maxRow; $row++) {
                for ($column = $minColumn; $column <= $maxColumn; $column++) {
                    $key = $row * $columns + $column;
                    if (!isset($cells[$key])) {
                        $cells[$key] = '';
                    }
                    $cells[$key] .= pack('V', $id);
                    $referenceCount++;
                }
            }
        }

        return [
            'bbox' => $bbox,
            'columns' => $columns,
            'rows' => $rows,
            'cells' => $cells,
            'overflow' => $overflow,
            'reference_count' => $referenceCount,
        ];
    }

    /**
     * Estimate number of references needed
     */
    private static function estimateReferences(
        string $segments,
        int $segmentCount,
        array $bbox,
        int $columns,
        int $rows
    ): int {
        $references = 0;

        for ($id = 0; $id < $segmentCount; $id++) {
            [$ax, $ay, $bx, $by] = Segment::unpack($segments, $id);
            $segmentBbox = [
                'minX' => min($ax, $bx),
                'minY' => min($ay, $by),
                'maxX' => max($ax, $bx),
                'maxY' => max($ay, $by),
            ];

            [$minColumn, $maxColumn, $minRow, $maxRow] = self::gridRangeForBoundingBox(
                $segmentBbox,
                $bbox,
                $columns,
                $rows
            );

            $span = ($maxColumn - $minColumn + 1) * ($maxRow - $minRow + 1);
            $references += $span > SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT ? 1 : $span;
        }

        return $references;
    }

    /**
     * Calculate grid dimensions
     */
    private static function calculateDimensions(array $bbox, int $segmentCount): array
    {
        $width = $bbox['maxX'] - $bbox['minX'];
        $height = $bbox['maxY'] - $bbox['minY'];

        $targetCells = max(
            1,
            min(
                SPATIAL_INDEX_MAX_TOTAL_CELLS,
                (int) ceil($segmentCount / SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL)
            )
        );

        if ($width <= GEO_EPSILON && $height <= GEO_EPSILON) {
            return ['columns' => 1, 'rows' => 1];
        }

        if ($height <= GEO_EPSILON) {
            $columns = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, max(1, $targetCells));
            return ['columns' => $columns, 'rows' => 1];
        }

        if ($width <= GEO_EPSILON) {
            $rows = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, max(1, $targetCells));
            return ['columns' => 1, 'rows' => $rows];
        }

        $aspect = max(1.0 / 16.0, min(16.0, $width / $height));
        $columns = (int) max(1, round(sqrt($targetCells * $aspect)));
        $columns = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, $columns);
        $rows = (int) max(1, ceil($targetCells / $columns));
        $rows = min(SPATIAL_INDEX_MAX_GRID_DIMENSION, $rows);

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * Get grid range for bounding box
     */
    public static function gridRangeForBoundingBox(
        array $itemBbox,
        array $gridBbox,
        int $columns,
        int $rows
    ): array {
        return [
            self::gridColumnForX((float) $itemBbox['minX'], $gridBbox, $columns),
            self::gridColumnForX((float) $itemBbox['maxX'], $gridBbox, $columns),
            self::gridRowForY((float) $itemBbox['minY'], $gridBbox, $rows),
            self::gridRowForY((float) $itemBbox['maxY'], $gridBbox, $rows),
        ];
    }

    /**
     * Get grid column for X coordinate
     */
    public static function gridColumnForX(float $x, array $bbox, int $columns): int
    {
        if ($columns <= 1 || ($bbox['maxX'] - $bbox['minX']) <= GEO_EPSILON) {
            return 0;
        }

        $ratio = ($x - $bbox['minX']) / ($bbox['maxX'] - $bbox['minX']);
        $column = (int) floor($ratio * $columns);

        return max(0, min($columns - 1, $column));
    }

    /**
     * Get grid row for Y coordinate
     */
    public static function gridRowForY(float $y, array $bbox, int $rows): int
    {
        if ($rows <= 1 || ($bbox['maxY'] - $bbox['minY']) <= GEO_EPSILON) {
            return 0;
        }

        $ratio = ($y - $bbox['minY']) / ($bbox['maxY'] - $bbox['minY']);
        $row = (int) floor($ratio * $rows);

        return max(0, min($rows - 1, $row));
    }

    /**
     * Check if segment intersects grid index
     */
    public static function segmentIntersectsGridIndex(
        array $a,
        array $b,
        array $buffer
    ): bool {
        $index = $buffer['boundary_index'];
        $segments = $buffer['segments'];
        $segmentCount = (int) $buffer['segment_count'];
        $ax = (float) $a[0];
        $ay = (float) $a[1];
        $bx = (float) $b[0];
        $by = (float) $b[1];

        $segmentBbox = [
            'minX' => min($ax, $bx),
            'minY' => min($ay, $by),
            'maxX' => max($ax, $bx),
            'maxY' => max($ay, $by),
        ];

        if (!BoundingBox::intersect($segmentBbox, $index['bbox'])) {
            return false;
        }

        [$minColumn, $maxColumn, $minRow, $maxRow] = self::gridRangeForBoundingBox(
            $segmentBbox,
            $index['bbox'],
            $index['columns'],
            $index['rows']
        );

        $seen = str_repeat("\0", intdiv($segmentCount + 7, 8));

        if (self::packedCandidatesIntersectSourceSegment(
            $index['overflow'],
            $segments,
            $seen,
            $segmentBbox,
            $ax,
            $ay,
            $bx,
            $by
        )) {
            return true;
        }

        for ($row = $minRow; $row <= $maxRow; $row++) {
            for ($column = $minColumn; $column <= $maxColumn; $column++) {
                $key = $row * $index['columns'] + $column;
                if (self::packedCandidatesIntersectSourceSegment(
                    $index['cells'][$key] ?? '',
                    $segments,
                    $seen,
                    $segmentBbox,
                    $ax,
                    $ay,
                    $bx,
                    $by
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if packed candidates intersect source segment
     */
    private static function packedCandidatesIntersectSourceSegment(
        string $ids,
        string $segments,
        string &$seen,
        array $sourceBbox,
        float $ax,
        float $ay,
        float $bx,
        float $by
    ): bool {
        $length = strlen($ids);

        for ($offset = 0; $offset < $length; $offset += PACKED_ID_BYTES) {
            $id = PointIndex::readId($ids, $offset);
            if (self::packedBitSetTestAndAdd($seen, $id)) {
                continue;
            }

            [$cx, $cy, $dx, $dy] = Segment::unpack($segments, $id);

            if (
                max($cx, $dx) + GEO_EPSILON < $sourceBbox['minX']
                || min($cx, $dx) - GEO_EPSILON > $sourceBbox['maxX']
                || max($cy, $dy) + GEO_EPSILON < $sourceBbox['minY']
                || min($cy, $dy) - GEO_EPSILON > $sourceBbox['maxY']
            ) {
                continue;
            }

            if (Segment::intersectCoordinates($ax, $ay, $bx, $by, $cx, $cy, $dx, $dy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test and add to bitset
     * Returns true when the ID was already present
     */
    private static function packedBitSetTestAndAdd(string &$bits, int $id): bool
    {
        $byteIndex = intdiv($id, 8);
        $mask = 1 << ($id & 7);
        $byte = ord($bits[$byteIndex]);

        if (($byte & $mask) !== 0) {
            return true;
        }

        $bits[$byteIndex] = chr($byte | $mask);
        return false;
    }
}
