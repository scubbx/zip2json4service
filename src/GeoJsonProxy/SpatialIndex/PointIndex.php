<?php

declare(strict_types=1);

namespace GeoJsonProxy\SpatialIndex;

use const GEO_EPSILON;
use const PACKED_ID_BYTES;
use const POINT_INDEX_TARGET_EDGES_PER_BUCKET;
use const POINT_INDEX_MAX_BUCKETS;
use const POINT_INDEX_MAX_BUCKETS_PER_EDGE;
use const POINT_INDEX_MAX_REFERENCE_BYTES;

use GeoJsonProxy\GeoJson\Segment;
use GeoJsonProxy\GeoJson\BoundingBox;
use GeoJsonProxy\GeoJson\Point;

/**
 * Point-in-polygon index using Y-buckets
 */
final class PointIndex
{
    /**
     * Build a compact point index for a ring
     *
     * @param string $segments Packed segment data
     * @param array $descriptor Ring descriptor
     * @param int $maximumReferences Maximum number of references
     * @return array Index structure
     */
    public static function build(
        string $segments,
        array $descriptor,
        int $maximumReferences
    ): array {
        $segmentCount = (int) $descriptor['segment_count'];
        if ($maximumReferences < $segmentCount) {
            throw new \RuntimeException(
                'compact point-index reference budget is smaller than its minimum'
            );
        }

        $bucketCount = self::calculateBucketCount($descriptor, $segmentCount);

        if (($descriptor['bbox']['maxY'] - $descriptor['bbox']['minY']) <= GEO_EPSILON) {
            $bucketCount = 1;
        }

        while (self::estimateReferences($segments, $descriptor, $bucketCount) > $maximumReferences) {
            if ($bucketCount === 1) {
                throw new \RuntimeException('compact point-index budget could not be satisfied');
            }
            $bucketCount = max(1, intdiv($bucketCount + 1, 2));
        }

        $buckets = [];
        $overflow = '';
        $referenceCount = 0;
        $start = (int) $descriptor['segment_start'];
        $end = $start + $segmentCount;

        for ($id = $start; $id < $end; $id++) {
            [$ax, $ay, $bx, $by] = Segment::unpack($segments, $id);
            $minBucket = self::bucketForY(min($ay, $by), $descriptor['bbox'], $bucketCount);
            $maxBucket = self::bucketForY(max($ay, $by), $descriptor['bbox'], $bucketCount);
            $span = $maxBucket - $minBucket + 1;

            if ($span > POINT_INDEX_MAX_BUCKETS_PER_EDGE) {
                $overflow .= pack('V', $id);
                $referenceCount++;
                continue;
            }

            for ($bucket = $minBucket; $bucket <= $maxBucket; $bucket++) {
                self::appendId($buckets, $bucket, $id);
                $referenceCount++;
            }
        }

        return [
            'bbox' => $descriptor['bbox'],
            'segment_start' => $start,
            'segment_count' => $segmentCount,
            'bucket_count' => $bucketCount,
            'buckets' => $buckets,
            'overflow' => $overflow,
            'reference_count' => $referenceCount,
        ];
    }

    /**
     * Estimate number of references needed
     */
    private static function estimateReferences(
        string $segments,
        array $descriptor,
        int $bucketCount
    ): int {
        $references = 0;
        $start = (int) $descriptor['segment_start'];
        $end = $start + (int) $descriptor['segment_count'];

        for ($id = $start; $id < $end; $id++) {
            [, $ay, , $by] = Segment::unpack($segments, $id);
            $minBucket = self::bucketForY(min($ay, $by), $descriptor['bbox'], $bucketCount);
            $maxBucket = self::bucketForY(max($ay, $by), $descriptor['bbox'], $bucketCount);
            $span = $maxBucket - $minBucket + 1;
            $references += $span > POINT_INDEX_MAX_BUCKETS_PER_EDGE ? 1 : $span;
        }

        return $references;
    }

    /**
     * Calculate optimal bucket count
     */
    private static function calculateBucketCount(array $descriptor, int $segmentCount): int
    {
        return max(
            1,
            min(
                POINT_INDEX_MAX_BUCKETS,
                (int) ceil($segmentCount / POINT_INDEX_TARGET_EDGES_PER_BUCKET)
            )
        );
    }

    /**
     * Get bucket index for Y coordinate
     */
    public static function bucketForY(float $y, array $bbox, int $bucketCount): int
    {
        if ($bucketCount <= 1 || ($bbox['maxY'] - $bbox['minY']) <= GEO_EPSILON) {
            return 0;
        }

        $ratio = ($y - $bbox['minY']) / ($bbox['maxY'] - $bbox['minY']);
        $bucket = (int) floor($ratio * $bucketCount);

        return max(0, min($bucketCount - 1, $bucket));
    }

    /**
     * Append packed ID to bucket list
     */
    private static function appendId(array &$lists, int $key, int $id): void
    {
        $packed = pack('V', $id);
        if (isset($lists[$key])) {
            $lists[$key] .= $packed;
        } else {
            $lists[$key] = $packed;
        }
    }

    /**
     * Read packed ID at offset
     */
    public static function readId(string $ids, int $offset): int
    {
        return ord($ids[$offset])
            | (ord($ids[$offset + 1]) << 8)
            | (ord($ids[$offset + 2]) << 16)
            | (ord($ids[$offset + 3]) << 24);
    }

    /**
     * Test if point is in prepared ring
     */
    public static function pointInPreparedRing(
        array $point,
        array $ring,
        string $segments,
        bool $boundaryCountsAsInside
    ): bool {
        if (!BoundingBox::containsPoint($point, $ring['bbox'])) {
            return false;
        }

        $bucket = self::bucketForY((float) $point[1], $ring['bbox'], $ring['bucket_count']);
        $inside = false;

        if (self::evaluatePointAgainstPackedSegments($point, $ring['buckets'][$bucket] ?? '', $segments, $inside)) {
            return $boundaryCountsAsInside;
        }

        if (self::evaluatePointAgainstPackedSegments($point, $ring['overflow'], $segments, $inside)) {
            return $boundaryCountsAsInside;
        }

        return $inside;
    }

    /**
     * Evaluate point against packed segments
     * Toggles $inside for ray crossings and returns true when point lies on a boundary segment
     */
    public static function evaluatePointAgainstPackedSegments(
        array $point,
        string $ids,
        string $segments,
        bool &$inside
    ): bool {
        $px = (float) $point[0];
        $py = (float) $point[1];
        $length = strlen($ids);

        for ($offset = 0; $offset < $length; $offset += PACKED_ID_BYTES) {
            $id = self::readId($ids, $offset);
            [$ax, $ay, $bx, $by] = Segment::unpack($segments, $id);

            if (self::pointOnSegmentCoordinates($px, $py, $ax, $ay, $bx, $by)) {
                return true;
            }

            $crosses = ($by > $py) !== ($ay > $py);
            if (!$crosses) {
                continue;
            }

            $intersectionX = ($ax - $bx) * ($py - $by) / ($ay - $by) + $bx;
            if ($px < $intersectionX) {
                $inside = !$inside;
            }
        }

        return false;
    }

    /**
     * Check if point is on segment
     */
    private static function pointOnSegmentCoordinates(
        float $px,
        float $py,
        float $ax,
        float $ay,
        float $bx,
        float $by
    ): bool {
        // Check if point is colinear with segment
        $orientation = ($bx - $ax) * ($py - $ay) - ($by - $ay) * ($px - $ax);
        if (abs($orientation) > GEO_EPSILON) {
            return false;
        }

        // Check if point is within segment bounds
        return $px >= min($ax, $bx) - GEO_EPSILON
            && $px <= max($ax, $bx) + GEO_EPSILON
            && $py >= min($ay, $by) - GEO_EPSILON
            && $py <= max($ay, $by) + GEO_EPSILON;
    }
}
