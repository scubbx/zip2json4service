<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Filters FeatureCollections by request-specific time criteria.
 */
final class TimeWindowFilter
{
    private const MICROSECONDS_PER_DAY = 86_400_000_000;

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
     * Parse a non-negative number of days into microseconds.
     *
     * Up to six decimal places are accepted and converted without floating
     * point rounding.
     */
    public static function parseDurationDays(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/D', $value, $matches)) {
            return null;
        }

        $wholeDigits = ltrim($matches[1], '0');
        $wholeDigits = $wholeDigits === '' ? '0' : $wholeDigits;
        $maximumWholeDays = intdiv(PHP_INT_MAX, self::MICROSECONDS_PER_DAY);
        $maximumWholeDaysString = (string) $maximumWholeDays;

        if (strlen($wholeDigits) > strlen($maximumWholeDaysString)
            || (strlen($wholeDigits) === strlen($maximumWholeDaysString)
                && strcmp($wholeDigits, $maximumWholeDaysString) > 0)
        ) {
            return null;
        }

        $wholeMicroseconds = ((int) $wholeDigits) * self::MICROSECONDS_PER_DAY;
        $fractionDigits = $matches[2] ?? '';
        $fractionMillionths = $fractionDigits === ''
            ? 0
            : (int) str_pad($fractionDigits, 6, '0');
        $fractionMicroseconds = $fractionMillionths * 86_400;

        if ($wholeMicroseconds > PHP_INT_MAX - $fractionMicroseconds) {
            return null;
        }

        return $wholeMicroseconds + $fractionMicroseconds;
    }

    /**
     * Retain features whose interval matches all requested time criteria.
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
        ?int $until,
        ?int $minimumDuration
    ): array {
        if (($document['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('time filtering requires a FeatureCollection');
        }

        if (!is_array($document['features'] ?? null)) {
            throw new RuntimeException('time filtering requires a features array');
        }

        if ($from === null && $until === null && $minimumDuration === null) {
            throw new RuntimeException('time filtering requires at least one criterion');
        }

        $features =& $document['features'];
        $total = count($features);
        $writeIndex = 0;
        $invalid = 0;
        $needsStart = $until !== null || $minimumDuration !== null;
        $needsEnd = $from !== null || $minimumDuration !== null;

        for ($readIndex = 0; $readIndex < $total; $readIndex++) {
            $feature = $features[$readIndex] ?? null;
            $properties = is_array($feature) && is_array($feature['properties'] ?? null)
                ? $feature['properties']
                : null;
            $featureStart = $properties !== null && $needsStart
                && is_string($properties[$startProperty] ?? null)
                ? self::parseTimestamp($properties[$startProperty])
                : null;
            $featureEnd = $properties !== null && $needsEnd
                && is_string($properties[$endProperty] ?? null)
                ? self::parseTimestamp($properties[$endProperty])
                : null;
            $hasRequiredTimes = $properties !== null
                && (!$needsStart || $featureStart !== null)
                && (!$needsEnd || $featureEnd !== null);

            if (!$hasRequiredTimes) {
                $invalid++;
            }

            $matches = $hasRequiredTimes
                && ($from === null || $featureEnd >= $from)
                && ($until === null || $featureStart <= $until)
                && ($minimumDuration === null
                    || $featureEnd - $featureStart >= $minimumDuration);

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
