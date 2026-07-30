<?php

declare(strict_types=1);

/**
 * uMap GeoJSON spatial filter proxy - Public Entry Point
 */

// Load configuration constants first (this defines constants in global namespace)
require_once __DIR__ . '/../config/config.php';

// Then load dependencies and autoloader
require_once __DIR__ . '/../config/dependencies.php';

use GeoJsonProxy\Config;
use GeoJsonProxy\Application;

try {
    // Debug: Check if constants are defined
    $missing_constants = [];
    $required_constants = ['VERSION', 'SOURCE_URL', 'BUFFER_URL', 'CACHE_DIR', 'CACHE_TTL'];
    foreach ($required_constants as $const) {
        if (!defined($const)) {
            $missing_constants[] = $const;
        }
    }
    
    if (!empty($missing_constants)) {
        throw new RuntimeException('Missing configuration constants: ' . implode(', ', $missing_constants));
    }

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
        fwrite(STDERR, 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        fwrite(STDERR, 'Trace: ' . $e->getTraceAsString() . PHP_EOL);
        exit(1);
    }

    // For web requests, send error response with more details
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code(500);
    }

    // Include file and line in error response
    $error_response = [
        'error' => 'internal proxy error',
        'details' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    
    // Add trace for debugging
    if (getenv('DEBUG') === '1') {
        $error_response['trace'] = $e->getTraceAsString();
    }
    
    echo json_encode($error_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
