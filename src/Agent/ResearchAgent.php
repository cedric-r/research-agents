<?php

declare(strict_types=1);

namespace App\Agent;

use App\Config\Loader;
use App\LlmClient\LlmClient;
use App\LlmClient\LlmException;
use App\Log\Logger;

class ResearchAgent
{
    private array $config;
    private string $soul;
    private LlmClient $llm;
    private ?Logger $logger;
    private string $correlationId;

    /**
     * @param string           $agentDir     Path to the agent's config directory
     * @param Loader           $configLoader Config file loader instance
     * @param Logger|null      $logger       Optional logger (creates a no-op stub if null)
     */
    public function __construct(
        string $agentDir,
        Loader $configLoader,
        ?Logger $logger = null
    ) {
        $this->logger = $logger;
        $this->correlationId = $logger ? $logger->getCorrelationId() : Logger::generateCorrelationId();

        // Load primary agent config with required fields
        $this->config = $configLoader->load(
            $agentDir . '/config.json',
            required: ['provider', 'model', 'api_key'],
            types: ['provider' => 'string', 'model' => 'string', 'api_key' => 'string']
        );

        // Load preferences (optional — no required fields in Phase 1)
        $configLoader->load(
            $agentDir . '/preferences.json',
            required: [],
            types: []
        );

        // Read SOUL.md — must exist and be non-empty
        $soulPath = $agentDir . '/SOUL.md';
        if (!file_exists($soulPath)) {
            throw new \RuntimeException("SOUL.md not found: {$soulPath}");
        }
        $soulContent = file_get_contents($soulPath);
        if ($soulContent === false || trim($soulContent) === '') {
            throw new \RuntimeException("SOUL.md is empty or unreadable: {$soulPath}");
        }
        $this->soul = $soulContent;

        // Resolve provider base URL and construct LLM client
        $baseUrl = $this->resolveBaseUrl($this->config);
        $this->llm = new LlmClient([
            'base_url' => $baseUrl,
            'api_key'  => $this->config['api_key'],
            'model'    => $this->config['model'],
            'provider' => $this->config['provider'],
        ]);
    }

    /**
     * Run research on a question and return structured result with metadata.
     *
     * @param  string $question Research question (capped at 2000 chars)
     * @return array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string}
     * @throws \RuntimeException On SOUL.md issues
     * @throws LlmException      On LLM API errors
     */
    public function research(string $question): array
    {
        // T-01-09: Cap question at 2000 characters to prevent prompt injection surface
        $question = mb_substr($question, 0, 2000);

        if ($this->logger) {
            $this->logger->info('Agent research started', [
                'question' => mb_substr($question, 0, 200),
                'model'    => $this->config['model'],
            ]);
        }

        $messages = [
            ['role' => 'system', 'content' => $this->soul],
            ['role' => 'user',   'content' => $question],
        ];

        try {
            $startTime = (int) (microtime(true) * 1000);
            $answer = $this->llm->chat($messages);
            $endTime = (int) (microtime(true) * 1000);
            $responseTimeMs = $endTime - $startTime;

            $responseInfo = $this->llm->getLastResponseInfo();

            if ($this->logger) {
                $this->logger->info('Agent research completed', [
                    'model'            => $responseInfo['model'],
                    'response_time_ms' => $responseTimeMs,
                    'prompt_tokens'    => $responseInfo['usage']['prompt_tokens'],
                    'completion_tokens'=> $responseInfo['usage']['completion_tokens'],
                ]);
            }

            return [
                'answer'           => $answer,
                'model'            => $responseInfo['model'],
                'response_time_ms' => $responseTimeMs,
                'usage'            => $responseInfo['usage'],
                'correlation_id'   => $this->correlationId,
            ];
        } catch (LlmException $e) {
            if ($this->logger) {
                $this->logger->error('Agent research failed', [
                    'error'   => $e->getMessage(),
                    'model'   => $this->config['model'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Resolve the provider API base URL from config.
     *
     * Priority:
     * 1. Explicit provider_base_url override in config
     * 2. Known provider name mapping
     *
     * @throws \RuntimeException On unknown provider without base_url override
     */
    private function resolveBaseUrl(array $config): string
    {
        // Allow explicit override via config
        if (!empty($config['provider_base_url'])) {
            return rtrim($config['provider_base_url'], '/');
        }

        return match ($config['provider']) {
            'deepseek'  => 'https://api.deepseek.com',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => throw new \RuntimeException(
                "Unknown provider '{$config['provider']}'. Set provider_base_url in config.json to use a custom endpoint."
            ),
        };
    }
}
