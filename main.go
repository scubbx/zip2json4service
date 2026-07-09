package main

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
)

var version = "dev"

type config struct {
	sourceURL  string
	listenAddr string
	cacheTTL   time.Duration
	staleTTL   time.Duration
	maxBytes   int64
	showVersion bool
}

type cacheEntry struct {
	body       []byte
	etag       string
	fetchedAt  time.Time
	expiresAt  time.Time
	staleUntil time.Time
}

var (
	cfg config

	client = &http.Client{
		Timeout: 30 * time.Second,
		Transport: &http.Transport{
			Proxy:                 http.ProxyFromEnvironment,
			MaxIdleConns:          20,
			MaxIdleConnsPerHost:   10,
			IdleConnTimeout:       90 * time.Second,
			ResponseHeaderTimeout: 20 * time.Second,
		},
	}

	cacheMu   sync.RWMutex
	cache     *cacheEntry
	refreshMu sync.Mutex
)

func main() {
	cfg = parseConfig()

	if cfg.showVersion {
		fmt.Println(version)
		return
	}

	http.HandleFunc("/data.geojson", handleGeoJSON)
	http.HandleFunc("/healthz", handleHealthz)

	log.Printf("listening on %s", cfg.listenAddr)
	log.Printf("source URL: %s", cfg.sourceURL)
	log.Printf("cache TTL: %s, stale TTL: %s, max bytes: %d", cfg.cacheTTL, cfg.staleTTL, cfg.maxBytes)

	log.Fatal(http.ListenAndServe(cfg.listenAddr, nil))
}

func parseConfig() config {
	c := config{
		sourceURL:  envString("SOURCE_URL", ""),
		listenAddr: envString("LISTEN_ADDR", ":8080"),
		cacheTTL:   envDuration("CACHE_TTL", 5*time.Minute),
		staleTTL:   envDuration("STALE_TTL", 24*time.Hour),
		maxBytes:   envInt64("MAX_BYTES", 100*1024*1024),
	}

	flag.StringVar(&c.sourceURL, "source-url", c.sourceURL, "Remote source URL to fetch. Can also be set via SOURCE_URL.")
	flag.StringVar(&c.listenAddr, "listen-addr", c.listenAddr, "Address and port to listen on. Can also be set via LISTEN_ADDR.")
	flag.DurationVar(&c.cacheTTL, "cache-ttl", c.cacheTTL, "Fresh cache lifetime, for example 30s, 5m, 1h. Can also be set via CACHE_TTL.")
	flag.DurationVar(&c.staleTTL, "stale-ttl", c.staleTTL, "How long stale cached data may be served if refresh fails. Can also be set via STALE_TTL.")
	flag.Int64Var(&c.maxBytes, "max-bytes", c.maxBytes, "Maximum allowed source response size in bytes. Can also be set via MAX_BYTES.")
	flag.BoolVar(&c.showVersion, "version", false, "Print version and exit.")

	flag.Usage = func() {
		out := flag.CommandLine.Output()

		fmt.Fprintf(out, `uMap GeoJSON gzip Proxy

Fetches a remote GeoJSON source, decompresses gzip payloads if needed,
validates the result as JSON, caches it in memory, and serves it as plain
GeoJSON for uMap or similar clients.

Usage:

  %[1]s --source-url URL [options]

Examples:

  %[1]s --source-url "https://example.com/export"

  %[1]s \
    --source-url "https://example.com/api/data?id=123" \
    --listen-addr ":8080" \
    --cache-ttl 5m \
    --stale-ttl 24h

Environment based usage:

  SOURCE_URL="https://example.com/export" %[1]s

uMap configuration:

  URL:    https://your-domain.example/data.geojson
  Format: GeoJSON

Options:

`, os.Args[0])

		flag.PrintDefaults()

		fmt.Fprintf(out, `

Environment variables:

  SOURCE_URL   Remote source URL. Required unless --source-url is set.
  LISTEN_ADDR  Address and port to listen on. Default: :8080
  CACHE_TTL    Fresh cache lifetime. Default: 5m
  STALE_TTL    Stale cache lifetime after refresh errors. Default: 24h
  MAX_BYTES    Maximum allowed source response size in bytes. Default: 104857600

Endpoints:

  GET  /data.geojson   Returns the proxied GeoJSON
  HEAD /data.geojson   Returns headers only
  GET  /healthz        Health check endpoint

Notes:

  The source URL does not need a .geojson or .gz file extension.
  Gzip is detected by inspecting the response body for gzip magic bytes.
  CLI flags override environment variables.
`)
	}

	flag.Parse()

	c.sourceURL = strings.TrimSpace(c.sourceURL)
	c.listenAddr = strings.TrimSpace(c.listenAddr)

	if c.sourceURL == "" && !c.showVersion {
		fmt.Fprintln(os.Stderr, "error: missing required source URL")
		fmt.Fprintln(os.Stderr)
		flag.Usage()
		os.Exit(2)
	}

	if c.cacheTTL <= 0 {
		fmt.Fprintln(os.Stderr, "error: --cache-ttl must be greater than 0")
		os.Exit(2)
	}

	if c.staleTTL < 0 {
		fmt.Fprintln(os.Stderr, "error: --stale-ttl must not be negative")
		os.Exit(2)
	}

	if c.maxBytes <= 0 {
		fmt.Fprintln(os.Stderr, "error: --max-bytes must be greater than 0")
		os.Exit(2)
	}

	return c
}

func handleHealthz(w http.ResponseWriter, r *http.Request) {
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte("ok\n"))
}

func handleGeoJSON(w http.ResponseWriter, r *http.Request) {
	if r.Method == http.MethodOptions {
		setCORSHeaders(w)
		w.WriteHeader(http.StatusNoContent)
		return
	}

	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	now := time.Now()

	if entry, ok := getFreshCache(now); ok {
		serveCache(w, r, entry, "HIT")
		return
	}

	refreshMu.Lock()
	defer refreshMu.Unlock()

	if entry, ok := getFreshCache(time.Now()); ok {
		serveCache(w, r, entry, "HIT")
		return
	}

	body, err := fetchAndPrepare(r.Context())
	if err != nil {
		log.Printf("refresh failed: %v", err)

		if entry, ok := getStaleCache(time.Now()); ok {
			w.Header().Set("Warning", `110 - "Response is stale because source refresh failed"`)
			serveCache(w, r, entry, "STALE")
			return
		}

		http.Error(w, "could not fetch valid GeoJSON from source", http.StatusBadGateway)
		return
	}

	now = time.Now()
	entry := &cacheEntry{
		body:       body,
		etag:       makeETag(body),
		fetchedAt:  now,
		expiresAt:  now.Add(cfg.cacheTTL),
		staleUntil: now.Add(cfg.cacheTTL + cfg.staleTTL),
	}

	cacheMu.Lock()
	cache = entry
	cacheMu.Unlock()

	serveCache(w, r, entry, "MISS")
}

func fetchAndPrepare(ctx context.Context) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, cfg.sourceURL, nil)
	if err != nil {
		return nil, err
	}

	req.Header.Set("User-Agent", "umap-geojson-gzip-proxy/"+version)
	req.Header.Set("Accept", "application/geo+json, application/json, */*")

	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		return nil, fmt.Errorf("source returned HTTP %d: %s", resp.StatusCode, strings.TrimSpace(string(snippet)))
	}

	body, err := readLimited(resp.Body, cfg.maxBytes)
	if err != nil {
		return nil, err
	}

	if isGzip(body) {
		body, err = gunzipLimited(body, cfg.maxBytes)
		if err != nil {
			return nil, err
		}
	}

	if !json.Valid(body) {
		return nil, errors.New("decoded response is not valid JSON")
	}

	return body, nil
}

func readLimited(r io.Reader, limit int64) ([]byte, error) {
	lr := io.LimitReader(r, limit+1)

	body, err := io.ReadAll(lr)
	if err != nil {
		return nil, err
	}

	if int64(len(body)) > limit {
		return nil, fmt.Errorf("response too large, limit is %d bytes", limit)
	}

	return body, nil
}

func gunzipLimited(data []byte, limit int64) ([]byte, error) {
	gr, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("could not open gzip data: %w", err)
	}
	defer gr.Close()

	body, err := readLimited(gr, limit)
	if err != nil {
		return nil, fmt.Errorf("could not decompress gzip data: %w", err)
	}

	return body, nil
}

func isGzip(data []byte) bool {
	return len(data) >= 2 && data[0] == 0x1f && data[1] == 0x8b
}

func getFreshCache(now time.Time) (*cacheEntry, bool) {
	cacheMu.RLock()
	defer cacheMu.RUnlock()

	if cache == nil {
		return nil, false
	}

	return cache, now.Before(cache.expiresAt)
}

func getStaleCache(now time.Time) (*cacheEntry, bool) {
	cacheMu.RLock()
	defer cacheMu.RUnlock()

	if cache == nil {
		return nil, false
	}

	return cache, now.Before(cache.staleUntil)
}

func serveCache(w http.ResponseWriter, r *http.Request, entry *cacheEntry, cacheStatus string) {
	setCORSHeaders(w)

	w.Header().Set("Content-Type", "application/geo+json; charset=utf-8")
	w.Header().Set("Cache-Control", fmt.Sprintf("public, max-age=%d", int(cfg.cacheTTL.Seconds())))
	w.Header().Set("ETag", entry.etag)
	w.Header().Set("X-Cache", cacheStatus)
	w.Header().Set("X-Cache-Fetched-At", entry.fetchedAt.UTC().Format(time.RFC3339))

	if r.Header.Get("If-None-Match") == entry.etag {
		w.WriteHeader(http.StatusNotModified)
		return
	}

	if r.Method == http.MethodHead {
		w.WriteHeader(http.StatusOK)
		return
	}

	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(entry.body)
}

func setCORSHeaders(w http.ResponseWriter) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, HEAD, OPTIONS")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, If-None-Match")
}

func makeETag(body []byte) string {
	sum := sha256.Sum256(body)
	return `"` + hex.EncodeToString(sum[:]) + `"`
}

func envString(key string, fallback string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	return value
}

func envDuration(key string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	d, err := time.ParseDuration(value)
	if err != nil {
		log.Fatalf("invalid duration for %s: %s", key, value)
	}

	return d
}

func envInt64(key string, fallback int64) int64 {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	i, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		log.Fatalf("invalid integer for %s: %s", key, value)
	}

	return i
}