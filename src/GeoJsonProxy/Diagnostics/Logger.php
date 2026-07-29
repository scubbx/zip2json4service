<?php

declare(strict_types=1);

namespace GeoJsonProxy\Diagnostics;

use Throwable;

/**
 * Logging utilities for the proxy
 */
final class Logger
{
    private static ?array $diagnostics = null;

    /**
     * Initialize diagnostics
     */
    public static function initialize(array $config, string $mode): void
    {
        $requestId = sprintf(
            '%s-%d-%s',
            gmdate('YmdHis'),
            getmypid(),
            substr(hash('sha256', uniqid('', true)), 0, 8)
        );

        self::$diagnostics = [
            'enabled' => (bool) $config['debug_log_enabled'],
            'log_file' => $config['log_file'],
            'status_file' => $config['status_file'],
            'request_id' => $requestId,
            'started_at' => microtime(true),
            'mode' => $mode,
            'progress_every' => (int) $config['log_progress_every'],
        ];

        if (!$config['debug_log_enabled']) {
            return;
        }

        @ini_set('log_errors', '1');
        @ini_set('error_log', $config['log_file']);

        // Keep a small reserve so a fatal memory error can still be logged.
        $GLOBALS['proxy_fatal_memory_reserve'] = str_repeat('R', 128 * 1024);

        register_shutdown_function(static function (): void {
            unset($GLOBALS['proxy_fatal_memory_reserve']);

            $error = error_get_last();
            if ($error === null) {
                return;
            }

            if (!in_array($error['type'], [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
            ], true)) {
                return;
            }

            self::log('FATAL', 'PHP terminated with a fatal error', [
                'error_type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);

            self::writeStatus('fatal-error', [
                'error_type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        });

        self::log('INFO', 'diagnostics initialized', [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'curl_available' => function_exists('curl_init'),
            'gzdecode_available' => function_exists('gzdecode'),
            'cache_dir' => $config['cache_dir'],
        ]);
    }

    /**
     * Log a message
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $diagnostics = self::$diagnostics ?? null;

        if (!is_array($diagnostics) || !($diagnostics['enabled'] ?? false)) {
            return;
        }

        $record = array_merge([
            'timestamp' => gmdate(DATE_ATOM),
            'level' => strtoupper($level),
            'request_id' => $diagnostics['request_id'] ?? null,
            'mode' => $diagnostics['mode'] ?? null,
            'elapsed_seconds' => round(
                microtime(true) - (float) ($diagnostics['started_at'] ?? microtime(true)),
                3
            ),
            'memory_mib' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'message' => $message,
        ], $context);

        $json = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json !== false) {
            @file_put_contents(
                (string) $diagnostics['log_file'],
                $json . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }

    /**
     * Set proxy stage
     */
    public static function setStage(string $stage, array $context = []): void
    {
        self::log('INFO', 'stage: ' . $stage, $context);
        self::writeStatus($stage, $context);
    }

    /**
     * Write proxy status
     */
    public static function writeStatus(string $stage, array $context = []): void
    {
        $diagnostics = self::$diagnostics ?? null;

        if (!is_array($diagnostics) || !($diagnostics['enabled'] ?? false)) {
            return;
        }

        $status = array_merge([
            'stage' => $stage,
            'updated_at' => gmdate(DATE_ATOM),
            'request_id' => $diagnostics['request_id'] ?? null,
            'mode' => $diagnostics['mode'] ?? null,
            'elapsed_seconds' => round(
                microtime(true) - (float) ($diagnostics['started_at'] ?? microtime(true)),
                3
            ),
            'memory_mib' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ], $context);

        $json = json_encode(
            $status,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            return;
        }

        $statusFile = (string) $diagnostics['status_file'];
        $tmp = $statusFile . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            if (!@rename($tmp, $statusFile)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Log an exception
     */
    public static function logException(string $message, Throwable $exception): void
    {
        self::log('ERROR', $message, [
            'exception' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
