#!/usr/bin/env python3
"""Integration tests for the pure-PHP uMap GeoJSON spatial filter proxy.

The current PHP proxy keeps its configuration in constants at the top of the
file. This test therefore creates a temporary copy of the PHP script and
patches only those constants. The original PHP file is never modified.

Covered behavior:

* source and buffer GeoJSON are fetched as gzip payloads;
* Point, LineString, Polygon, MultiPoint, MultiLineString, MultiPolygon, and
  GeometryCollection features are spatially filtered;
* polygon holes and boundary intersections are handled;
* multiple buffer polygons, MultiPolygon, and GeometryCollection buffers work;
* the filtered result, rather than the unfiltered source, is cached;
* cache MISS, HIT, ETag/304, HEAD, OPTIONS, and 405 behavior works;
* changing the buffer is picked up only after the cache TTL expires;
* failures of either source URL or buffer URL serve stale cache data;
* --warm-cache refreshes the filtered cache;
* an invalid buffer dataset without polygons produces HTTP 502 when no stale
  cache is available.
"""

from __future__ import annotations

import argparse
import gzip
import http.client
import json
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import urlparse


def square(min_x: float, min_y: float, max_x: float, max_y: float) -> list[list[float]]:
    """Return a closed rectangular GeoJSON linear ring."""
    return [
        [min_x, min_y],
        [max_x, min_y],
        [max_x, max_y],
        [min_x, max_y],
        [min_x, min_y],
    ]


def feature(feature_id: str, geometry: dict[str, Any] | None) -> dict[str, Any]:
    return {
        "type": "Feature",
        "id": feature_id,
        "properties": {
            "name": feature_id,
            "keep_original_property": True,
        },
        "geometry": geometry,
    }


SOURCE_GEOJSON: dict[str, Any] = {
    "type": "FeatureCollection",
    # This must be removed because it no longer describes the filtered result.
    "bbox": [-10, -10, 110, 110],
    # A foreign member should survive filtering.
    "dataset": "spatial-filter-integration-test",
    "features": [
        feature("point_inside", {"type": "Point", "coordinates": [1, 1]}),
        feature("point_outside", {"type": "Point", "coordinates": [15, 15]}),
        feature("point_outer_boundary", {"type": "Point", "coordinates": [0, 5]}),
        feature("point_in_hole", {"type": "Point", "coordinates": [5, 5]}),
        feature("point_hole_boundary", {"type": "Point", "coordinates": [4, 5]}),
        feature("point_second_buffer", {"type": "Point", "coordinates": [21, 21]}),
        feature("point_third_buffer", {"type": "Point", "coordinates": [31, 31]}),
        feature(
            "line_crosses",
            {"type": "LineString", "coordinates": [[-2, 2], [2, 2]]},
        ),
        feature(
            "line_only_in_hole",
            {"type": "LineString", "coordinates": [[4.5, 5], [5.5, 5]]},
        ),
        feature(
            "line_outside",
            {"type": "LineString", "coordinates": [[12, 12], [13, 13]]},
        ),
        feature(
            "polygon_inside",
            {"type": "Polygon", "coordinates": [square(1, 1, 2, 2)]},
        ),
        feature(
            "polygon_contains_buffer",
            {"type": "Polygon", "coordinates": [square(-1, -1, 11, 11)]},
        ),
        feature(
            "polygon_overlaps",
            {"type": "Polygon", "coordinates": [square(9, 9, 12, 12)]},
        ),
        feature(
            "polygon_only_in_hole",
            {"type": "Polygon", "coordinates": [square(4.5, 4.5, 5.5, 5.5)]},
        ),
        feature(
            "polygon_outside",
            {"type": "Polygon", "coordinates": [square(12, 12, 13, 13)]},
        ),
        feature(
            "multipoint_hit",
            {"type": "MultiPoint", "coordinates": [[15, 15], [21, 21]]},
        ),
        feature(
            "multipoint_miss",
            {"type": "MultiPoint", "coordinates": [[15, 15], [16, 16]]},
        ),
        feature(
            "multiline_hit",
            {
                "type": "MultiLineString",
                "coordinates": [
                    [[40, 40], [41, 41]],
                    [[29, 31], [31, 31]],
                ],
            },
        ),
        feature(
            "multiline_miss",
            {
                "type": "MultiLineString",
                "coordinates": [
                    [[40, 40], [41, 41]],
                    [[50, 50], [51, 51]],
                ],
            },
        ),
        feature(
            "multipolygon_hit",
            {
                "type": "MultiPolygon",
                "coordinates": [
                    [square(50, 50, 51, 51)],
                    [square(20.5, 20.5, 21.5, 21.5)],
                ],
            },
        ),
        feature(
            "multipolygon_miss",
            {
                "type": "MultiPolygon",
                "coordinates": [
                    [square(50, 50, 51, 51)],
                    [square(60, 60, 61, 61)],
                ],
            },
        ),
        feature(
            "geometrycollection_hit",
            {
                "type": "GeometryCollection",
                "geometries": [
                    {"type": "Point", "coordinates": [5, 5]},
                    {"type": "LineString", "coordinates": [[19, 21], [20.5, 21]]},
                ],
            },
        ),
        feature(
            "geometrycollection_miss",
            {
                "type": "GeometryCollection",
                "geometries": [
                    {"type": "Point", "coordinates": [5, 5]},
                    {"type": "LineString", "coordinates": [[70, 70], [71, 71]]},
                ],
            },
        ),
        feature("point_moved", {"type": "Point", "coordinates": [105, 105]}),
        feature(
            "line_moved",
            {"type": "LineString", "coordinates": [[99, 105], [101, 105]]},
        ),
        feature("null_geometry", None),
    ],
}


INITIAL_BUFFER_GEOJSON: dict[str, Any] = {
    "type": "FeatureCollection",
    "features": [
        {
            "type": "Feature",
            "properties": {"name": "polygon-with-hole"},
            "geometry": {
                "type": "Polygon",
                "coordinates": [
                    square(0, 0, 10, 10),
                    square(4, 4, 6, 6),
                ],
            },
        },
        {
            "type": "Feature",
            "properties": {"name": "geometry-collection-with-multipolygon"},
            "geometry": {
                "type": "GeometryCollection",
                "geometries": [
                    {
                        "type": "MultiPolygon",
                        "coordinates": [
                            [square(20, 20, 22, 22)],
                            [square(30, 30, 32, 32)],
                        ],
                    },
                    # Non-polygon members in the buffer document are ignored.
                    {"type": "Point", "coordinates": [999, 999]},
                ],
            },
        },
    ],
}


MOVED_BUFFER_GEOJSON: dict[str, Any] = {
    "type": "FeatureCollection",
    "features": [
        {
            "type": "Feature",
            "properties": {"name": "moved-buffer"},
            "geometry": {
                "type": "Polygon",
                "coordinates": [square(100, 100, 110, 110)],
            },
        }
    ],
}


INVALID_BUFFER_GEOJSON: dict[str, Any] = {
    "type": "FeatureCollection",
    "features": [
        feature("not-a-polygon", {"type": "Point", "coordinates": [1, 1]})
    ],
}


EXPECTED_INITIAL_IDS = [
    "point_inside",
    "point_outer_boundary",
    "point_hole_boundary",
    "point_second_buffer",
    "point_third_buffer",
    "line_crosses",
    "polygon_inside",
    "polygon_contains_buffer",
    "polygon_overlaps",
    "multipoint_hit",
    "multiline_hit",
    "multipolygon_hit",
    "geometrycollection_hit",
]

EXPECTED_MOVED_IDS = [
    "point_moved",
    "line_moved",
]


class MockState:
    lock = threading.Lock()
    source_request_count = 0
    buffer_request_count = 0
    source_fail = False
    buffer_fail = False
    buffer_variant = "initial"

    @classmethod
    def reset(cls) -> None:
        with cls.lock:
            cls.source_request_count = 0
            cls.buffer_request_count = 0
            cls.source_fail = False
            cls.buffer_fail = False
            cls.buffer_variant = "initial"

    @classmethod
    def counts(cls) -> tuple[int, int]:
        with cls.lock:
            return cls.source_request_count, cls.buffer_request_count


class MockUpstreamHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt: str, *args: Any) -> None:
        return

    def send_bytes(self, status: int, content_type: str, body: bytes) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        path = urlparse(self.path).path

        if path == "/source":
            with MockState.lock:
                MockState.source_request_count += 1
                should_fail = MockState.source_fail

            if should_fail:
                self.send_bytes(503, "text/plain", b"source currently failing\n")
                return

            raw = json.dumps(
                SOURCE_GEOJSON,
                ensure_ascii=False,
                separators=(",", ":"),
            ).encode("utf-8")
            self.send_bytes("200" if False else 200, "application/gzip", gzip.compress(raw))
            return

        if path == "/buffer":
            with MockState.lock:
                MockState.buffer_request_count += 1
                should_fail = MockState.buffer_fail
                variant = MockState.buffer_variant

            if should_fail:
                self.send_bytes(503, "text/plain", b"buffer currently failing\n")
                return

            if variant == "initial":
                document = INITIAL_BUFFER_GEOJSON
            elif variant == "moved":
                document = MOVED_BUFFER_GEOJSON
            elif variant == "invalid":
                document = INVALID_BUFFER_GEOJSON
            else:
                self.send_bytes(500, "text/plain", b"unknown buffer variant\n")
                return

            raw = json.dumps(
                document,
                ensure_ascii=False,
                separators=(",", ":"),
            ).encode("utf-8")
            self.send_bytes(200, "application/gzip", gzip.compress(raw))
            return

        self.send_bytes(404, "text/plain", b"not found\n")


def get_free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_for_port(port: int, timeout: float = 10.0) -> None:
    deadline = time.time() + timeout

    while time.time() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.1)

    raise RuntimeError(f"Port {port} did not open in time")


def http_request(
    method: str,
    url: str,
    headers: dict[str, str] | None = None,
) -> tuple[int, dict[str, str], bytes]:
    headers = headers or {}
    parsed = urlparse(url)

    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query

    conn = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=15)
    conn.request(method, path, headers=headers)

    response = conn.getresponse()
    body = response.read()
    response_headers = {key.lower(): value for key, value in response.getheaders()}
    status = response.status
    conn.close()

    return status, response_headers, body


def assert_eq(actual: Any, expected: Any, message: str) -> None:
    if actual != expected:
        raise AssertionError(
            f"{message}: expected {expected!r}, got {actual!r}"
        )


def assert_true(condition: Any, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def assert_contains(needle: str, haystack: str, message: str) -> None:
    if needle not in haystack:
        raise AssertionError(
            f"{message}: expected to find {needle!r} in {haystack!r}"
        )


def retained_ids(document: dict[str, Any]) -> list[str]:
    return [str(item.get("id")) for item in document.get("features", [])]


def start_mock_server(port: int) -> ThreadingHTTPServer:
    server = ThreadingHTTPServer(("127.0.0.1", port), MockUpstreamHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


def php_string(value: str) -> str:
    """Encode a Python string as a single-quoted PHP string literal."""
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def replace_php_constant(source: str, name: str, php_value: str) -> str:
    pattern = re.compile(
        rf"^const\s+{re.escape(name)}\s*=\s*.*?;\s*$",
        re.MULTILINE,
    )
    replacement = f"const {name} = {php_value};"
    updated, count = pattern.subn(replacement, source, count=1)

    if count != 1:
        raise RuntimeError(f"Could not find exactly one PHP constant named {name}")

    return updated


def create_configured_php_copy(
    original_script: Path,
    target_script: Path,
    source_url: str,
    buffer_url: str,
    cache_dir: Path,
) -> None:
    source = original_script.read_text(encoding="utf-8")

    replacements = {
        "SOURCE_URL": php_string(source_url),
        "BUFFER_URL": php_string(buffer_url),
        "CACHE_DIR": php_string(str(cache_dir)),
        "CACHE_TTL": php_string("1s"),
        "STALE_TTL": php_string("20s"),
        "MAX_BYTES": str(10 * 1024 * 1024),
        "HTTP_TIMEOUT": "5",
    }

    for name, value in replacements.items():
        source = replace_php_constant(source, name, value)

    target_script.write_text(source, encoding="utf-8")


def lint_php_script(php_bin: str, php_script: Path) -> None:
    result = subprocess.run(
        [php_bin, "-l", str(php_script)],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        timeout=20,
        check=False,
    )

    if result.returncode != 0:
        raise RuntimeError(f"PHP syntax check failed:\n{result.stdout}")


def start_php_server(
    php_bin: str,
    php_dir: Path,
    php_port: int,
) -> subprocess.Popen[str]:
    process = subprocess.Popen(
        [php_bin, "-S", f"127.0.0.1:{php_port}", "-t", str(php_dir)],
        cwd=str(php_dir),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )

    try:
        wait_for_port(php_port, timeout=10)
    except Exception:
        output = ""
        if process.stdout:
            try:
                output = process.stdout.read()
            except Exception:
                pass
        process.terminate()
        raise RuntimeError(f"PHP server did not start. Output:\n{output}")

    return process


def stop_process(process: subprocess.Popen[str]) -> str:
    process.terminate()

    try:
        output, _ = process.communicate(timeout=5)
    except subprocess.TimeoutExpired:
        process.kill()
        output, _ = process.communicate(timeout=5)

    return output or ""


def wait_until_cache_expired(cache_dir: Path, timeout: float = 5.0) -> None:
    meta_path = cache_dir / "meta.json"
    deadline = time.time() + timeout

    while time.time() < deadline:
        if meta_path.is_file():
            meta = json.loads(meta_path.read_text(encoding="utf-8"))
            expires_at = int(meta["expires_at"])

            # PHP checks time() < expires_at. Waiting until the next full second
            # avoids timing flakiness around integer timestamps.
            remaining = expires_at - time.time() + 0.15
            if remaining > 0:
                time.sleep(remaining)

            while int(time.time()) < expires_at:
                time.sleep(0.05)
            return

        time.sleep(0.05)

    raise RuntimeError("Cache metadata was not created in time")


def clear_cache(cache_dir: Path) -> None:
    for filename in ("data.geojson", "meta.json"):
        path = cache_dir / filename
        if path.exists():
            path.unlink()


def run_tests(args: argparse.Namespace) -> None:
    original_php_script = Path(args.php_script).resolve()

    if not original_php_script.is_file():
        raise RuntimeError(f"PHP script not found: {original_php_script}")

    php_bin = shutil.which(args.php_bin)
    if php_bin is None:
        raise RuntimeError(f"PHP binary not found: {args.php_bin}")

    MockState.reset()

    mock_port = get_free_port()
    php_port = get_free_port()
    mock_server = start_mock_server(mock_port)
    php_process: subprocess.Popen[str] | None = None

    try:
        with tempfile.TemporaryDirectory(prefix="geojson-spatial-proxy-test-") as tmp:
            tmp_dir = Path(tmp)
            php_dir = tmp_dir / "php"
            php_dir.mkdir()
            cache_dir = tmp_dir / "cache"
            configured_php_script = php_dir / original_php_script.name

            create_configured_php_copy(
                original_script=original_php_script,
                target_script=configured_php_script,
                source_url=f"http://127.0.0.1:{mock_port}/source",
                buffer_url=f"http://127.0.0.1:{mock_port}/buffer",
                cache_dir=cache_dir,
            )

            print("Test 0: configured PHP copy passes syntax check")
            lint_php_script(php_bin, configured_php_script)

            php_process = start_php_server(
                php_bin=php_bin,
                php_dir=php_dir,
                php_port=php_port,
            )

            proxy_url = f"http://127.0.0.1:{php_port}/{configured_php_script.name}"

            print("Test 1: first GET fetches both gzip files and spatially filters all supported geometry types")
            status, headers, initial_body = http_request("GET", proxy_url)

            assert_eq(status, 200, "first GET status")
            assert_true(
                headers.get("content-type", "").startswith("application/geo+json"),
                "Content-Type should be application/geo+json",
            )
            assert_eq(headers.get("access-control-allow-origin"), "*", "CORS header")
            assert_eq(headers.get("x-cache"), "MISS", "first request should be cache MISS")
            assert_eq(MockState.counts(), (1, 1), "both upstream datasets should be fetched once")

            initial_document = json.loads(initial_body.decode("utf-8"))
            assert_eq(initial_document["type"], "FeatureCollection", "GeoJSON type")
            assert_eq(
                retained_ids(initial_document),
                EXPECTED_INITIAL_IDS,
                "retained feature IDs for initial buffers",
            )
            assert_true("bbox" not in initial_document, "stale source-level bbox should be removed")
            assert_eq(
                initial_document.get("dataset"),
                "spatial-filter-integration-test",
                "foreign FeatureCollection members should be preserved",
            )
            assert_true(
                all(
                    item.get("properties", {}).get("keep_original_property") is True
                    for item in initial_document["features"]
                ),
                "retained features should preserve their original properties",
            )

            etag_initial = headers.get("etag")
            assert_true(etag_initial, "ETag should be present")
            assert_eq(
                int(headers.get("content-length", "-1")),
                len(initial_body),
                "Content-Length should match the filtered body",
            )

            cache_data_path = cache_dir / "data.geojson"
            cache_meta_path = cache_dir / "meta.json"
            assert_true(cache_data_path.is_file(), "filtered cache data should exist")
            assert_true(cache_meta_path.is_file(), "cache metadata should exist")
            assert_eq(
                cache_data_path.read_bytes(),
                initial_body,
                "cache must contain the filtered result served to uMap",
            )
            cache_meta = json.loads(cache_meta_path.read_text(encoding="utf-8"))
            assert_eq(cache_meta["etag"], etag_initial, "cache metadata ETag")
            assert_eq(cache_meta["bytes"], len(initial_body), "cache metadata byte count")
            assert_true(cache_meta.get("cache_key"), "cache key should be stored")

            print("Test 2: repeated requests during the TTL use only the filtered cache")
            status, headers, second_body = http_request("GET", proxy_url)
            assert_eq(status, 200, "second GET status")
            assert_eq(headers.get("x-cache"), "HIT", "second request should be cache HIT")
            assert_eq(MockState.counts(), (1, 1), "no upstream request should occur during fresh cache")
            assert_eq(second_body, initial_body, "cached body should equal initial filtered body")

            print("Test 3: If-None-Match accepts a list of ETags and returns 304")
            status, headers, body_304 = http_request(
                "GET",
                proxy_url,
                headers={"If-None-Match": f'"unrelated", {etag_initial}'},
            )
            assert_eq(status, 304, "If-None-Match status")
            assert_eq(body_304, b"", "304 response should have an empty body")
            assert_eq(MockState.counts(), (1, 1), "304 should not fetch upstream data")

            print("Test 4: HEAD returns filtered representation headers without a body")
            status, headers, head_body = http_request("HEAD", proxy_url)
            assert_eq(status, 200, "HEAD status")
            assert_eq(headers.get("etag"), etag_initial, "HEAD ETag")
            assert_eq(
                int(headers.get("content-length", "-1")),
                len(initial_body),
                "HEAD Content-Length",
            )
            assert_eq(head_body, b"", "HEAD response should have an empty body")

            print("Test 5: OPTIONS and unsupported methods return the expected HTTP metadata")
            status, headers, options_body = http_request("OPTIONS", proxy_url)
            assert_eq(status, 204, "OPTIONS status")
            assert_eq(headers.get("access-control-allow-origin"), "*", "OPTIONS CORS header")
            assert_contains("GET", headers.get("access-control-allow-methods", ""), "OPTIONS allowed methods")
            assert_eq(options_body, b"", "OPTIONS body")

            status, headers, post_body = http_request("POST", proxy_url)
            assert_eq(status, 405, "POST status")
            assert_contains("GET", headers.get("allow", ""), "Allow header")
            post_error = json.loads(post_body.decode("utf-8"))
            assert_eq(post_error.get("error"), "method not allowed", "POST error message")

            print("Test 6: a changed buffer is not used before TTL expiry, then produces a newly filtered cache entry")
            with MockState.lock:
                MockState.buffer_variant = "moved"

            status, headers, still_cached_body = http_request("GET", proxy_url)
            assert_eq(status, 200, "GET before TTL expiry")
            assert_eq(headers.get("x-cache"), "HIT", "changed upstream buffer should not bypass fresh cache")
            assert_eq(still_cached_body, initial_body, "fresh cache should remain stable")
            assert_eq(MockState.counts(), (1, 1), "changed buffer should not be fetched before expiry")

            wait_until_cache_expired(cache_dir)
            status, headers, moved_body = http_request("GET", proxy_url)
            assert_eq(status, 200, "GET after TTL expiry")
            assert_eq(headers.get("x-cache"), "MISS", "successful refresh should be MISS")
            assert_eq(MockState.counts(), (2, 2), "both datasets should be fetched for refresh")

            moved_document = json.loads(moved_body.decode("utf-8"))
            assert_eq(
                retained_ids(moved_document),
                EXPECTED_MOVED_IDS,
                "retained feature IDs after moving the buffer",
            )
            etag_moved = headers.get("etag")
            assert_true(etag_moved, "refreshed ETag should exist")
            assert_true(etag_moved != etag_initial, "ETag should change when filtered output changes")
            assert_eq(cache_data_path.read_bytes(), moved_body, "cache should be replaced by refreshed filtered data")

            print("Test 7: source failure after expiry serves the last filtered result as stale")
            wait_until_cache_expired(cache_dir)
            with MockState.lock:
                MockState.source_fail = True

            before_source_fail_counts = MockState.counts()
            status, headers, stale_source_body = http_request("GET", proxy_url)
            after_source_fail_counts = MockState.counts()

            assert_eq(status, 200, "stale response after source failure")
            assert_eq(headers.get("x-cache"), "STALE", "source failure should serve stale cache")
            assert_contains("110", headers.get("warning", ""), "stale Warning header")
            assert_contains("max-age=0", headers.get("cache-control", ""), "stale Cache-Control")
            assert_eq(stale_source_body, moved_body, "stale source-failure body")
            assert_eq(
                after_source_fail_counts,
                (before_source_fail_counts[0] + 1, before_source_fail_counts[1]),
                "buffer should not be fetched when source download already failed",
            )

            print("Test 8: buffer failure also serves the last filtered result as stale")
            with MockState.lock:
                MockState.source_fail = False
                MockState.buffer_fail = True

            before_buffer_fail_counts = MockState.counts()
            status, headers, stale_buffer_body = http_request("GET", proxy_url)
            after_buffer_fail_counts = MockState.counts()

            assert_eq(status, 200, "stale response after buffer failure")
            assert_eq(headers.get("x-cache"), "STALE", "buffer failure should serve stale cache")
            assert_eq(stale_buffer_body, moved_body, "stale buffer-failure body")
            assert_eq(
                after_buffer_fail_counts,
                (before_buffer_fail_counts[0] + 1, before_buffer_fail_counts[1] + 1),
                "source and buffer should both be attempted before buffer failure is known",
            )

            print("Test 9: recovery refreshes the cache, and --warm-cache forces another complete refresh")
            with MockState.lock:
                MockState.buffer_fail = False

            before_recovery_counts = MockState.counts()
            status, headers, recovered_body = http_request("GET", proxy_url)
            assert_eq(status, 200, "recovery GET status")
            assert_eq(headers.get("x-cache"), "MISS", "recovery should rebuild cache")
            assert_eq(recovered_body, moved_body, "recovered filtered output")
            assert_eq(
                MockState.counts(),
                (before_recovery_counts[0] + 1, before_recovery_counts[1] + 1),
                "recovery should fetch both datasets",
            )

            before_warm_counts = MockState.counts()
            warm_result = subprocess.run(
                [php_bin, str(configured_php_script), "--warm-cache"],
                cwd=str(php_dir),
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                timeout=30,
                check=False,
            )
            assert_eq(warm_result.returncode, 0, "--warm-cache exit status")
            assert_contains("cache refreshed", warm_result.stdout, "--warm-cache output")
            assert_contains("peak_memory_mib:", warm_result.stdout, "--warm-cache memory output")
            assert_eq(
                MockState.counts(),
                (before_warm_counts[0] + 1, before_warm_counts[1] + 1),
                "--warm-cache should fetch and filter both datasets even with a fresh cache",
            )

            print("Test 10: invalid buffer data returns 502 when no stale cache is available")
            clear_cache(cache_dir)
            with MockState.lock:
                MockState.buffer_variant = "invalid"

            before_invalid_counts = MockState.counts()
            status, headers, invalid_body = http_request("GET", proxy_url)
            assert_eq(status, 502, "invalid buffer status")
            assert_true(
                headers.get("content-type", "").startswith("application/json"),
                "invalid buffer error should be JSON",
            )
            invalid_error = json.loads(invalid_body.decode("utf-8"))
            assert_eq(
                invalid_error.get("error"),
                "could not fetch and filter valid GeoJSON",
                "invalid buffer error message",
            )
            assert_contains(
                "no valid Polygon or MultiPolygon",
                invalid_error.get("details", ""),
                "invalid buffer details",
            )
            assert_eq(
                MockState.counts(),
                (before_invalid_counts[0] + 1, before_invalid_counts[1] + 1),
                "invalid-buffer request should fetch both datasets",
            )
            assert_true(not cache_data_path.exists(), "failed refresh must not create cache data")
            assert_true(not cache_meta_path.exists(), "failed refresh must not create cache metadata")

            print()
            print("All tests passed.")

    finally:
        php_output = ""
        if php_process is not None:
            php_output = stop_process(php_process)

        mock_server.shutdown()
        mock_server.server_close()

        if args.show_php_log and php_output:
            print("\nPHP development server output:\n")
            print(php_output.rstrip())


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Integration test for the PHP uMap GeoJSON spatial filter proxy"
    )
    parser.add_argument(
        "php_script",
        help="Path to the spatial-filter PHP proxy",
    )
    parser.add_argument(
        "--php-bin",
        default="php",
        help="PHP binary to use. Default: php",
    )
    parser.add_argument(
        "--show-php-log",
        action="store_true",
        help="Print the PHP development server log after the test run",
    )

    args = parser.parse_args()

    try:
        run_tests(args)
    except Exception as exc:
        print(f"FAILED: {exc}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
