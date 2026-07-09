#!/usr/bin/env python3
import argparse
import gzip
import http.client
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse


GEOJSON = {
    "type": "FeatureCollection",
    "features": [
        {
            "type": "Feature",
            "properties": {
                "name": "Test Point"
            },
            "geometry": {
                "type": "Point",
                "coordinates": [14.2858, 48.3069]
            }
        }
    ]
}


class MockState:
    request_count = 0
    fail = False


class MockUpstreamHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        if self.path != "/source":
            self.send_response(404)
            self.end_headers()
            return

        MockState.request_count += 1

        if MockState.fail:
            body = b"upstream currently failing\n"
            self.send_response(503)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        raw = json.dumps(GEOJSON).encode("utf-8")
        gz = gzip.compress(raw)

        self.send_response(200)
        self.send_header("Content-Type", "application/gzip")
        self.send_header("Content-Length", str(len(gz)))
        self.end_headers()
        self.wfile.write(gz)


def get_free_port():
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def wait_for_port(port, timeout=10):
    deadline = time.time() + timeout

    while time.time() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.1)

    raise RuntimeError(f"Port {port} did not open in time")


def http_request(method, url, headers=None):
    headers = headers or {}
    parsed = urlparse(url)

    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query

    conn = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=10)
    conn.request(method, path, headers=headers)

    resp = conn.getresponse()
    body = resp.read()

    response_headers = {}
    for key, value in resp.getheaders():
        response_headers[key.lower()] = value

    conn.close()
    return resp.status, response_headers, body


def assert_eq(actual, expected, message):
    if actual != expected:
        raise AssertionError(f"{message}: expected {expected!r}, got {actual!r}")


def assert_true(condition, message):
    if not condition:
        raise AssertionError(message)


def start_mock_server(port):
    server = ThreadingHTTPServer(("127.0.0.1", port), MockUpstreamHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server


def start_php_server(php_bin, php_dir, php_port, env):
    process = subprocess.Popen(
        [php_bin, "-S", f"127.0.0.1:{php_port}", "-t", str(php_dir)],
        cwd=str(php_dir),
        env=env,
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


def run_tests(args):
    php_script = Path(args.php_script).resolve()

    if not php_script.is_file():
        raise RuntimeError(f"PHP script not found: {php_script}")

    php_bin = shutil.which(args.php_bin)
    if php_bin is None:
        raise RuntimeError(f"PHP binary not found: {args.php_bin}")

    mock_port = get_free_port()
    php_port = get_free_port()

    mock_server = start_mock_server(mock_port)

    with tempfile.TemporaryDirectory(prefix="geojson-proxy-test-") as tmp:
        cache_dir = Path(tmp) / "cache"

        env = os.environ.copy()
        env.update({
            "SOURCE_URL": f"http://127.0.0.1:{mock_port}/source",
            "CACHE_DIR": str(cache_dir),
            "CACHE_TTL": "2s",
            "STALE_TTL": "20s",
            "MAX_BYTES": "10485760",
            "HTTP_TIMEOUT": "5",
        })

        php_process = start_php_server(
            php_bin=php_bin,
            php_dir=php_script.parent,
            php_port=php_port,
            env=env,
        )

        try:
            proxy_url = f"http://127.0.0.1:{php_port}/{php_script.name}"

            print("Test 1: first GET fetches, decompresses and serves GeoJSON")
            status, headers, body = http_request("GET", proxy_url)

            assert_eq(status, 200, "first GET status")
            assert_true(headers.get("content-type", "").startswith("application/geo+json"), "content-type should be application/geo+json")
            assert_eq(headers.get("access-control-allow-origin"), "*", "CORS header")
            assert_eq(headers.get("x-cache"), "MISS", "first request should be cache MISS")
            assert_eq(MockState.request_count, 1, "upstream request count after first GET")

            parsed = json.loads(body.decode("utf-8"))
            assert_eq(parsed["type"], "FeatureCollection", "GeoJSON type")
            assert_eq(parsed["features"][0]["properties"]["name"], "Test Point", "GeoJSON feature property")

            etag = headers.get("etag")
            assert_true(etag, "ETag should be present")

            print("Test 2: second GET is served from cache")
            status, headers, body2 = http_request("GET", proxy_url)

            assert_eq(status, 200, "second GET status")
            assert_eq(headers.get("x-cache"), "HIT", "second request should be cache HIT")
            assert_eq(MockState.request_count, 1, "upstream should not be called again during fresh cache")
            assert_eq(body2, body, "cached body should equal first body")

            print("Test 3: If-None-Match returns 304")
            status, headers, body3 = http_request("GET", proxy_url, headers={"If-None-Match": etag})

            assert_eq(status, 304, "If-None-Match status")
            assert_eq(body3, b"", "304 response should have empty body")

            print("Test 4: HEAD returns headers without body")
            status, headers, body4 = http_request("HEAD", proxy_url)

            assert_eq(status, 200, "HEAD status")
            assert_true(headers.get("etag"), "HEAD should include ETag")
            assert_eq(body4, b"", "HEAD response should have empty body")

            print("Test 5: after cache TTL, failed upstream serves stale cache")
            time.sleep(3)
            MockState.fail = True

            status, headers, stale_body = http_request("GET", proxy_url)

            assert_eq(status, 200, "stale GET status")
            assert_eq(headers.get("x-cache"), "STALE", "request should serve stale cache")
            assert_eq(stale_body, body, "stale body should equal cached body")
            assert_true(MockState.request_count >= 2, "upstream should have been retried after cache expiry")

            print()
            print("All tests passed.")

        finally:
            php_process.terminate()
            try:
                php_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                php_process.kill()

            mock_server.shutdown()


def main():
    parser = argparse.ArgumentParser(
        description="Integration test for geojson-proxy.php"
    )
    parser.add_argument(
        "php_script",
        help="Path to geojson-proxy.php",
    )
    parser.add_argument(
        "--php-bin",
        default="php",
        help="PHP binary to use. Default: php",
    )

    args = parser.parse_args()

    try:
        run_tests(args)
    except Exception as e:
        print(f"FAILED: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
