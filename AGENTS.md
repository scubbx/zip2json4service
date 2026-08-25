# AGENTS.md - Development Guidelines for AI Agents

This document provides guidance for AI agents (like Mistral AI's Vibe Code) working on this repository.

## Repository Overview

This is **uMap GeoJSON Spatial Filter Proxy** - a PHP-based application that:
- Downloads GeoJSON data from remote sources
- Filters features spatially against buffer polygons
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
│   │   ├── Segment.php       # Line segment intersection detection
│   │   └── SpatialFilter.php # Core spatial filtering logic
│   ├── Http/
│   │   ├── Fetcher.php      # HTTP client with gzip support
│   │   └── GzipDetector.php # Gzip magic bytes detection
│   └── SpatialIndex/
│       ├── GridIndex.php    # Grid-based spatial index (for future optimization)
│       └── PointIndex.php   # Point-in-polygon index (for future optimization)
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
- HTTP fetching and gzip decompression
- All GeoJSON geometry types (Point, LineString, Polygon, Multi*, GeometryCollection)
- Spatial filtering accuracy (including holes, boundaries, overlaps)
- Transport mode attribute filtering
- Caching behavior (HIT, MISS, STALE)
- Point representation generation
- ETag and 304 Not Modified responses
- Error handling (502, 400, 405)

**Run tests**:
```bash
python3 test_php_proxy.py public/index.php
```

### Configuration

All user-configurable settings are in `config/config.php` as constants:
- URLs for source and buffer GeoJSON
- Transport mode filtering settings
- Cache directories and TTLs
- Size limits and timeouts
- Debug and logging settings

**Do not** hardcode configuration in class files.

### Key Algorithms

#### Spatial Filtering
The core filtering logic in `SpatialFilter::filterByBufferPolygons()`:

1. **Bounding Box Optimization**: Quick rejection using axis-aligned bounding boxes
2. **Geometric Intersection**: Full geometric checks for accurate results:
   - Point-in-Polygon (with hole support)
   - Segment-segment intersection
   - Polygon overlap detection
   - Support for all GeoJSON geometry types

#### Caching Strategy
- **Fresh Cache**: Served directly without upstream requests
- **Stale Cache**: Served when refresh fails but still within stale TTL
- **Cache Invalidation**: Based on configuration changes (version, URLs, filter settings)
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
# Validate PHP syntax (if PHP available)
php -l public/index.php
php -l src/GeoJsonProxy/Application.php

# Run integration tests
python3 test_php_proxy.py public/index.php

# Check Python test syntax
python3 -m py_compile test_php_proxy.py

# View git status
git status

# View recent commits
git log --oneline -10
```

## Areas for Future Improvement

1. **Spatial Indexes**: The `GridIndex` and `PointIndex` classes are placeholders for future optimization
2. **Performance**: Consider streaming JSON parsing for very large files
3. **Configuration**: Could add environment variable support
4. **Validation**: More robust GeoJSON validation
5. **CRS Support**: Add coordinate transformation capabilities

## Getting Help

For questions about the codebase:
- Check existing code patterns
- Review test cases for expected behavior
- Examine the original monolithic version on `main` branch for reference

---

*Last updated: For uMap GeoJSON Spatial Filter Proxy v1.5.1*
