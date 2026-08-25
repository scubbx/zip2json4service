<?php

declare(strict_types=1);

/**
 * Autoloading configuration
 */

spl_autoload_register(static function (string $class): void {
    // Check if the class starts with our namespace
    if (strpos($class, 'GeoJsonProxy') !== 0) {
        return;
    }
    
    // Remove the namespace prefix
    $relativeClass = substr($class, 12); // 12 = strlen('GeoJsonProxy')
    
    // Remove leading backslash if present
    if (!empty($relativeClass) && $relativeClass[0] === '\\') {
        $relativeClass = substr($relativeClass, 1);
    }
    
    // Build the file path: src/GeoJsonProxy/... .php
    $file = __DIR__ . '/../src/GeoJsonProxy/' . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});
