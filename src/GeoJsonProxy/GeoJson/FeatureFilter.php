<?php

declare(strict_types=1);

namespace GeoJsonProxy\GeoJson;

use RuntimeException;

/**
 * Filter features by transport mode
 */
final class FeatureFilter
{
    /**
     * Filter features in place by transport mode
     *
     * @return array Statistics about the filtering
     */
    public static function filterByTransportMode(
        array &$source,
        string $propertyName,
        array $allowedTypes
    ): array {
        if (($source['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('source GeoJSON must be a FeatureCollection');
        }

        if (!is_array($source['features'] ?? null)) {
            throw new RuntimeException('source FeatureCollection has no valid features array');
        }

        $allowedLookup = self::createStringLookup($allowedTypes);
        $transportModeFilterEnabled = $allowedLookup !== [];
        $features =& $source['features'];
        $totalFeatures = count($features);
        $writeIndex = 0;
        $transportModeMatchedFeatures = 0;
        $transportModeRejectedFeatures = 0;
        $featuresWithoutValidGeometry = 0;

        for ($readIndex = 0; $readIndex < $totalFeatures; $readIndex++) {
            $feature = $features[$readIndex] ?? null;

            if (!is_array($feature)) {
                $transportModeRejectedFeatures++;
                unset($features[$readIndex]);
                continue;
            }

            if (!self::featureMatchesAllowedTypes($feature, $propertyName, $allowedLookup)) {
                $transportModeRejectedFeatures++;
                unset($features[$readIndex]);
                continue;
            }

            $transportModeMatchedFeatures++;

            if (!is_array($feature['geometry'] ?? null)) {
                $featuresWithoutValidGeometry++;
                unset($features[$readIndex]);
                continue;
            }

            $features[$writeIndex] = $feature;
            if ($writeIndex !== $readIndex) {
                unset($features[$readIndex]);
            }
            $writeIndex++;
        }

        return [
            'source_features' => $totalFeatures,
            'candidate_features' => $writeIndex,
            'transport_mode_filter_enabled' => $transportModeFilterEnabled,
            'transport_mode_property' => $propertyName,
            'allowed_transport_mode_types' => $allowedTypes,
            'transport_mode_matched_features' => $transportModeMatchedFeatures,
            'transport_mode_rejected_features' => $transportModeRejectedFeatures,
            'features_without_valid_geometry' => $featuresWithoutValidGeometry,
        ];
    }

    /**
     * Check if feature matches allowed transport modes
     *
     * @param array<string, true> $allowedLookup
     */
    private static function featureMatchesAllowedTypes(
        array $feature,
        string $propertyName,
        array $allowedLookup
    ): bool {
        if ($allowedLookup === []) {
            return true;
        }

        $properties = $feature['properties'] ?? null;
        if (!is_array($properties) || !array_key_exists($propertyName, $properties)) {
            return false;
        }

        $featureValues = $properties[$propertyName];

        if (is_string($featureValues)) {
            return isset($allowedLookup[trim($featureValues)]);
        }

        if (!is_array($featureValues)) {
            return false;
        }

        foreach ($featureValues as $featureValue) {
            if (is_string($featureValue) && isset($allowedLookup[trim($featureValue)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create lookup array from string values
     *
     * @param array<int, string> $values
     * @return array<string, true>
     */
    private static function createStringLookup(array $values): array
    {
        $lookup = [];
        foreach ($values as $value) {
            $lookup[$value] = true;
        }
        return $lookup;
    }
}
