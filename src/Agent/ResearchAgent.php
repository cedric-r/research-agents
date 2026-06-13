<?php

declare(strict_types=1);

namespace App\Agent;

use App\Config\Loader;
use App\LlmClient\LlmClient;
use App\LlmClient\LlmException;
use App\Log\Logger;
use App\Session\ProgressLogger;
use App\Tool\ToolRegistry;

class ResearchAgent
{
    private array $config;
    private array $preferences;
    private string $soul;
    private LlmClient $llm;
    private ?Logger $logger;
    private string $correlationId;
    private ?ToolRegistry $toolRegistry = null;
    private ?ProgressLogger $progressLogger = null;
    private string $agentName = '';

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
        $this->preferences = $configLoader->load(
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
     * Inject the tool registry for tool-enabled research.
     *
     * Must be called before research() for tools to be available.
     * Tools are configured via preferences.json per agent.
     */
    public function setToolRegistry(ToolRegistry $registry): void
    {
        $this->toolRegistry = $registry;
    }

    /**
     * Set progress logger for real-time event emission (Phase 5, D-11).
     */
    public function setProgressLogger(?ProgressLogger $logger): void
    {
        $this->progressLogger = $logger;
    }

    /**
     * Set the agent's display name (used in progress events).
     */
    public function setAgentName(string $name): void
    {
        $this->agentName = $name;
    }

    /**
     * Build tool context block from configured tools.
     *
     * Checks preferences.json['tools'] to determine which tools to run:
     * - 'llm_only': if true, skip all tools
     * - 'web_search': if true, run web_search with the question
     * - 'paper_search': if true, run paper_search with the question
     *
     * @param  string $question The user's research question
     * @return string           Combined tool context, or empty string if no tools configured
     */
    private function buildToolContext(string $question): string
    {
        if ($this->toolRegistry === null) {
            return '';
        }

        $tools = $this->preferences['tools'] ?? [];

        // llm_only flag skips all tool queries
        if (!empty($tools['llm_only'])) {
            return '';
        }

        $contextParts = [];

        if (!empty($tools['web_search'])) {
            try {
                $result = $this->toolRegistry->run('web_search', ['q' => $question]);
                if ($result !== '') {
                    $contextParts[] = $result;
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warn('ResearchAgent: web_search failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (!empty($tools['paper_search'])) {
            try {
                $result = $this->toolRegistry->run('paper_search', ['q' => $question]);
                if ($result !== '') {
                    $contextParts[] = $result;
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warn('ResearchAgent: paper_search failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return implode("\n\n", $contextParts);
    }

    /**
     * Run research on a question and return structured result with metadata.
     *
     * @param  string      $question Research question (capped at 2000 chars)
     * @param  float|null  $deadline Optional absolute Unix timestamp deadline for Layer 4 cooperative timeout (D-16)
     * @return array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string}
     * @throws \RuntimeException On SOUL.md issues or deadline exceeded
     * @throws LlmException      On LLM API errors
     */
    public function research(string $question, ?float $deadline = null): array
    {
        // T-01-09: Cap question at 2000 characters to prevent prompt injection surface
        $question = mb_substr($question, 0, 2000);

        if ($this->logger) {
            $this->logger->info('Agent research started', [
                'question' => mb_substr($question, 0, 200),
                'model'    => $this->config['model'],
            ]);
        }

        // Emit started progress event (Phase 5, D-11)
        $this->emitProgress('started', [
            'question' => mb_substr($question, 0, 100),
            'model'    => $this->config['model'],
        ]);

        // Build system content: soul + optional tool context
        $systemContent = $this->soul;

        // Layer 4 deadline check: skip tool building if deadline is imminent (D-16)
        if ($deadline !== null && microtime(true) + 5 > $deadline) {
            $toolContext = '';
            if ($this->logger) {
                $this->logger->info('ResearchAgent: deadline imminent, skipping tool context');
            }
        } else {
            $toolContext = $this->buildToolContext($question);
        }

        if ($toolContext !== '') {
            $systemContent .= "\n\n" . $toolContext;

            // Emit tool events based on what was configured (Phase 5, D-11)
            $tools = $this->preferences['tools'] ?? [];
            if (!empty($tools['web_search'])) {
                $this->emitProgress('web_search', ['tool' => 'web_search']);
            }
            if (!empty($tools['paper_search'])) {
                $this->emitProgress('paper_search', ['tool' => 'paper_search']);
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemContent],
            ['role' => 'user',   'content' => $question],
        ];

        // Layer 4 deadline check: before LLM call (D-16)
        if ($deadline !== null && microtime(true) + 5 > $deadline) {
            throw new \RuntimeException('ResearchAgent: deadline reached before LLM call');
        }

        // Emit llm_call progress event (Phase 5, D-11)
        $this->emitProgress('llm_call', ['model' => $this->config['model']]);

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

            // Emit completed progress event (Phase 5, D-11)
            $this->emitProgress('completed', [
                'response_time_ms' => $responseTimeMs,
                'model'            => $responseInfo['model'],
            ]);

            return [
                'answer'           => $answer,
                'model'            => $responseInfo['model'],
                'response_time_ms' => $responseTimeMs,
                'usage'            => $responseInfo['usage'],
                'correlation_id'   => $this->correlationId,
            ];
        } catch (LlmException $e) {
            // Emit failed progress event (Phase 5, D-11)
            $this->emitProgress('failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
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
     * Produce a structured peer critique of other agents' answers (Round 2).
     *
     * Builds a system prompt from SOUL.md + critique template, anonymizes
     * peer answers (labels them "Peer 1", "Peer 2" instead of agent names),
     * calls the LLM, and returns a structured JSON critique.
     *
     * @param  string      $question       Original research question
     * @param  array       $peerAnswers    Peer answers to critique: [[
     *                                     'answer' => string, 'scores' => array, 'agent' => string, ...
     *                                     ], ...]
     *                                     The 'agent' key is used internally for exclusion check only.
     *                                     Other keys (e.g., 'scores') are passed through for display.
     * @param  string      $critiquePrompt Complete system prompt text with {peer_answers} already resolved
     * @param  float|null  $deadline       Optional absolute Unix timestamp deadline for cooperative timeout
     * @return array{critiques: string, model: string, response_time_ms: int, usage: array, correlation_id: string}
     *         The 'critiques' key contains the raw JSON string returned by the LLM for the Arbitrator to parse.
     * @throws \RuntimeException On deadline exceeded
     * @throws LlmException      On LLM API errors
     */
    public function critique(string $question, array $peerAnswers, string $critiquePrompt, ?float $deadline = null): array
    {
        // Layer 4 deadline check before LLM call (same pattern as research())
        if ($deadline !== null && microtime(true) + 5 > $deadline) {
            throw new \RuntimeException('ResearchAgent: deadline reached before critique LLM call');
        }

        if ($this->logger) {
            $this->logger->info('Agent critique started', [
                'peer_count' => count($peerAnswers),
                'model'      => $this->config['model'],
            ]);
        }

        // Build system content from SOUL.md + the critique prompt (which already contains
        // anonymized peer answer blocks and the critique template instructions)
        $systemContent = $this->soul . "\n\n" . $critiquePrompt;

        $messages = [
            ['role' => 'system', 'content' => $systemContent],
            // Do NOT pass the original answer to the critique prompt -- the agent
            // should not see its own answer or be able to self-identify.
            // The critiquePrompt already contains anonymized peer answers.
            ['role' => 'user', 'content' => 'Please critique the above peer answers according to the template.'],
        ];

        // Layer 4 deadline check: before LLM call (D-16 pattern)
        if ($deadline !== null && microtime(true) + 5 > $deadline) {
            throw new \RuntimeException('ResearchAgent: deadline reached before critique LLM call');
        }

        try {
            $startTime = (int) (microtime(true) * 1000);
            $answer = $this->llm->chat($messages);
            $endTime = (int) (microtime(true) * 1000);
            $responseTimeMs = $endTime - $startTime;

            // Validate that the response is parsable JSON with expected structure
            // (early detection of malformed critiques, per T-04-02)
            $critiquesRaw = $answer;
            $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $critiquesRaw);
            $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
            $parsedTest = json_decode(trim($cleaned), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($parsedTest) || empty($parsedTest)) {
                throw new \RuntimeException('Critique LLM returned non-JSON or empty response');
            }
            // Validate each critique entry has required fields
            foreach ($parsedTest as $key => $entry) {
                if (!is_array($entry)) {
                    throw new \RuntimeException("Critique entry '{$key}' is not an object");
                }
                if (!isset($entry['score'])) {
                    throw new \RuntimeException("Critique entry '{$key}' missing 'score' field");
                }
                $score = $entry['score'];
                if (!is_int($score) && !is_float($score)) {
                    throw new \RuntimeException("Critique entry '{$key}' score is not numeric");
                }
                if ($score < 0 || $score > 10) {
                    throw new \RuntimeException("Critique entry '{$key}' score out of range (0-10): {$score}");
                }
            }

            $responseInfo = $this->llm->getLastResponseInfo();

            if ($this->logger) {
                $this->logger->info('Agent critique completed', [
                    'model'             => $responseInfo['model'],
                    'response_time_ms'  => $responseTimeMs,
                    'prompt_tokens'     => $responseInfo['usage']['prompt_tokens'],
                    'completion_tokens' => $responseInfo['usage']['completion_tokens'],
                ]);
            }

            return [
                'critiques'        => $answer,     // Raw JSON string -- Arbitrator parses and validates
                'model'            => $responseInfo['model'],
                'response_time_ms' => $responseTimeMs,
                'usage'            => $responseInfo['usage'],
                'correlation_id'   => $this->correlationId,
            ];
        } catch (LlmException $e) {
            if ($this->logger) {
                $this->logger->error('Agent critique failed', [
                    'error' => $e->getMessage(),
                    'model' => $this->config['model'],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Emit a progress event if a ProgressLogger is configured (Phase 5, D-11).
     */
    private function emitProgress(string $event, array $data = []): void
    {
        if ($this->progressLogger !== null) {
            $this->progressLogger->logEvent($event, $this->agentName, $data);
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
