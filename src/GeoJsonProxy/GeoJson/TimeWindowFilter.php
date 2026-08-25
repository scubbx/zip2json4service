<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Filters FeatureCollections by overlap with a requested time window.
 */
final class TimeWindowFilter
{
    /**
     * Parse an ISO-8601-like timestamp into Unix microseconds.
     *
     * Date-only and timezone-less values are interpreted as UTC so filtering
     * remains independent of the server's configured timezone.
     */
    public static function parseTimestamp(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || !preg_match(
            '/^\d{4}-\d{2}-\d{2}(?:[Tt ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:[Zz]|[+-]\d{2}:?\d{2})?)?$/D',
            $value
        )) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return ((int) $date->format('U')) * 1_000_000 + (int) $date->format('u');
    }

    /**
     * Retain features whose own interval overlaps the requested interval.
     *
     * @return array{
     *   source_features: int,
     *   retained_features: int,
     *   rejected_features: int,
     *   invalid_or_missing_time_features: int
     * }
     */
    public static function filterFeatureCollection(
        array &$document,
        string $startProperty,
        string $endProperty,
        ?int $from,
        ?int $until
    ): array {
        if (($document['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('time filtering requires a FeatureCollection');
        }

        if (!is_array($document['features'] ?? null)) {
            throw new RuntimeException('time filtering requires a features array');
        }

        if ($from === null && $until === null) {
            throw new RuntimeException('time filtering requires at least one criterion');
        }

        $features =& $document['features'];
        $total = count($features);
        $writeIndex = 0;
        $invalid = 0;

        for ($readIndex = 0; $readIndex < $total; $readIndex++) {
            $feature = $features[$readIndex] ?? null;
            $properties = is_array($feature) && is_array($feature['properties'] ?? null)
                ? $feature['properties']
                : null;
            $matches = $properties !== null;

            if (!$matches) {
                $invalid++;
            }

            if ($matches && $from !== null) {
                $featureEnd = is_string($properties[$endProperty] ?? null)
                    ? self::parseTimestamp($properties[$endProperty])
                    : null;

                if ($featureEnd === null) {
                    $invalid++;
                    $matches = false;
                } elseif ($featureEnd < $from) {
                    $matches = false;
                }
            }

            if ($matches && $until !== null) {
                $featureStart = is_string($properties[$startProperty] ?? null)
                    ? self::parseTimestamp($properties[$startProperty])
                    : null;

                if ($featureStart === null) {
                    $invalid++;
                    $matches = false;
                } elseif ($featureStart > $until) {
                    $matches = false;
                }
            }

            if (!$matches) {
                unset($features[$readIndex]);
                continue;
            }

            $features[$writeIndex] = $feature;
            if ($writeIndex !== $readIndex) {
                unset($features[$readIndex]);
            }
            $writeIndex++;
        }

        unset($document['bbox']);

        return [
            'source_features' => $total,
            'retained_features' => $writeIndex,
            'rejected_features' => $total - $writeIndex,
            'invalid_or_missing_time_features' => $invalid,
        ];
    }
}
