<?php

/**
 * ResearchAgents -- multi-agent research and debate system.
 * Copyright (C) 2026 Cedric Raguenaud
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */



declare(strict_types=1);

namespace App\Http;

/**
 * Centralized HTTP utility with timeout enforcement.
 *
 * All external HTTP calls go through this class to ensure consistent
 * timeout values, error handling, and security controls.
 *
 * @template T of array{body: string, http_code: int}
 */
class HttpHelper
{
    private const USER_AGENT = 'ResearchAgents/1.0';

    private int $timeout;
    private int $connectTimeout;

    /**
     * @param int $timeout        CURLOPT_TIMEOUT — max seconds for the full request
     * @param int $connectTimeout CURLOPT_CONNECTTIMEOUT — max seconds for connection
     */
    public function __construct(int $timeout = 60, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Perform a GET request.
     *
     * @param  string   $url     Full URL to request
     * @param  string[] $headers Extra headers (e.g., ['Accept: application/json'])
     * @return array{body: string, http_code: int}
     * @throws HttpException On curl failure or unreachable host
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * Perform a POST request with JSON-encoded body.
     *
     * @param  string   $url     Full URL to request
     * @param  array    $data    Payload data (JSON-encoded automatically)
     * @param  string[] $headers Extra headers
     * @return array{body: string, http_code: int}
     * @throws HttpException On curl failure, JSON encoding failure, or unreachable host
     */
    public function post(string $url, array $data, array $headers = []): array
    {
        try {
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpException(
                'Failed to encode POST payload: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $this->request('POST', $url, $payload, $headers);
    }

    /**
     * Perform multiple GET requests in parallel.
     *
     * Uses curl_multi_exec for concurrent HTTP requests. Useful for
     * Phase 3+ parallelism where multiple tools are queried simultaneously.
     *
     * @param  string[]           $urls    Associative or indexed array of URLs
     * @param  string[]           $headers Headers applied to all requests
     * @return array<string, array{body: string, http_code: int, error: string}>  Keyed by original keys
     */
    public function getMulti(array $urls, array $headers = []): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];

        foreach ($urls as $key => $url) {
            $ch = curl_init((string) $url);
            curl_setopt_array($ch, $this->buildCurlOptions('GET', null, $headers));
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$key] = $ch;
        }

        $running = 0;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($status === CURLM_CALL_MULTI_PERFORM) {
                continue;
            }
            $selRet = curl_multi_select($multiHandle, 5);
            if ($selRet === -1) {
                usleep(10000); // 10ms fallback if select interrupted
            }
        } while ($running > 0);

        foreach ($curlHandles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $results[(string) $key] = [
                'body'      => $body !== false ? $body : '',
                'http_code'  => $httpCode,
                'error'     => $error !== '' ? $error : '',
            ];
            curl_multi_remove_handle($multiHandle, $ch);
            // curl_close deliberately omitted — deprecated since PHP 8.5, handle auto-closes
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    /**
     * Execute a single HTTP request via curl.
     *
     * @return array{body: string, http_code: int}
     * @throws HttpException
     */
    private function request(string $method, string $url, ?string $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpException('Failed to initialize curl handle for: ' . $this->sanitizeUrlForError($url));
        }

        $options = $this->buildCurlOptions($method, $payload, $headers);
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        // curl_close deliberately omitted — deprecated since PHP 8.5, handle auto-closes

        if ($body === false) {
            throw new HttpException(
                sprintf(
                    'HTTP request failed (errno %d): %s',
                    $curlErrno,
                    $this->truncateErrorMessage($curlError)
                )
            );
        }

        return [
            'body'      => $body,
            'http_code'  => $httpCode,
        ];
    }

    /**
     * Build the default set of curl options.
     *
     * @return array<int, mixed>
     */
    private function buildCurlOptions(string $method, ?string $payload, array $headers): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            // Fork safety: disable libcurl's SIGALRM timeout mechanism (CURLOPT_TIMEOUT uses poll()/select() instead)
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => $headers ?: [],
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload ?? '';
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        return $options;
    }

    /**
     * Sanitize a URL for inclusion in error messages — strip query params
     * to avoid leaking API keys (T-02-03).
     */
    private function sanitizeUrlForError(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'http';
        $host   = $parsed['host'] ?? 'unknown';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path   = $parsed['path'] ?? '';

        // Strip query params and fragments to avoid leaking credentials
        return "{$scheme}://{$host}{$port}{$path}";
    }

    /**
     * Truncate error messages to avoid leaking sensitive info (T-02-03).
     */
    private function truncateErrorMessage(string $message): string
    {
        if (mb_strlen($message) > 200) {
            return mb_substr($message, 0, 200) . '...';
        }
        return $message;
    }
}
