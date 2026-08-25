<?php

declare(strict_types=1);

namespace GeoJsonProxy\Http;

use RuntimeException;
use GeoJsonProxy\Config;

/**
 * HTTP client for fetching remote resources
 */
final class Fetcher
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Fetch URL content
     */
    public function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            return $this->fetchWithCurl($url);
        }
        return $this->fetchWithFileGetContents($url);
    }

    /**
     * Fetch using cURL
     */
    private function fetchWithCurl(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('could not initialize curl');
        }

        $body = '';
        $tooLarge = false;
        $maxBytes = $this->config->getInt('max_bytes');

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->config->getInt('http_timeout'),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->config->getInt('http_timeout')),
            CURLOPT_USERAGENT => $this->config->getString('user_agent'),
            CURLOPT_HTTPHEADER => [
                'Accept: application/geo+json, application/json, application/gzip, */*',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_FAILONERROR => false,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        $status = (int) ($info['http_code'] ?? 0);

        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException('remote response exceeds MAX_BYTES');
        }

        if ($ok === false) {
            throw new RuntimeException('curl error: ' . $error);
        }

        if ($status !== 200) {
            throw new RuntimeException('remote source returned HTTP ' . $status . ' (expected 200)');
        }

        return $body;
    }

    /**
     * Fetch using file_get_contents
     */
    private function fetchWithFileGetContents(string $url): string
    {
        if (!ini_get('allow_url_fopen')) {
            throw new RuntimeException('neither curl nor allow_url_fopen is available');
        }

        $maxBytes = $this->config->getInt('max_bytes');
        $timeout = $this->config->getInt('http_timeout');
        $userAgent = $this->config->getString('user_agent');

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: {$userAgent}\r\n"
                    . "Accept: application/geo+json, application/json, application/gzip, */*\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);

        if ($body === false) {
            throw new RuntimeException('could not fetch remote URL');
        }

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('remote response exceeds MAX_BYTES');
        }

        $status = $this->parseHttpStatus($http_response_header ?? []);
        if ($status !== null && $status !== 200) {
            throw new RuntimeException('remote source returned HTTP ' . $status . ' (expected 200)');
        }

        return $body;
    }

    /**
     * Parse HTTP status from headers
     */
    private function parseHttpStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        return $status;
    }
}
