<?php

declare(strict_types=1);

/**
 * uMap GeoJSON spatial filter proxy - Public Entry Point
 */

// Load configuration and constants
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/dependencies.php';

use GeoJsonProxy\Config;
use GeoJsonProxy\Application;

try {
    $config = new Config();

    if (PHP_SAPI === 'cli') {
        $app = new Application($config);
        $app->runCli($argv);
        exit;
    }

    $app = new Application($config);
    $app->runWeb();
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'error: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    // For web requests, send error response
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code(500);
    }

    echo json_encode([
        'error' => 'internal proxy error',
        'details' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
