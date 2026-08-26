<?php

declare(strict_types=1);

/**
 * uMap GeoJSON spatial filter proxy - Configuration
 */

// Version
const VERSION = '1.5.1';

// API Response
const EMPTY_FEATURE_COLLECTION_JSON = '{"type":"FeatureCollection","features":[]}';

// URLs - EDIT THESE TO CONFIGURE YOUR PROXY
const SOURCE_URL = 'https://example.org/source.geojson.gz';
const BUFFER_URL = 'https://example.org/buffer.geojson.gz';

// Transport Mode Filter Configuration
const TRANSPORT_MODE_PROPERTY = 'affected-transportmode-types';
const ALLOWED_TRANSPORT_MODE_TYPES = []; // Leave empty to disable transport mode filtering

// Request Time Filter Configuration (inclusive interval-overlap semantics)
const TIME_FILTER_START_PROPERTY = 'start-time';
const TIME_FILTER_END_PROPERTY = 'stop-time';
const TIME_FILTER_FROM_PARAMETER = 'from';
const TIME_FILTER_UNTIL_PARAMETER = 'until';
const TIME_FILTER_MIN_DURATION_DAYS_PARAMETER = 'minDurationDays';

// Cache Configuration
const CACHE_DIR = __DIR__ . '/../cache';
const CACHE_TTL = '15m';  // Fresh cache lifetime
const STALE_TTL = '24h';  // Stale cache lifetime after refresh errors

// Limits
const MAX_BYTES = 32 * 1024 * 1024;  // Maximum size for downloads and results
const HTTP_TIMEOUT = 60;  // HTTP timeout in seconds
const USER_AGENT = 'umap-geojson-spatial-filter/' . VERSION;

// Geometry Precision
const GEO_EPSILON = 1.0e-12;

// Debug and Logging
const DEBUG_LOG_ENABLED = true;
const DEBUG_LOG_FILENAME = 'proxy.log';
const STATUS_FILENAME = 'status.json';
const STATUS_ENDPOINT_ENABLED = true;
const LOG_PROGRESS_EVERY = 1000;  // Log progress every N features

// Spatial Index Constants (Advanced - usually no need to change)
const PACKED_SEGMENT_BYTES = 32;
const PACKED_ID_BYTES = 4;
const SPATIAL_INDEX_TARGET_SEGMENTS_PER_CELL = 24;
const SPATIAL_INDEX_MAX_TOTAL_CELLS = 16384;
const SPATIAL_INDEX_MAX_GRID_DIMENSION = 128;
const SPATIAL_INDEX_MAX_CELLS_PER_SEGMENT = 128;
const SPATIAL_INDEX_MAX_REFERENCE_BYTES = 16 * 1024 * 1024;
const POINT_INDEX_TARGET_EDGES_PER_BUCKET = 32;
const POINT_INDEX_MAX_BUCKETS = 256;
const POINT_INDEX_MAX_BUCKETS_PER_EDGE = 32;
const POINT_INDEX_MAX_REFERENCE_BYTES = 8 * 1024 * 1024;
const COMPACT_SEGMENT_MAX_BYTES = 64 * 1024 * 1024;
const MEMORY_SAFETY_RESERVE_BYTES = 32 * 1024 * 1024;
