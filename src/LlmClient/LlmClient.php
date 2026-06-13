<?php

declare(strict_types=1);

namespace App\LlmClient;

class LlmClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private ?array $lastResponse = null;
    private ?int $lastResponseTimeMs = null;

    public function __construct(array $providerConfig)
    {
        $this->baseUrl = rtrim($providerConfig['base_url'], '/');
        $this->apiKey  = $providerConfig['api_key'];
        $this->model   = $providerConfig['model'];
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

        try {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                "Failed to serialize request payload: " . $e->getMessage(),
                0,
                $e
            );
        }

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ResearchAgents/1.0',
        ]);

        $startTime = (int) (microtime(true) * 1000);
        $response = curl_exec($ch);
        $endTime = (int) (microtime(true) * 1000);
        $this->lastResponseTimeMs = $endTime - $startTime;

        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Pitfall 3: curl_exec returns false, not empty string, on network failure
        if ($response === false) {
            throw new LlmException(
                "HTTP request failed (errno {$curlErrno}): {$curlError}"
            );
        }

        // Check HTTP status before parsing JSON — but also pass through for error body
        if ($httpCode !== 200) {
            // Truncate response body to 500 chars to avoid leaking API keys (T-01-07)
            $truncated = mb_substr($response, 0, 500);
            throw new LlmException(
                "API returned HTTP {$httpCode}: {$truncated}"
            );
        }

        // Pitfall 2: json_decode can throw on malformed JSON
        try {
            $result = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                "Malformed JSON response from API: " . $e->getMessage(),
                0,
                $e
            );
        }

        $this->lastResponse = $result;

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Get response metadata from the last successful chat() call.
     *
     * @return array{model: string, usage: array, response_time_ms: int}
     */
    public function getLastResponseInfo(): array
    {
        $usage = $this->lastResponse['usage'] ?? [];
        $model = $this->lastResponse['model'] ?? $this->model;

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
