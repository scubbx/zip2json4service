<?php

declare(strict_types=1);

/**
 * Autoloading configuration
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'GeoJsonProxy\\';
    $baseDir = __DIR__ . '/../src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});
