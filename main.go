package main

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
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

type cacheEntry struct {
	body       []byte
	etag       string
	fetchedAt  time.Time
	expiresAt  time.Time
	staleUntil time.Time
}

var version = "dev"

var (
	sourceURL = mustEnv("SOURCE_URL")

	listenAddr = envString("LISTEN_ADDR", ":8080")
	cacheTTL   = envDuration("CACHE_TTL", 5*time.Minute)
	staleTTL   = envDuration("STALE_TTL", 24*time.Hour)
	maxBytes   = envInt64("MAX_BYTES", 100*1024*1024) // 100 MB

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
	http.HandleFunc("/data.geojson", handleGeoJSON)
	http.HandleFunc("/healthz", handleHealthz)

	log.Printf("listening on %s", listenAddr)
	log.Printf("source URL: %s", sourceURL)
	log.Printf("cache TTL: %s, stale TTL: %s, max bytes: %d", cacheTTL, staleTTL, maxBytes)

	log.Fatal(http.ListenAndServe(listenAddr, nil))
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

	// Verhindert, dass viele gleichzeitige Requests alle parallel die Quelle laden.
	refreshMu.Lock()
	defer refreshMu.Unlock()

	// Während wir auf refreshMu gewartet haben, könnte jemand anderer den Cache erneuert haben.
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

	entry := &cacheEntry{
		body:       body,
		etag:       makeETag(body),
		fetchedAt:  time.Now(),
		expiresAt:  time.Now().Add(cacheTTL),
		staleUntil: time.Now().Add(cacheTTL + staleTTL),
	}

	cacheMu.Lock()
	cache = entry
	cacheMu.Unlock()

	serveCache(w, r, entry, "MISS")
}

func fetchAndPrepare(ctx context.Context) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, sourceURL, nil)
	if err != nil {
		return nil, err
	}

	req.Header.Set("User-Agent", "umap-geojson-gzip-proxy/1.0")
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

	body, err := readLimited(resp.Body, maxBytes)
	if err != nil {
		return nil, err
	}

	// Fall 1: Server liefert echte gzip-Datei als Inhalt, z. B. application/gzip.
	// Fall 2: Server liefert HTTP Content-Encoding gzip.
	//         Dann entpackt Go normalerweise bereits automatisch; dann greift das hier nicht.
	if isGzip(body) {
		body, err = gunzipLimited(body, maxBytes)
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
	w.Header().Set("Cache-Control", fmt.Sprintf("public, max-age=%d", int(cacheTTL.Seconds())))
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

func mustEnv(key string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		log.Fatalf("missing required environment variable %s", key)
	}
	return value
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