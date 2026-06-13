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

namespace App\Tool;

use App\Http\HttpHelper;
use App\Log\Logger;

/**
 * Web search tool using Brave Search API.
 *
 * Provides formatted web search results as a context block for LLM prompts.
 * Configured via $config array with api_key and optional count.
 * Gracefully degrades on API errors — returns empty string with log entry.
 */
class WebSearch
{
    private const BASE_URL = 'https://api.search.brave.com/res/v1/web/search';

    private HttpHelper $http;
    private array $config;
    private ?Logger $logger;

    /**
     * @param HttpHelper   $http   Centralized HTTP utility
     * @param array        $config Configuration:
     *                             - 'api_key' (string, required): Brave Search subscription token
     *                             - 'count'   (int, optional, default 10): max results per query
     * @param Logger|null  $logger Optional logger for tool activity
     */
    public function __construct(HttpHelper $http, array $config, ?Logger $logger = null)
    {
        $this->http = $http;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Execute a web search query via Brave Search API.
     *
     * @param  array  $params Parameters:
     *                        - 'q'     (string, required): search query
     *                        - 'count' (int, optional): max results (overrides config default)
     * @return string Formatted context block with search results, or empty string on failure
     * @throws \RuntimeException If query parameter is missing
     */
    public function execute(array $params): string
    {
        if (empty($this->config['api_key'])) {
            throw new \RuntimeException('WebSearch: Brave Search API key is required');
        }

        $query = $params['q'] ?? '';

        if (trim($query) === '') {
            throw new \RuntimeException('WebSearch: parameter \'q\' (search query) is required');
        }

        $count = (int) ($params['count'] ?? $this->config['count'] ?? 10);
        $count = max(1, min(20, $count));

        $url = self::BASE_URL . '?q=' . urlencode($query) . '&count=' . $count;

        $headers = [
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'X-Subscription-Token: ' . $this->config['api_key'],
        ];

        if ($this->logger) {
            $this->logger->info('WebSearch: requesting', [
                'query' => mb_substr($query, 0, 100),
                'count' => $count,
            ]);
        }

        try {
            $response = $this->http->get($url, $headers);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warn('WebSearch: HTTP request failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return '';
        }

        if ($response['http_code'] !== 200) {
            if ($this->logger) {
                $this->logger->warn('WebSearch: non-200 response', [
                    'http_code' => $response['http_code'],
                ]);
            }
            return '';
        }

        try {
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->logger) {
                $this->logger->warn('WebSearch: JSON parse failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return '';
        }

        $results = $data['web']['results'] ?? [];

        if (empty($results)) {
            return '';
        }

        return $this->formatResults($results);
    }

    /**
     * Format search results as a context block for LLM prompts.
     *
     * @param  array  $results Raw results from Brave Search API
     * @return string Formatted context block string
     */
    private function formatResults(array $results): string
    {
        $lines = ["## Web Search Results"];

        foreach ($results as $result) {
            $title = mb_substr(trim($result['title'] ?? ''), 0, 200);
            $snippet = mb_substr(trim($result['snippet'] ?? $result['description'] ?? ''), 0, 300);
            $url = $result['url'] ?? '';

            $lines[] = sprintf(
                '- %s: %s (%s)',
                $title,
                $snippet,
                $url
            );
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
