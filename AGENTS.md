# AGENTS.md - Development Guidelines for AI Agents

This document provides guidance for AI agents (like Mistral AI's Vibe Code) working on this repository.

## Repository Overview

This is **uMap GeoJSON Spatial Filter Proxy** - a PHP-based application that:
- Downloads GeoJSON data from remote sources
- Filters features spatially against buffer polygons
- Optionally filters cached results by a request-specific validity interval
- Serves filtered results with intelligent caching
- Supports both full geometries and point representations

## Project Structure

```
.
├── config/
│   ├── config.php          # Main configuration constants
│   └── dependencies.php    # PSR-4 autoloader configuration
├── public/
│   └── index.php           # Public entry point (web and CLI)
├── src/GeoJsonProxy/
│   ├── Application.php      # Main application orchestration
│   ├── Config.php           # Configuration loader and validator
│   ├── Cache/
│   │   └── Manager.php      # Cache file management
│   ├── Diagnostics/
│   │   └── Logger.php       # JSON-lines logging and status tracking
│   ├── GeoJson/
│   │   ├── BoundingBox.php   # Bounding box calculation and intersection
│   │   ├── Centroid.php     # Centroid/point representation calculation
│   │   ├── FeatureFilter.php # Transport mode attribute filtering
│   │   ├── GeometryExtractor.php # Extract polygons from complex GeoJSON
│   │   ├── Parser.php        # JSON parsing with validation
│   │   ├── Point.php         # Point utilities and comparisons
│   │   ├── PreparedPolygon.php # Compact buffer-polygon preparation and queries
│   │   ├── Segment.php       # Line segment intersection detection
│   │   ├── SpatialFilter.php # Core spatial filtering logic
│   │   └── TimeWindowFilter.php # Request time-window filtering
│   ├── Http/
│   │   ├── Fetcher.php      # HTTP client with gzip support
│   │   └── GzipDetector.php # Gzip magic bytes detection
│   └── SpatialIndex/
│       ├── GridIndex.php    # Grid index for buffer-boundary segments
│       └── PointIndex.php   # Y-bucket index for point-in-polygon tests
├── test_php_proxy.py         # Integration test suite
└── README.md                # User documentation
```

## Development Guidelines

### Code Style

- **PHP Version**: PHP 8.0+ with strict types (`declare(strict_types=1)`)
- **Naming**: Use `PascalCase` for classes, `snake_case` for methods and variables
- **Namespaces**: All classes in `GeoJsonProxy` namespace
- **Error Handling**: Use exceptions with meaningful messages
- **Type Safety**: Use type hints and return types throughout

### File Organization

- **No monolithic files**: Keep files focused and single-purpose
- **Class per file**: One class per PHP file
- **Logical grouping**: Related classes in same directory (e.g., `GeoJson/*` for GeoJSON handling)

### Testing

The integration test (`test_php_proxy.py`) covers:
- PHP syntax checking for every PHP file in the configured project copy
- Gzip and plain-JSON fetching, redirects, size limits, and malformed upstream payloads
- All GeoJSON geometry types (Point, LineString, Polygon, Multi*, GeometryCollection)
- Spatial filtering accuracy, including holes, boundaries, open rings, and degenerate edge cases
- Full and point representations plus transport-mode attribute filtering
- Configurable time properties/parameters, one-sided and two-sided intervals,
  inclusive boundaries, validation errors, and response-specific ETags
- Cache HIT, MISS, STALE, cache-key invalidation, integrity checks, and stale expiry
- ETag/304, HEAD, OPTIONS, CORS, status endpoint, and CLI behavior
- Concurrent cold-cache requests and refresh-lock coordination
- Error handling and invalid configuration

**Run tests**:
```bash
python3 test_php_proxy.py .

# Include PHP development-server output when diagnosing a failure
python3 test_php_proxy.py . --show-php-log

# Use a specific PHP binary
python3 test_php_proxy.py . --php-bin /path/to/php
```

The positional argument is the project directory, not the PHP entry point. The test creates temporary configured project copies and cache directories, starts mock upstream and PHP servers on ephemeral `127.0.0.1` ports, and does not modify the source configuration.

### Configuration

All user-configurable settings are in `config/config.php` as constants:
- URLs for source and buffer GeoJSON
- Transport mode filtering settings
- Time-filter property names and URL parameter names. The shipped defaults use
  the EVIS GeoJSON properties `start-time`/`stop-time` and request parameters
  `from`/`until`.
- Cache directories and TTLs
- Size limits and timeouts
- Debug and logging settings

**Do not** hardcode configuration in class files.

### Key Algorithms

#### Spatial Filtering
The core filtering logic in `SpatialFilter::filterByBufferPolygons()`:

1. **Bounding Box Optimization**: Quick rejection using axis-aligned bounding boxes
2. **Prepared Buffer Polygons**: Pack each polygon's segments once and build:
   - A uniform grid for boundary intersection candidates
   - Y-buckets for point-in-polygon candidates
3. **Geometric Intersection**: Full geometric checks for accurate results:
   - Point-in-Polygon (with hole support)
   - Segment-segment intersection
   - Polygon overlap detection
   - Support for all GeoJSON geometry types

#### Caching Strategy
- **Fresh Cache**: Served directly without upstream requests
- **Stale Cache**: Served when refresh fails but still within stale TTL
- **Cache Invalidation**: Based on configuration changes that affect upstream
  preparation (version, URLs, transport-mode filter settings)
- **Request Time Filtering**: Applied after loading the full or point cache.
  Never write a request-specific time-window result back to the shared cache;
  `--warm-cache` must remain independent of URL parameters.
- **Atomic Writes**: Temporary files + rename for atomic cache updates
- **Locking**: File-based locking (`refresh.lock`) prevents race conditions

### Common Pitfalls

1. **Memory Usage**: Large GeoJSON files can consume significant memory. The implementation:
   - Uses bounding boxes for quick rejection
   - Processes features iteratively
   - Cleans up temporary data

2. **Coordinate Systems**: Both source and buffer must use the same CRS. No transformation is performed.

3. **Polygon Validity**: The code assumes valid polygons. Invalid geometries may cause unexpected results.

4. **Gzip Detection**: Based on magic bytes, not file extension or Content-Type header.

## Workflow for AI Agents

### Before Making Changes

1. **Read the README**: Understand the project purpose and features
2. **Review the structure**: Check how similar functionality is implemented
3. **Check the tests**: Understand what behavior is expected
4. **Look at config**: See what's configurable vs. hardcoded

### Making Changes

1. **Follow existing patterns**: Match coding style and architecture
2. **Update tests**: If behavior changes, update `test_php_proxy.py`
3. **Validate**: Run the test suite after changes
4. **Document**: Update README if user-facing behavior changes

### Testing Without PHP

If PHP is not available in the environment:
- Check PHP syntax with `php -l <file>` when possible
- Verify Python test file compiles: `python3 -m py_compile test_php_proxy.py`
- Review code logic manually against test expectations

## Branch Strategy

- **main**: Stable releases (currently v1.5.1 equivalent)
- **refactor/modular-structure**: Modular, object-oriented refactoring

The modular branch is the active development branch and should be used for new work.

## Useful Commands

```bash
# Validate all PHP files (if PHP is available)
find config public src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l

# Run integration tests
python3 test_php_proxy.py .

# Check Python test syntax
python3 -m py_compile test_php_proxy.py

# View git status
git status

# View recent commits
git log --oneline -10
```

## Areas for Future Improvement

1. **Performance**: Consider streaming JSON parsing for very large files
2. **Configuration**: Could add environment variable support
3. **Validation**: More robust GeoJSON validation
4. **CRS Support**: Add coordinate transformation capabilities

## Getting Help

For questions about the codebase:
- Check existing code patterns
- Review test cases for expected behavior
- Examine the original monolithic version on `main` branch for reference

---

*Last updated: For uMap GeoJSON Spatial Filter Proxy v1.5.1*
