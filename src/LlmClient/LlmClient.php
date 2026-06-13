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

namespace App\LlmClient;

use App\Http\HttpHelper;
use App\Http\HttpException;

class LlmClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private HttpHelper $http;
    private ?array $lastResponse = null;
    private ?int $lastResponseTimeMs = null;

    /**
     * @param array           $providerConfig Configuration with base_url, api_key, model
     * @param HttpHelper|null $http           Optional HTTP client (defaults to new instance)
     */
    public function __construct(array $providerConfig, ?HttpHelper $http = null)
    {
        $this->baseUrl = rtrim($providerConfig['base_url'], '/');
        $this->apiKey  = $providerConfig['api_key'];
        $this->model   = $providerConfig['model'];
        $this->http    = $http ?? new HttpHelper();
    }

    /**
     * Send a chat completion request and return the response content.
     *
     * @param array $messages  Message array: [['role' => 'system', 'content' => '...'], ...]
     * @param array $options   Override defaults: temperature, max_tokens, timeout, etc.
     * @return string          The content from choices[0].message.content
     * @throws LlmException    On network failure, non-200 HTTP status, or malformed JSON
     */
    public function chat(array $messages, array $options = []): string
    {
        $payload = array_merge([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 4096,
        ], $options);

        $url = $this->baseUrl . '/chat/completions';
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $startTime = (int) (microtime(true) * 1000);

        try {
            // HttpHelper::post() JSON-encodes the payload and adds Content-Type header
            $response = $this->http->post($url, $payload, $headers);
        } catch (HttpException $e) {
            throw new LlmException(
                'HTTP request failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $endTime = (int) (microtime(true) * 1000);
        $this->lastResponseTimeMs = $endTime - $startTime;

        $httpCode = $response['http_code'];
        $responseBody = $response['body'];

        // Check HTTP status before parsing JSON — truncate body to 500 chars (T-01-07)
        if ($httpCode !== 200) {
            $truncated = mb_substr($responseBody, 0, 500);
            throw new LlmException(
                "API returned HTTP {$httpCode}: {$truncated}"
            );
        }

        try {
            $result = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                'Malformed JSON response from API: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $this->lastResponse = $result;

        if (!isset($result['choices'][0]['message']['content'])) {
            $finishReason = $result['choices'][0]['finish_reason'] ?? 'unknown';
            throw new LlmException(
                "API response missing message content (finish_reason: {$finishReason})"
            );
        }

        return $result['choices'][0]['message']['content'];
    }

    /**
     * Get response metadata from the last successful chat() call.
     *
     * @return array{model: string, usage: array, response_time_ms: int}
     */
    public function getLastResponseInfo(): array
    {
        $response = $this->lastResponse ?? [];
        $usage = $response['usage'] ?? [];
        $model = $response['model'] ?? $this->model;

        return [
            'model'            => $model,
            'usage'            => [
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
            ],
            'response_time_ms' => $this->lastResponseTimeMs ?? 0,
        ];
    }
}
