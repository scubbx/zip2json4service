# uMap GeoJSON gzip Proxy

A small Go service that fetches a remote GeoJSON source, decompresses it if needed, validates the result as JSON, caches it in memory, and serves it as plain GeoJSON for use in [uMap](https://umap-project.org/) or similar web mapping tools.

This is useful when a web service provides GeoJSON through an URL that does not directly work as an external uMap data source, for example because the response is gzip-compressed or because the original URL has no useful file extension.

## Problem

Some services expose GeoJSON through URLs that do not directly return parseable GeoJSON to browser clients, for example:

```text
https://example.com/export
https://example.com/api/data?id=123
https://example.com/download/abc123
https://example.com/data.geojson.gz
```

The URL may have no file extension at all. What matters is not the URL name, but what the server returns.

A service may return a real gzip payload, for example with:

```http
Content-Type: application/gzip
```

In that case, uMap may fail to parse the external data source because it receives gzip-compressed bytes instead of plain GeoJSON text.

This proxy solves that by exposing a clean endpoint:

```text
https://your-domain.example/data.geojson
```

which returns plain, valid GeoJSON:

```http
Content-Type: application/geo+json
```

## Features

* Fetches a remote GeoJSON source from any configured URL
* The source URL does not need a `.geojson` or `.gz` file extension
* Automatically detects and decompresses real gzip payloads
* Validates that the decoded response is valid JSON
* Serves the result as `application/geo+json`
* Adds CORS headers for browser-based clients such as uMap
* Provides in-memory caching
* Supports stale-if-error behavior:

  * if the source service is temporarily unavailable, the last valid cached version can still be served
* Adds ETag support
* Provides a simple health check endpoint

## Endpoints

### `GET /data.geojson`

Returns the proxied GeoJSON.

Example:

```bash
curl -i http://localhost:8080/data.geojson
```

### `HEAD /data.geojson`

Returns headers only.

### `GET /healthz`

Simple health check endpoint.

```bash
curl http://localhost:8080/healthz
```

Expected response:

```text
ok
```

## Configuration

Configuration is done via environment variables.

| Variable      | Required |     Default | Description                                                  |
| ------------- | -------: | ----------: | ------------------------------------------------------------ |
| `SOURCE_URL`  |      yes |           — | Remote source URL, for example `https://example.com/export`  |
| `LISTEN_ADDR` |       no |     `:8080` | Address and port the service listens on                      |
| `CACHE_TTL`   |       no |        `5m` | How long a successfully fetched response is considered fresh |
| `STALE_TTL`   |       no |       `24h` | How long stale cached data may be served if refreshing fails |
| `MAX_BYTES`   |       no | `104857600` | Maximum allowed response size in bytes, default 100 MB       |

Duration values use Go duration syntax, for example:

```text
30s
5m
1h
24h
```

## Run locally

```bash
export SOURCE_URL='https://example.com/export'
export CACHE_TTL='5m'
export STALE_TTL='24h'

go run main.go
```

Then open:

```text
http://localhost:8080/data.geojson
```

## Build

```bash
go build -o umap-geojson-proxy .
```

Run:

```bash
SOURCE_URL='https://example.com/export' ./umap-geojson-proxy
```

## Docker

Build the image:

```bash
docker build -t umap-geojson-proxy .
```

Run it:

```bash
docker run --rm -p 8080:8080 \
  -e SOURCE_URL='https://example.com/export' \
  -e CACHE_TTL='5m' \
  -e STALE_TTL='24h' \
  umap-geojson-proxy
```

The proxied GeoJSON is then available at:

```text
http://localhost:8080/data.geojson
```

## Docker Compose

Example `docker-compose.yml`:

```yaml
services:
  umap-geojson-proxy:
    image: umap-geojson-proxy
    build: .
    ports:
      - "8080:8080"
    environment:
      SOURCE_URL: "https://example.com/export"
      CACHE_TTL: "5m"
      STALE_TTL: "24h"
      MAX_BYTES: "104857600"
    restart: unless-stopped
```

Start:

```bash
docker compose up -d
```

## Usage with uMap

In uMap, configure the layer as external data:

```text
URL:    https://your-domain.example/data.geojson
Format: GeoJSON
```

The URL entered in uMap should be the proxy endpoint, not the original source URL.

If this proxy is already caching the response, the uMap proxy cache can usually be left disabled:

```text
Proxycache-Anfrage: Kein Cache
```

## Cache behavior

The service uses a simple in-memory cache.

Default behavior:

1. First request fetches the remote source.
2. The result is decompressed if necessary.
3. The result is validated as JSON.
4. The valid GeoJSON is cached.
5. Further requests are served from cache until `CACHE_TTL` expires.
6. After `CACHE_TTL`, the proxy tries to refresh the data.
7. If refreshing fails, the previous valid response is served until `STALE_TTL` expires.

This avoids unnecessary load on the upstream service and keeps uMap usable during short upstream outages.

## Gzip detection

The proxy does not rely on the source URL file name or file extension.

Instead, it checks the downloaded response body for the gzip magic bytes:

```text
1f 8b
```

If the response starts with these bytes, the proxy treats it as a gzip payload and decompresses it before validating and serving the result.

This means all of the following source URLs are supported, as long as the decoded response is valid JSON or GeoJSON:

```text
https://example.com/export
https://example.com/api/data?id=123
https://example.com/download/abc123
https://example.com/data.geojson.gz
```

## Response headers

The proxy sets headers similar to:

```http
Content-Type: application/geo+json; charset=utf-8
Access-Control-Allow-Origin: *
Cache-Control: public, max-age=300
ETag: "..."
X-Cache: HIT
X-Cache-Fetched-At: 2026-07-09T10:00:00Z
```

Possible `X-Cache` values:

| Value   | Meaning                                                |
| ------- | ------------------------------------------------------ |
| `MISS`  | Data was fetched from the source and cached            |
| `HIT`   | Fresh cached data was served                           |
| `STALE` | Stale cached data was served because refreshing failed |

## Notes

This proxy does not transform the GeoJSON structure. It only fetches, decompresses, validates and forwards the data.

If the upstream service returns HTML, an error page, invalid JSON or a file larger than `MAX_BYTES`, the refresh fails.

If a previous valid response exists and is still within the stale window, that cached response is served instead.

## Security considerations

Only configure trusted `SOURCE_URL` values.

This service is intended as a fixed-purpose proxy for one known upstream source. It is not a general open proxy and does not accept arbitrary target URLs from users.

## License

MIT License
