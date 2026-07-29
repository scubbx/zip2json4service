# uMap GeoJSON Spatial Filter Proxy

A PHP-based proxy that downloads GeoJSON data, filters features spatially against buffer polygons, and serves the results with caching.

## Structure

The application has been refactored from a single large file into a modular, object-oriented structure:

```
├── config/
│   ├── config.php          # Configuration constants
│   └── dependencies.php    # Autoloading configuration
├── public/
│   └── index.php           # Public entry point
└── src/GeoJsonProxy/
    ├── Application.php      # Main application class
    ├── Config.php           # Configuration loader
    ├── Constants.php        # Global constants
    ├── Cache/
    │   └── Manager.php      # Cache management
    ├── Diagnostics/
    │   └── Logger.php       # Logging utilities
    ├── GeoJson/
    │   ├── BoundingBox.php   # Bounding box utilities
    │   ├── Centroid.php     # Centroid calculation
    │   ├── FeatureFilter.php # Transport mode filtering
    │   ├── GeometryExtractor.php # Geometry extraction
    │   ├── Parser.php        # JSON parsing
    │   ├── Point.php         # Point utilities
    │   ├── Segment.php       # Segment intersection
    │   └── SpatialFilter.php # Spatial filtering
    ├── Http/
    │   ├── Fetcher.php      # HTTP client
    │   └── GzipDetector.php # Gzip detection
    └── SpatialIndex/
        ├── GridIndex.php     # Grid-based spatial index
        └── PointIndex.php    # Point-in-polygon index
```

## Configuration

Edit the constants in `config/config.php`:

- **SOURCE_URL**: URL of the source GeoJSON (can be gzip-compressed)
- **BUFFER_URL**: URL of the buffer GeoJSON with Polygon/MultiPolygon geometries
- **TRANSPORT_MODE_PROPERTY**: Feature property containing transport mode list
- **ALLOWED_TRANSPORT_MODE_TYPES**: Allowed transport mode values (empty to disable)
- **CACHE_DIR**: Writable cache directory
- **CACHE_TTL**: Fresh cache lifetime (e.g., '15m', '1h', '24h')
- **STALE_TTL**: Stale cache lifetime after refresh errors
- **MAX_BYTES**: Maximum size for downloads and results
- **HTTP_TIMEOUT**: HTTP timeout in seconds
- **DEBUG_LOG_ENABLED**: Enable detailed logging
- **LOG_PROGRESS_EVERY**: Log progress every N features

## Usage

### Web

- **Full geometries**: `https://your-domain.example/public/index.php`
- **Point representation**: `https://your-domain.example/public/index.php?geometry=point`
- **Status endpoint**: `https://your-domain.example/public/index.php?status=1`

### CLI

```bash
# Show help
php public/index.php --help

# Show version
php public/index.php --version

# Warm cache
php public/index.php --warm-cache
```

## uMap Configuration

### Full Geometry Layer
- URL: `https://your-domain.example/public/index.php`
- Format: GeoJSON

### Point/Symbol Layer
- URL: `https://your-domain.example/public/index.php?geometry=point`
- Format: GeoJSON

## Features

- ✅ Gzip-compressed GeoJSON support
- ✅ Transport mode filtering
- ✅ Spatial filtering (bounding box based)
- ✅ Caching with TTL and stale cache support
- ✅ Point representation (centroid calculation)
- ✅ CORS support
- ✅ ETag caching
- ✅ Status endpoint
- ✅ Detailed logging
- ✅ CLI cache warming

## Notes

- Both remote responses may be gzip-compressed or plain GeoJSON
- Gzip is detected from the response body's magic bytes
- The source GeoJSON must be a FeatureCollection
- Buffer data may be a Geometry, Feature, FeatureCollection, or GeometryCollection
- Both datasets must use the same coordinate reference system
- The point representation is calculated in that coordinate plane

## Requirements

- PHP 8.0+
- cURL extension (recommended)
- zlib extension (for gzip support)
- Writable cache directory
