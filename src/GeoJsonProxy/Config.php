<?php

declare(strict_types=1);

namespace GeoJsonProxy;

use RuntimeException;

/**
 * Configuration loader and validator
 */
final class Config
{
    private array $data;

    public function __construct(array $overrides = [])
    {
        $this->data = $this->loadDefaultConfig();
        $this->data = array_merge($this->data, $overrides);
        $this->validate();
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function getString(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            throw new RuntimeException("Config key '{$key}' must be a string");
        }
        return $value;
    }

    public function getInt(string $key): int
    {
        $value = $this->get($key);
        if (!is_int($value)) {
            throw new RuntimeException("Config key '{$key}' must be an integer");
        }
        return $value;
    }

    public function getBool(string $key): bool
    {
        $value = $this->get($key);
        if (!is_bool($value)) {
            throw new RuntimeException("Config key '{$key}' must be a boolean");
        }
        return $value;
    }

    public function getArray(string $key): array
    {
        $value = $this->get($key);
        if (!is_array($value)) {
            throw new RuntimeException("Config key '{$key}' must be an array");
        }
        return $value;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    private function loadDefaultConfig(): array
    {
        // Load constants from config file
        require_once __DIR__ . '/../../config/config.php';

        return [
            'version' => VERSION,
            'source_url' => SOURCE_URL,
            'buffer_url' => BUFFER_URL,
            'transport_mode_property' => TRANSPORT_MODE_PROPERTY,
            'allowed_transport_mode_types' => $this->normalizeAllowedTransportModeTypes(ALLOWED_TRANSPORT_MODE_TYPES),
            'cache_dir' => CACHE_DIR,
            'cache_ttl' => $this->parseDuration(CACHE_TTL),
            'stale_ttl' => $this->parseDuration(STALE_TTL),
            'max_bytes' => MAX_BYTES,
            'http_timeout' => HTTP_TIMEOUT,
            'user_agent' => USER_AGENT,
            'debug_log_enabled' => DEBUG_LOG_ENABLED,
            'log_file' => CACHE_DIR . '/' . DEBUG_LOG_FILENAME,
            'status_file' => CACHE_DIR . '/' . STATUS_FILENAME,
            'status_endpoint_enabled' => STATUS_ENDPOINT_ENABLED,
            'log_progress_every' => LOG_PROGRESS_EVERY,
        ];
    }

    private function validate(): void
    {
        foreach (['source_url', 'buffer_url'] as $key) {
            $url = trim((string) ($this->data[$key] ?? ''));
            if ($url === '') {
                throw new RuntimeException(strtoupper($key) . ' is empty');
            }
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException(strtoupper($key) . ' is not a valid URL');
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException(strtoupper($key) . ' must use HTTP or HTTPS');
            }
        }

        if (trim((string) $this->data['transport_mode_property']) === '') {
            throw new RuntimeException('TRANSPORT_MODE_PROPERTY must not be empty');
        }

        if (!is_array($this->data['allowed_transport_mode_types'])) {
            throw new RuntimeException('ALLOWED_TRANSPORT_MODE_TYPES must be an array');
        }

        if ($this->data['cache_ttl'] <= 0) {
            throw new RuntimeException('CACHE_TTL must be greater than zero');
        }

        if ($this->data['stale_ttl'] < 0) {
            throw new RuntimeException('STALE_TTL must not be negative');
        }

        if ($this->data['max_bytes'] <= 0) {
            throw new RuntimeException('MAX_BYTES must be greater than zero');
        }

        if ($this->data['http_timeout'] <= 0) {
            throw new RuntimeException('HTTP_TIMEOUT must be greater than zero');
        }
    }

    /**
     * Normalizes the configured transport-mode list
     *
     * @return array<int, string>
     */
    private function normalizeAllowedTransportModeTypes(array $values): array
    {
        $normalized = [];

        foreach ($values as $index => $value) {
            if (!is_string($value)) {
                throw new RuntimeException(sprintf(
                    'ALLOWED_TRANSPORT_MODE_TYPES entry %s must be a string',
                    (string) $index
                ));
            }

            $value = trim($value);

            if ($value === '') {
                throw new RuntimeException(sprintf(
                    'ALLOWED_TRANSPORT_MODE_TYPES entry %s must not be empty',
                    (string) $index
                ));
            }

            $normalized[$value] = true;
        }

        $result = array_keys($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * Parse duration string to seconds
     */
    private function parseDuration(string $value): int
    {
        $value = trim($value);

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (!preg_match('/^(\d+)\s*([smhd])$/i', $value, $matches)) {
            throw new RuntimeException('invalid duration: ' . $value);
        }

        $number = (int) $matches[1];
        $unit = strtolower($matches[2]);

        return match ($unit) {
            's' => $number,
            'm' => $number * 60,
            'h' => $number * 3600,
            'd' => $number * 86400,
            default => throw new RuntimeException('invalid duration unit: ' . $unit),
        };
    }
}
