<?php

declare(strict_types=1);

namespace App\Arbitrator;

use App\Agent\AgentManager;
use App\Agent\ResearchAgent;
use App\Config\Loader;
use App\Http\HttpHelper;
use App\Log\Logger;
use App\Arbitrator\DiversityAnalyzer;
use App\LlmClient\LlmClient;

/**
 * Multi-agent orchestrator with process-level parallelism.
 *
 * Discovers agents via AgentManager::getAgentConfigs(), distributes a
 * research question across all agents using pcntl_fork for process
 * isolation, collects results via temp file IPC, and returns structured
 * results keyed by agent name.
 *
 * Each agent runs in its own forked child process -- one agent crash
 * does not block others. Temp files in sys_get_temp_dir() provide
 * inter-process result transfer that survives child SIGKILL.
 *
 * # 4-Layer Timeout Architecture (ORCH-10)
 *
 * - **Layer 1 (PHP max_execution_time):** Inactive in CLI mode
 *   (default 0/unlimited). No code enforcement (D-13).
 * - **Layer 2 (HTTP socket timeout):** HttpHelper CURLOPT_TIMEOUT=60s,
 *   CURLOPT_CONNECTTIMEOUT=10s. Active from Phase 2 (D-14).
 * - **Layer 3 (stream-idle watchdog):** Deferred to v2 (no streaming
 *   LLM responses yet) (D-15).
 * - **Layer 4 (cooperative agent-step deadline):**
 *   ResearchAgent::research($deadline) checks before each major step
 *   (tool context building, LLM call) (D-16).
 * - **Batch alarm:** pcntl_alarm($batchTimeout) + SIGALRM handler as
 *   safety net (D-12).
 *
 * @package App\Arbitrator
 */
class Arbitrator
{
    private AgentManager $agentManager;
    private Loader $configLoader;
    private ?Logger $logger;
    private array $config;
    private string $correlationId;
    private ?LlmClient $scoringLlm = null;
    private ?LlmClient $judgeLlm = null;
    private ?array $debateResult = null;
    private string $projectRoot;

    /**
     * @param AgentManager $agentManager Agent discovery service
     * @param Loader       $configLoader Config file loader
     * @param Logger|null  $logger       Optional logger for SYSTEM channel messages
     */
    public function __construct(
        AgentManager $agentManager,
        Loader $configLoader,
        ?Logger $logger = null
    ) {
        $this->agentManager = $agentManager;
        $this->configLoader = $configLoader;
        $this->logger = $logger;
        $this->config = $this->loadConfig();
        $this->projectRoot = dirname(__DIR__, 2);

        // Initialize LLM client for scoring and judging (Phase 4, D-01, D-04)
        $this->initDebateLlmClients();
    }

    /**
     * Load arbitrator configuration with defaults.
     *
     * Config file is optional -- if missing or invalid, all keys
     * fall back to safe defaults.
     *
     * @return array{max_concurrent_agents: int, agent_timeout: int}
     */
    private function loadConfig(): array
    {
        $defaults = [
            'max_concurrent_agents' => 5,
            'agent_timeout'         => 60,
        ];

        $configPath = __DIR__ . '/../../config/arbitrator/config.json';

        if (!file_exists($configPath)) {
            return $defaults;
        }

        try {
            $config = $this->configLoader->load($configPath, required: [], types: []);
        } catch (\Throwable) {
            return $defaults;
        }

        // Merge with defaults for any missing keys
        $config = array_merge($defaults, $config);

        // Validate and clamp values
        if (!is_int($config['max_concurrent_agents']) || $config['max_concurrent_agents'] < 1) {
            $config['max_concurrent_agents'] = $defaults['max_concurrent_agents'];
        }
        if (!is_int($config['agent_timeout']) || $config['agent_timeout'] < 1) {
            $config['agent_timeout'] = $defaults['agent_timeout'];
        }

        // Validate Phase 4 nested config sections are arrays if present (ORCH-05, ORCH-08)
        foreach (['scoring', 'judge', 'weights', 'diversity', 'critique'] as $section) {
            if (isset($config[$section]) && !is_array($config[$section])) {
                $this->logger->warn("Arbitrator config: '{$section}' must be an object, using defaults");
                $config[$section] = [];
            }
        }

        return $config;
    }

    /**
     * Initialize LLM clients for scoring/judge calls using arbitrator config.
     *
     * The scoring and judge configs may specify a different model than the
     * arbitrator's default. Falls back to the arbitrator's own config if
     * scoring/judge sections have missing fields.
     */
    private function initDebateLlmClients(): void
    {
        $scoringConfig = $this->config['scoring'] ?? [];
        $judgeConfig = $this->config['judge'] ?? [];

        // Scoring LLM: uses arbitrator provider + scoring model (or fallback to arbitrator model)
        $this->scoringLlm = new LlmClient([
            'base_url' => $this->resolveBaseUrl(),
            'api_key'  => $this->config['api_key'],
            'model'    => $scoringConfig['model'] ?? $this->config['model'],
        ]);

        // Judge LLM: same pattern, potentially different model
        $this->judgeLlm = new LlmClient([
            'base_url' => $this->resolveBaseUrl(),
            'api_key'  => $this->config['api_key'],
            'model'    => $judgeConfig['model'] ?? $this->config['model'],
        ]);
    }

    /**
     * Resolve the API base URL from arbitrator config.
     *
     * Duplicates ResearchAgent::resolveBaseUrl() pattern for the arbitrator's
     * provider. Throws on unknown provider without override.
     */
    private function resolveBaseUrl(): string
    {
        if (!empty($this->config['provider_base_url'])) {
            return rtrim($this->config['provider_base_url'], '/');
        }
        return match ($this->config['provider']) {
            'deepseek'  => 'https://api.deepseek.com',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default => throw new \RuntimeException(
                "Unknown provider '{$this->config['provider']}' in arbitrator config."
            ),
        };
    }

    /**
     * Distribute a research question to all configured agents in parallel.
     *
     * Discovers agents via AgentManager::getAgentConfigs(), then spawns
     * each agent as a forked child process. Processes agents in batches
     * of max_concurrent_agents. Collects results from temp files written
     * by each child.
     *
     * @param  string      $question      Research question
     * @param  string|null $correlationId Correlation ID for tracing (auto-generated if null)
     * @return array<string, array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string, error?: string}>
     */
    public function research(string $question, ?string $correlationId = null): array
    {
        $this->correlationId = $correlationId ?? Logger::generateCorrelationId();

        // Discover agents
        $agents = $this->agentManager->getAgentConfigs();

        if ($this->logger) {
            $this->logger->info('Arbitrator: starting research', [
                'correlation_id' => $this->correlationId,
            ]);
        }

        if ($agents === []) {
            if ($this->logger) {
                $this->logger->warn('Arbitrator: no agents discovered');
            }
            return [];
        }

        if ($this->logger) {
            $this->logger->info('Arbitrator: discovered ' . count($agents) . ' agents', [
                'agents' => array_keys($agents),
            ]);
        }

        // Check if pcntl_fork is available
        $canFork = function_exists('pcntl_fork');

        if (!$canFork && $this->logger) {
            $this->logger->warn('Arbitrator: pcntl_fork not available -- falling back to sequential execution');
        }

        $maxConcurrent = $this->config['max_concurrent_agents'];
        $agentNames = array_keys($agents);
        $results = [];

        // Process agents in batches
        foreach (array_chunk($agentNames, $maxConcurrent) as $batchIndex => $batch) {
            if ($this->logger) {
                $this->logger->info('Arbitrator: processing batch ' . ($batchIndex + 1), [
                    'agent_count' => count($batch),
                    'batch'       => $batch,
                ]);
            }

            if ($canFork) {
                $batchResults = $this->executeBatchParallel($question, $agents, $batch);
            } else {
                $batchResults = $this->executeBatchSequential($question, $agents, $batch);
            }

            foreach ($batchResults as $agentName => $result) {
                $results[$agentName] = $result;
            }
        }

        if ($this->logger) {
            $this->logger->info('Arbitrator: all batches complete', [
                'result_count' => count($results),
            ]);
        }

        // === Phase 4: Debate Protocol (ORCH-05 through ORCH-08) ===

        if (count($results) < 2) {
            // Not enough agents for meaningful debate
            $this->logger->info('Arbitrator: skipping debate -- fewer than 2 agents responded');
            $this->debateResult = null;
            return $results;
        }

        // Step 1: Evaluate Round 1 answers (ORCH-05)
        $this->logger->info('Arbitrator: starting Round 1 quality evaluation');
        $qualityScores = $this->evaluateAnswers($question, $results);

        // Step 2: Compute diversity scores (D-03)
        $this->logger->info('Arbitrator: computing diversity scores');
        $diversityData = $this->computeDiversityScores($results);

        // Step 3: Round 2 -- fork agents with critique prompts (ORCH-06, ORCH-07)
        $this->logger->info('Arbitrator: starting Round 2 peer critique');
        $critiqueResults = $this->executeRound2Critique($question, $results, $qualityScores);

        // Step 4: Select best final answer (ORCH-08, D-04)
        $this->logger->info('Arbitrator: selecting final answer');
        $this->selectFinalAnswer($question, $results, $qualityScores, $critiqueResults, $diversityData);

        return $results;
    }

    /**
     * Execute a batch of agents in parallel using pcntl_fork.
     *
     * @param  string   $question Research question
     * @param  array    $agents   All agent configs keyed by name
     * @param  string[] $batch    Agent names in this batch
     * @return array<string, array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string, error?: string}>
     */
    private function executeBatchParallel(string $question, array $agents, array $batch): array
    {
        $children = [];
        $childDeadlines = [];
        $results = [];

        // Per-child deadline tracking (D-09, D-12)
        $batchTimeout = $this->config['agent_timeout'] ?? 60;
        $batchStart = microtime(true);

        // Register SIGALRM handler for batch-level timeout safety net (D-12)
        // Handler sets a boolean flag only -- no I/O, no complex logic (T-03-07)
        $batchTimedOut = false;
        pcntl_signal(SIGALRM, function () use (&$batchTimedOut): void {
            $batchTimedOut = true;
        });

        // Fork children for this batch
        foreach ($batch as $agentName) {
            $agentInfo = $agents[$agentName];
            $sanitizedName = $this->sanitizeAgentName($agentName);

            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed
                if ($this->logger) {
                    $this->logger->error('Arbitrator: fork failed for ' . $agentName);
                }
                $results[$agentName] = $this->makeErrorResult(
                    $agentName,
                    $agentInfo,
                    'Fork failed'
                );
                continue;
            }

            if ($pid === 0) {
                // ---- CHILD PROCESS ----
                $deadline = microtime(true) + $batchTimeout;
                $this->runChildProcess($question, $agentName, $agentInfo, $sanitizedName, $deadline);
                exit(0);
            }

            // ---- PARENT ----
            $deadline = microtime(true) + $batchTimeout;
            $children[$pid] = [
                'name'           => $agentName,
                'sanitized_name' => $sanitizedName,
                'deadline'       => $deadline,
            ];
            $childDeadlines[$pid] = $deadline;

            if ($this->logger) {
                $this->logger->info('Arbitrator: forking agent ' . $agentName . ' (pid ' . $pid . ')');
            }
        }

        // Set batch-level timeout alarm (D-12)
        pcntl_alarm($batchTimeout);

        // Wait loop: poll for children to complete with per-child deadline enforcement
        $running = $children;
        while (!empty($running)) {
            foreach ($running as $pid => $info) {
                // Check existing completion first
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);

                if ($reaped > 0 || $reaped === -1) {
                    // Child exited or error -- collect result from temp file
                    $result = $this->readTempFile($info['sanitized_name'], $pid);
                    $results[$info['name']] = $result ?? $this->makeErrorResult(
                        $info['name'],
                        $agents[$info['name']],
                        'No result file found'
                    );

                    // Clean up temp file
                    $this->cleanTempFile($info['sanitized_name'], $pid);

                    if ($this->logger) {
                        $this->logger->info('Arbitrator: agent ' . $info['name'] . ' completed');
                    }

                    unset($running[$pid]);
                    unset($childDeadlines[$pid]);
                    continue;
                }

                // Per-child deadline enforcement (D-09, D-12)
                if (isset($childDeadlines[$pid]) && microtime(true) > $childDeadlines[$pid]) {
                    posix_kill($pid, SIGTERM);      // Try graceful shutdown
                    usleep(2000000);                  // 2s grace for partial answer write

                    $reaped2 = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($reaped2 === 0) {
                        posix_kill($pid, SIGKILL);    // Force kill
                        pcntl_waitpid($pid, $status); // Reap zombie
                    }

                    // Read result (may be partial if child wrote before kill)
                    $result = $this->readTempFile($info['sanitized_name'], $pid);
                    if ($result === null) {
                        // D-11: No temp file -- child was killed before any write
                        $result = [
                            'answer'           => '[' . $info['name'] . ' timed out -- no partial answer]',
                            'model'            => $agents[$info['name']]['config']['model'] ?? 'unknown',
                            'response_time_ms' => (int) ($batchTimeout * 1000),
                            'usage'            => [
                                'prompt_tokens'     => 0,
                                'completion_tokens' => 0,
                                'total_tokens'      => 0,
                            ],
                            'correlation_id'   => $this->correlationId,
                        ];
                    }
                    $results[$info['name']] = $result;

                    // Clean up temp file if it was written
                    $this->cleanTempFile($info['sanitized_name'], $pid);

                    if ($this->logger) {
                        $this->logger->info('Arbitrator: agent ' . $info['name'] . ' timed out');
                    }

                    unset($running[$pid]);
                    unset($childDeadlines[$pid]);
                }
            }

            // Batch-level timeout check (SIGALRM safety net, D-12)
            if ($batchTimedOut) {
                if ($this->logger) {
                    $this->logger->warn('Arbitrator: batch timed out, killing remaining children');
                }
                foreach ($running as $pid => $info) {
                    posix_kill($pid, SIGTERM);
                }
                usleep(2000000);
                foreach ($running as $pid => $info) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);

                    $result = $this->readTempFile($info['sanitized_name'], $pid);
                    if ($result === null) {
                        $result = [
                            'answer'           => '[' . $info['name'] . ' timed out -- no partial answer]',
                            'model'            => $agents[$info['name']]['config']['model'] ?? 'unknown',
                            'response_time_ms' => (int) ($batchTimeout * 1000),
                            'usage'            => [
                                'prompt_tokens'     => 0,
                                'completion_tokens' => 0,
                                'total_tokens'      => 0,
                            ],
                            'correlation_id'   => $this->correlationId,
                        ];
                    }
                    $results[$info['name']] = $result;
                    $this->cleanTempFile($info['sanitized_name'], $pid);
                }
                $running = [];
                $childDeadlines = [];
                break;
            }

            if (!empty($running)) {
                usleep(100000); // 100ms poll interval
            }
        }

        // Cancel batch alarm -- all children in this batch completed
        pcntl_alarm(0);
        // Reset SIGALRM to default
        pcntl_signal(SIGALRM, SIG_DFL);

        return $results;
    }

    /**
     * Execute a batch of agents sequentially (fallback when pcntl_fork is unavailable).
     *
     * @param  string   $question Research question
     * @param  array    $agents   All agent configs keyed by name
     * @param  string[] $batch    Agent names in this batch
     * @return array<string, array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string, error?: string}>
     */
    private function executeBatchSequential(string $question, array $agents, array $batch): array
    {
        $results = [];

        foreach ($batch as $agentName) {
            $agentInfo = $agents[$agentName];

            try {
                $http = new HttpHelper();
                $agentLogger = new Logger(
                    $this->logFilePath(),
                    $agentName,
                    $this->correlationId
                );

                $agent = new ResearchAgent(
                    $agentInfo['dir'],
                    $this->configLoader,
                    $agentLogger
                );

                $toolRegistry = $this->agentManager->configureTools(
                    $http,
                    $agentInfo['config'],
                    $agentLogger
                );
                $agent->setToolRegistry($toolRegistry);

                $result = $agent->research($question);
                $results[$agentName] = $result;
            } catch (\Throwable $e) {
                $results[$agentName] = $this->makeErrorResult(
                    $agentName,
                    $agentInfo,
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Set up and run a single agent in a forked child process.
     *
     * Resets inherited signal handlers, creates fresh instances,
     * runs research, writes result to temp file, then exits.
     *
     * @param float $deadline Absolute Unix timestamp for Layer 4 cooperative deadline (D-16)
     */
    private function runChildProcess(string $question, string $agentName, array $agentInfo, string $sanitizedName, float $deadline): void
    {
        // Reset inherited signal handlers (RESEARCH.md Pitfall 3)
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(0);

        // Enable async signal dispatch (zero-overhead, PHP 7.1+)
        pcntl_async_signals(true);

        // Flag-based SIGTERM handler (D-10, per RESEARCH.md -- NO file I/O in handler)
        // T-03-08, T-03-13: handler sets flag only; deferred write in main context
        $timedOut = false;
        pcntl_signal(SIGTERM, function (int $signo) use (&$timedOut): void {
            $timedOut = true;  // ONLY set flag -- deferred I/O in main context
        });

        try {
            $http = new HttpHelper();
            $agentLogger = new Logger(
                $this->logFilePath(),
                $agentName,
                $this->correlationId
            );

            $agent = new ResearchAgent(
                $agentInfo['dir'],
                $this->configLoader,
                $agentLogger
            );

            // Configure tools via AgentManager (now public)
            $toolRegistry = $this->agentManager->configureTools(
                $http,
                $agentInfo['config'],
                $agentLogger
            );
            $agent->setToolRegistry($toolRegistry);

            // Check if SIGTERM was received before research started
            if ($timedOut) {
                throw new \RuntimeException('Timed out before research started');
            }

            // Pass deadline for Layer 4 cooperative check (D-16)
            $result = $agent->research($question, $deadline);

            // Write success result to temp file
            $this->writeTempFile($sanitizedName, [
                'status'           => 'completed',
                'answer'           => $result['answer'],
                'model'            => $result['model'],
                'response_time_ms' => $result['response_time_ms'],
                'usage'            => $result['usage'],
                'correlation_id'   => $result['correlation_id'],
            ]);
        } catch (\Throwable $e) {
            if ($timedOut) {
                // SIGTERM was received -- write partial answer from main context (safe)
                // D-10: file_put_contents is NOT called in the signal handler.
                // The handler only set a boolean flag; actual I/O happens here.
                $partialResult = [
                    'status'           => 'partial',
                    'answer'           => '[Research interrupted by timeout]',
                    'model'            => $agentInfo['config']['model'] ?? 'unknown',
                    'response_time_ms' => 0,
                    'usage'            => [
                        'prompt_tokens'     => 0,
                        'completion_tokens' => 0,
                        'total_tokens'      => 0,
                    ],
                    'correlation_id'   => $this->correlationId,
                ];
                $this->writeTempFile($sanitizedName, $partialResult);
            } else {
                // Normal exception -- write error result
                $this->writeTempFile($sanitizedName, [
                    'status'           => 'killed',
                    'answer'           => 'Agent ' . $agentName . ' failed: ' . $e->getMessage(),
                    'model'            => $agentInfo['config']['model'] ?? 'unknown',
                    'response_time_ms' => 0,
                    'usage'            => [
                        'prompt_tokens'     => 0,
                        'completion_tokens' => 0,
                        'total_tokens'      => 0,
                    ],
                    'correlation_id'   => $this->correlationId,
                    'error'            => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Write result data to a temp file so the parent process can read it.
     *
     * Called from child process context -- uses getmypid() for collision-avoidant naming.
     *
     * @param string $sanitizedName Sanitized agent name
     * @param array  $data          Result data to encode as JSON
     */
    private function writeTempFile(string $sanitizedName, array $data): void
    {
        $pid = getmypid();
        $path = $this->getTempFilePath($sanitizedName, $pid !== false ? $pid : 0);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($path, $json, LOCK_EX);
        }
    }

    /**
     * Read result data from a child's temp file.
     *
     * @param  string $sanitizedName Sanitized agent name
     * @param  int    $pid           Child process ID (known from parent's tracking)
     * @return array|null Decoded result array, or null if file missing/invalid
     */
    private function readTempFile(string $sanitizedName, int $pid): ?array
    {
        $path = $this->getTempFilePath($sanitizedName, $pid);

        if (!file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        // Remove status field before returning -- it's IPC metadata, not part of public result shape
        unset($data['status']);

        return $data;
    }

    /**
     * Remove a temp file after reading.
     *
     * @param string $sanitizedName Sanitized agent name
     * @param int    $pid           Child process ID
     */
    private function cleanTempFile(string $sanitizedName, int $pid): void
    {
        $path = $this->getTempFilePath($sanitizedName, $pid);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Get the temp file path for an agent's result.
     *
     * Pattern: sys_get_temp_dir()/agent_{sanitizedName}_{correlationId}_{pid}.json
     * PID is included for collision avoidance (RESEARCH.md Pitfall 4).
     *
     * @param  string $sanitizedName Sanitized agent name
     * @param  int    $pid           Process ID (child PID in parent context, getmypid() in child)
     * @return string                Full path to the temp file
     */
    private function getTempFilePath(string $sanitizedName, int $pid): string
    {
        return sys_get_temp_dir() . '/agent_' . $sanitizedName . '_' . $this->correlationId . '_' . $pid . '.json';
    }

    /**
     * Sanitize an agent name for safe use in file paths.
     *
     * Replaces any non-alphanumeric, non-underscore, non-hyphen character
     * with an underscore (defense-in-depth per T-03-01).
     *
     * @param  string $name Raw agent name (directory name)
     * @return string       Sanitized name safe for file paths
     */
    private function sanitizeAgentName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Create a structured error result entry.
     *
     * @param  string $agentName Agent name
     * @param  array  $agentInfo Agent config info
     * @param  string $errorMsg  Error description
     * @return array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string, error: string}
     */
    private function makeErrorResult(string $agentName, array $agentInfo, string $errorMsg): array
    {
        return [
            'answer'           => 'Agent ' . $agentName . ': ' . $errorMsg,
            'model'            => $agentInfo['config']['model'] ?? 'unknown',
            'response_time_ms' => 0,
            'usage'            => [
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
            ],
            'correlation_id'   => $this->correlationId,
            'error'            => $errorMsg,
        ];
    }

    /**
     * Get the path to the shared log file.
     *
     * Derived relative to the project root (two levels up from src/Arbitrator/).
     *
     * @return string Log file path
     */
    private function logFilePath(): string
    {
        return dirname(__DIR__, 2) . '/logs/research.log';
    }

    // ---------------------------------------------------------------
    // Phase 4: Debate Protocol Methods
    // ---------------------------------------------------------------

    /**
     * Evaluate Round 1 answers using LLM-based scoring rubric (ORCH-05, D-01).
     *
     * Calls the scoring LLM once per answer with the rubric prompt from config.
     * Parses JSON output containing per-dimension scores and composite.
     *
     * @param  string $question   Original research question
     * @param  array  $r1Results  Round 1 results: {agent_name => {answer, ...}}
     * @return array              {agent_name => {relevance, completeness, citation_quality, clarity, confidence, composite, reasoning}}
     */
    public function evaluateAnswers(string $question, array $r1Results): array
    {
        // Load scoring prompt template
        $promptPath = $this->config['scoring']['rubric_prompt'] ?? '';
        if ($promptPath === '' || !file_exists($promptPath)) {
            throw new \RuntimeException('Scoring rubric prompt not found: ' . $promptPath);
        }
        $promptTemplate = file_get_contents($promptPath);
        if ($promptTemplate === false) {
            throw new \RuntimeException('Cannot read scoring rubric prompt: ' . $promptPath);
        }

        $scores = [];
        $temperature = (float) ($this->config['scoring']['temperature'] ?? 0.0);

        foreach ($r1Results as $agentName => $result) {
            $answerText = $result['answer'] ?? '';
            if ($answerText === '') {
                // Empty answer -- score as 0 across all dimensions
                $scores[$agentName] = [
                    'relevance' => 0, 'completeness' => 0, 'citation_quality' => 0,
                    'clarity' => 0, 'confidence' => 0, 'composite' => 0,
                    'reasoning' => 'Empty answer -- no content to evaluate',
                ];
                continue;
            }

            // Build scoring prompt: replace {question} and {answer} placeholders
            $prompt = str_replace(
                ['{question}', '{answer}'],
                [$question, $answerText],
                $promptTemplate
            );

            $messages = [
                ['role' => 'user', 'content' => $prompt],
            ];

            try {
                $response = $this->scoringLlm->chat($messages, [
                    'temperature' => $temperature,
                ]);

                // Parse JSON response
                $parsed = $this->parseScoringJson($response);
                $scores[$agentName] = $parsed;
            } catch (\Throwable $e) {
                $this->logger->warn('Scoring failed for agent', [
                    'agent' => $agentName,
                    'error' => $e->getMessage(),
                ]);
                // Fallback: default score
                $scores[$agentName] = [
                    'relevance' => 0, 'completeness' => 0, 'citation_quality' => 0,
                    'clarity' => 0, 'confidence' => 0, 'composite' => 0,
                    'reasoning' => 'Scoring LLM call failed: ' . $e->getMessage(),
                ];
            }
        }

        return $scores;
    }

    /**
     * Parse and validate the JSON response from the scoring LLM.
     *
     * @param  string $response Raw JSON string from LLM
     * @return array            Validated array with dimension scores and composite
     */
    private function parseScoringJson(string $response): array
    {
        // Strip any markdown code fence if LLM wraps JSON in ```json ... ```
        $response = preg_replace('/^```(?:json)?\s*\n?/i', '', $response);
        $response = preg_replace('/\n?```\s*$/', '', $response);

        try {
            $data = json_decode(trim($response), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to parse scoring LLM JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Scoring LLM returned non-object JSON');
        }

        $dimensions = ['relevance', 'completeness', 'citation_quality', 'clarity', 'confidence'];
        $validated = [];

        foreach ($dimensions as $dim) {
            $value = $data[$dim] ?? 0;
            if (!is_int($value) && !is_float($value)) {
                $value = 0;
            }
            $validated[$dim] = max(0, min(10, (int) round($value)));
        }

        // Composite: validate or compute from dimensions
        $composite = $data['composite'] ?? 0;
        if (!is_int($composite) && !is_float($composite)) {
            $composite = array_sum($validated) / count($dimensions);
        }
        $validated['composite'] = max(0, min(10, (float) $composite));

        $validated['reasoning'] = (string) ($data['reasoning'] ?? '');

        return $validated;
    }

    /**
     * Compute diversity scores for all Round 1 answers (ORCH-05, D-03).
     *
     * Uses DiversityAnalyzer::computeSimilarityScores() for pairwise n-gram overlap,
     * then converts to diversity bonuses using configurable factor.
     *
     * @param  array $r1Results Round 1 results: {agent_name => {answer, ...}}
     * @return array            {agent_name => {avg_similarity, diversity_bonus}}
     */
    public function computeDiversityScores(array $r1Results): array
    {
        $diversityConfig = $this->config['diversity'] ?? [];
        $enabled = $diversityConfig['enabled'] ?? true;

        if (!$enabled || count($r1Results) < 2) {
            $result = [];
            foreach ($r1Results as $agentName => $_) {
                $result[$agentName] = [
                    'avg_similarity'  => 0.0,
                    'diversity_bonus' => 0.0,
                ];
            }
            return $result;
        }

        $nGramSize = (int) ($diversityConfig['n_gram_size'] ?? 3);
        $factor = (float) ($diversityConfig['factor'] ?? 0.15);

        // Build answer texts indexed by agent name
        $answerTexts = [];
        foreach ($r1Results as $agentName => $result) {
            $text = trim($result['answer'] ?? '');
            if ($text !== '') {
                $answerTexts[$agentName] = $text;
            }
        }

        if (count($answerTexts) < 2) {
            $result = [];
            foreach ($r1Results as $agentName => $_) {
                $result[$agentName] = [
                    'avg_similarity'  => 0.0,
                    'diversity_bonus' => 0.0,
                ];
            }
            return $result;
        }

        $similarityScores = DiversityAnalyzer::computeSimilarityScores($answerTexts, $nGramSize);

        $result = [];
        foreach ($r1Results as $agentName => $_) {
            $avgSimilarity = $similarityScores[$agentName] ?? 0.0;
            $result[$agentName] = [
                'avg_similarity'  => $avgSimilarity,
                'diversity_bonus' => DiversityAnalyzer::diversityBonus($avgSimilarity, $factor),
            ];
        }

        return $result;
    }

    /**
     * Build anonymized peer answer blocks for each agent's critique prompt.
     *
     * For each agent $agentName, builds a set of peer answers that EXCLUDES
     * the agent's own answer and anonymizes the remaining answers as "Peer 1", "Peer 2", etc.
     *
     * @param  string $agentName     The agent receiving the critique prompt
     * @param  array  $allAnswers    All Round 1 results with scores
     * @param  array  $qualityScores Quality scores per agent
     * @return string                {peer_answers} block with anonymized labels
     */
    private function buildAnonymizedPeerBlock(string $agentName, array $allAnswers, array $qualityScores): string
    {
        $peers = [];
        foreach ($allAnswers as $name => $result) {
            if ($name === $agentName) {
                continue; // Exclude own answer (D-02: anti-self-critique bias)
            }
            $peers[] = [
                'answer' => $result['answer'] ?? '',
                'scores' => $qualityScores[$name] ?? [],
            ];
        }

        if (empty($peers)) {
            return '[No peer answers to critique]';
        }

        $block = '';
        $index = 0;
        foreach ($peers as $peer) {
            $index++;
            $block .= '=== PEER ANSWER ' . $index . ' ===' . PHP_EOL;
            $block .= 'Quality scores: ' . json_encode($peer['scores']) . PHP_EOL;
            $block .= $peer['answer'] . PHP_EOL . PHP_EOL;
        }

        return $block;
    }

    /**
     * Build the complete critique prompt for a specific agent.
     *
     * Loads the critique template from config, replaces {question} and {peer_answers}
     * placeholders. The caller passes anonymized peer answer blocks.
     *
     * @param  string $question         Research question
     * @param  string $peerAnswersBlock Pre-built anonymized peer answers
     * @return string                   Complete critique prompt
     */
    private function buildCritiquePrompt(string $question, string $peerAnswersBlock): string
    {
        $templatePath = $this->config['critique']['template_path'] ?? '';
        if ($templatePath === '' || !file_exists($templatePath)) {
            throw new \RuntimeException('Critique template not found: ' . $templatePath);
        }
        $template = file_get_contents($templatePath);
        if ($template === false) {
            throw new \RuntimeException('Cannot read critique template: ' . $templatePath);
        }

        return str_replace(
            ['{question}', '{peer_answers}'],
            [$question, $peerAnswersBlock],
            $template
        );
    }

    /**
     * Select the best final answer using two-stage process (ORCH-08, D-04).
     *
     * Stage 1: Weighted formula narrows to top 2-3 candidates.
     * Stage 2: LLM judge picks the winner with narrative reasoning.
     *
     * @param  string $question         Research question
     * @param  array  $r1Results        Round 1 results
     * @param  array  $qualityScores    Quality evaluation scores
     * @param  array  $critiqueResults  Round 2 critique results (raw JSON from agents)
     * @param  array  $diversityData    Diversity scores and bonuses
     * @return array{winner: string, score_table: array, narrative: string}
     */
    public function selectFinalAnswer(
        string $question,
        array $r1Results,
        array $qualityScores,
        array $critiqueResults,
        array $diversityData
    ): array {
        $weights = $this->config['weights'] ?? [];
        $qualityWeight = (float) ($weights['quality'] ?? 0.50);
        $critiqueWeight = (float) ($weights['critique'] ?? 0.30);
        $diversityWeight = (float) ($weights['diversity'] ?? 0.20);

        // Extract average critique scores from raw critique results
        $avgCritiqueScores = $this->computeAverageCritiqueScores($critiqueResults);

        // Stage 1: Compute weighted scores for all agents
        $weightedScores = [];
        foreach ($r1Results as $agentName => $_) {
            $quality = $qualityScores[$agentName]['composite'] ?? 0;
            $qualityNormalized = $quality / 10.0; // Normalize 0-10 to 0-1

            $critiqueAvg = $avgCritiqueScores[$agentName] ?? 0.0;

            $diversityBonus = $diversityData[$agentName]['diversity_bonus'] ?? 0.0;

            $weightedScores[$agentName] = ($qualityWeight * $qualityNormalized)
                                        + ($critiqueWeight * $critiqueAvg)
                                        + ($diversityWeight * $diversityBonus);
        }

        // Sort descending
        arsort($weightedScores);

        // Keep top N candidates (minimum 2, top 50% if that's more)
        $total = count($weightedScores);
        $keepCount = max(2, min(
            (int) ($this->config['judge']['max_candidates'] ?? 3),
            (int) ceil($total * 0.5)
        ));
        $candidates = array_slice($weightedScores, 0, $keepCount, true);

        // Build score table (all agents, with full breakdown)
        $scoreTable = [];
        foreach ($weightedScores as $agentName => $totalScore) {
            $scoreTable[$agentName] = [
                'quality'           => $qualityScores[$agentName]['composite'] ?? 0,
                'critique_avg'      => $avgCritiqueScores[$agentName] ?? 0.0,
                'diversity_bonus'   => $diversityData[$agentName]['diversity_bonus'] ?? 0.0,
                'weighted_total'    => round($totalScore, 4),
                'is_candidate'      => array_key_exists($agentName, $candidates),
            ];
        }

        // Stage 2: LLM judge picks winner from candidates
        $judgeResult = $this->judgeSelection($question, $r1Results, $qualityScores, $critiqueResults, $candidates);

        // Store debate result internally for retrieval by research.php
        $this->debateResult = [
            'winner'       => $judgeResult['winner'],
            'score_table'  => $scoreTable,
            'narrative'    => $judgeResult['reasoning'],
        ];

        return $this->debateResult;
    }

    /**
     * Compute average critique score per agent from raw Round 2 results.
     *
     * Each agent's critique result contains scores for each peer answer.
     * This method aggregates all scores received by each agent from all peers.
     *
     * @param  array $critiqueResults Round 2 results: {critic_agent_name => {critiques: '{"1": {...}, ...}'}}
     * @return array                  {agent_name => average_score (0.0-1.0)}
     */
    private function computeAverageCritiqueScores(array $critiqueResults): array
    {
        $allScores = [];

        foreach ($critiqueResults as $criticAgent => $critiqueResult) {
            $raw = $critiqueResult['critiques'] ?? '';
            if ($raw === '') {
                continue;
            }

            try {
                // Strip code fences
                $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw);
                $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
                $parsed = json_decode(trim($cleaned), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warn('Failed to parse critique JSON', [
                    'critic' => $criticAgent,
                    'error'  => $e->getMessage(),
                ]);
                continue;
            }

            if (!is_array($parsed)) {
                continue;
            }

            // The parsed JSON has keys "1", "2", ... mapping to peer answers
            foreach ($parsed as $critiqueEntry) {
                if (!is_array($critiqueEntry)) {
                    continue;
                }
                $score = $critiqueEntry['score'] ?? null;
                if ($score === null || (!is_int($score) && !is_float($score))) {
                    continue;
                }
                $normalized = max(0, min(10, (float) $score)) / 10.0; // Normalize to 0-1
                $allScores[] = $normalized;
            }
        }

        // Average all critique scores across all peers.
        // The critique JSON keys are positional (1, 2, 3...) and the critic agent
        // doesn't know agent identities. Compute a global average as a pragmatic
        // proxy until a bijective identity mapping is added.
        $globalAvg = !empty($allScores) ? array_sum($allScores) / count($allScores) : 0.0;

        $result = [];
        foreach ($this->agentManager->getAgentConfigs() as $agentName => $_) {
            $result[$agentName] = $globalAvg;
        }

        return $result;
    }

    /**
     * Stage 2: LLM judge selects the winner from candidates.
     *
     * @param  string $question        Research question
     * @param  array  $r1Results       All Round 1 results
     * @param  array  $qualityScores   Quality scores per agent
     * @param  array  $critiqueResults Round 2 critique results
     * @param  array  $candidates      {agent_name => weighted_score}
     * @return array{winner: string, reasoning: string}
     */
    private function judgeSelection(
        string $question,
        array $r1Results,
        array $qualityScores,
        array $critiqueResults,
        array $candidates
    ): array {
        $judgePromptPath = $this->config['critique']['judge_prompt_path'] ?? '';
        if ($judgePromptPath === '' || !file_exists($judgePromptPath)) {
            throw new \RuntimeException('Judge prompt not found: ' . $judgePromptPath);
        }
        $judgeTemplate = file_get_contents($judgePromptPath);
        if ($judgeTemplate === false) {
            throw new \RuntimeException('Cannot read judge prompt: ' . $judgePromptPath);
        }

        // Build candidate blocks for the judge prompt
        $candidateBlocks = '';
        foreach ($candidates as $agentName => $weightedScore) {
            $result = $r1Results[$agentName] ?? [];
            $scores = $qualityScores[$agentName] ?? [];
            $critiqueText = '';

            // Collect critique text for this agent from Round 2
            foreach ($critiqueResults as $criticAgent => $cr) {
                if (isset($cr['critiques'])) {
                    $critiqueText .= "[From {$criticAgent}]: " . mb_substr($cr['critiques'], 0, 500) . PHP_EOL;
                }
            }

            $candidateBlocks .= '=== ' . strtoupper($agentName) . ' ===' . PHP_EOL;
            $candidateBlocks .= 'Quality scores: ' . json_encode($scores) . PHP_EOL;
            $candidateBlocks .= 'Answer: ' . ($result['answer'] ?? '[No answer]') . PHP_EOL;
            $candidateBlocks .= 'Peer critiques: ' . PHP_EOL . $critiqueText . PHP_EOL;
            $candidateBlocks .= '---' . PHP_EOL;
        }

        $candidateCount = count($candidates);
        $judgePrompt = str_replace(
            ['{candidate_count}', '{question}', '{candidates}'],
            [(string) $candidateCount, $question, $candidateBlocks],
            $judgeTemplate
        );

        $messages = [
            ['role' => 'user', 'content' => $judgePrompt],
        ];

        $temperature = (float) ($this->config['judge']['temperature'] ?? 0.3);

        try {
            $response = $this->judgeLlm->chat($messages, [
                'temperature' => $temperature,
            ]);

            // Parse JSON response
            $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $response);
            $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
            $data = json_decode(trim($cleaned), true, 16, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !isset($data['winner'])) {
                throw new \RuntimeException('Judge LLM returned invalid JSON structure');
            }

            $winner = (string) $data['winner'];
            $reasoning = (string) ($data['reasoning'] ?? '');

            // Validate winner is a real candidate
            if (!array_key_exists($winner, $candidates)) {
                $this->logger->warn('Judge selected non-candidate winner, falling back', [
                    'winner' => $winner,
                ]);
                // Fallback: pick first candidate
                $winner = array_key_first($candidates);
                $reasoning = '[Fallback: LLM judge selected non-candidate. Winner chosen by score. Original reasoning: ' . $reasoning . ']';
            }

            return [
                'winner'    => $winner,
                'reasoning' => $reasoning,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Judge LLM selection failed', [
                'error' => $e->getMessage(),
            ]);
            // Fallback: pick top candidate by weighted score
            $winner = array_key_first($candidates);
            return [
                'winner'    => $winner,
                'reasoning' => '[Automatic fallback] LLM judge selection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Execute Round 2 peer critique for all agents via parallel fork (ORCH-06, ORCH-07).
     *
     * For each agent, builds an anonymized critique prompt that excludes the
     * agent's own answer, then forks a child process to call ResearchAgent::critique().
     * Collects results via temp file IPC (same pattern as Round 1).
     *
     * @param  string $question       Original research question
     * @param  array  $r1Results      Round 1 results: {agent_name => {answer, ...}}
     * @param  array  $qualityScores  Quality scores per agent from evaluateAnswers()
     * @return array                  {agent_name => {critiques, model, usage, ...}}
     */
    private function executeRound2Critique(string $question, array $r1Results, array $qualityScores): array
    {
        $agentConfigs = $this->agentManager->getAgentConfigs();
        $maxConcurrent = (int) ($this->config['max_concurrent_agents'] ?? 5);
        $critiqueTimeout = (int) ($this->config['critique']['timeout_seconds'] ?? 120);

        $critiqueResults = [];

        // Build critique prompt for each agent
        $critiquePrompts = [];
        foreach ($r1Results as $agentName => $_) {
            if (!isset($agentConfigs[$agentName])) {
                continue;
            }
            $peerBlock = $this->buildAnonymizedPeerBlock($agentName, $r1Results, $qualityScores);
            $critiquePrompts[$agentName] = $this->buildCritiquePrompt($question, $peerBlock);
        }

        if (empty($critiquePrompts)) {
            return [];
        }

        $agentNames = array_keys($critiquePrompts);

        // Process agents in batches (same pattern as executeBatchParallel in Phase 3)
        foreach (array_chunk($agentNames, $maxConcurrent) as $batchIndex => $batch) {
            $this->logger->info('Arbitrator: Round 2 batch ' . ($batchIndex + 1), [
                'agent_count' => count($batch),
            ]);

            if (!function_exists('pcntl_fork')) {
                $batchResults = $this->executeCritiqueSequential($question, $batch, $agentConfigs, $critiquePrompts, $critiqueTimeout);
            } else {
                $batchResults = $this->executeCritiqueParallel($question, $batch, $agentConfigs, $critiquePrompts, $critiqueTimeout);
            }

            foreach ($batchResults as $agentName => $result) {
                $critiqueResults[$agentName] = $result;
            }
        }

        $this->logger->info('Arbitrator: Round 2 complete', [
            'result_count' => count($critiqueResults),
        ]);

        return $critiqueResults;
    }

    /**
     * Execute Round 2 critique in parallel forked children.
     *
     * Reuses the same temp-file IPC pattern as Round 1 (executeBatchParallel).
     * Each child creates a ResearchAgent, builds the critique prompt, and
     * calls critique().
     *
     * @return array {agent_name => {critiques, model, ...}}
     */
    private function executeCritiqueParallel(
        string $question,
        array $batch,
        array $agentConfigs,
        array $critiquePrompts,
        int $critiqueTimeout
    ): array {
        $results = [];
        $children = [];
        $childDeadlines = [];
        $running = [];

        foreach ($batch as $agentName) {
            $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentName);
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->logger->error('Arbitrator: fork failed for Round 2', ['agent' => $agentName]);
                continue;
            }

            if ($pid === 0) {
                // CHILD process
                $this->runChildCritique($question, $agentName, $agentConfigs[$agentName], $critiquePrompts[$agentName], $sanitizedName);
                exit(0);
            }

            // PARENT
            $deadline = microtime(true) + $critiqueTimeout;
            $children[$pid] = [
                'name'           => $agentName,
                'sanitized_name' => $sanitizedName,
                'deadline'       => $deadline,
            ];
            $childDeadlines[$pid] = $deadline;
        }

        $running = $children;
        while (!empty($running)) {
            foreach ($running as $pid => $info) {
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);

                if ($reaped > 0 || $reaped === -1) {
                    $result = $this->readTempFile($info['sanitized_name'], $pid);
                    if ($result !== null) {
                        $results[$info['name']] = $result;
                    } else {
                        $results[$info['name']] = [
                            'critiques' => '',
                            'model'     => $agentConfigs[$info['name']]['config']['model'] ?? 'unknown',
                            'response_time_ms' => $critiqueTimeout * 1000,
                            'usage'     => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                            'correlation_id' => $this->correlationId,
                            'error'     => 'No critique result -- child may have crashed',
                        ];
                    }
                    $this->cleanTempFile($info['sanitized_name'], $pid);
                    unset($running[$pid]);
                    unset($childDeadlines[$pid]);
                }

                // Per-child deadline check
                if (isset($childDeadlines[$pid]) && microtime(true) > $childDeadlines[$pid]) {
                    posix_kill($pid, SIGTERM);
                    usleep(2000000);
                    $reaped2 = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($reaped2 === 0) {
                        posix_kill($pid, SIGKILL);
                        pcntl_waitpid($pid, $status);
                    }
                    $result = $this->readTempFile($info['sanitized_name'], $pid);
                    if ($result !== null) {
                        $results[$info['name']] = $result;
                    } else {
                        $results[$info['name']] = $this->makeCritiqueErrorResult($info['name'], $agentConfigs[$info['name']], 'Critique timed out');
                    }
                    $this->cleanTempFile($info['sanitized_name'], $pid);
                    unset($running[$pid]);
                    unset($childDeadlines[$pid]);
                }
            }

            if (!empty($running)) {
                usleep(100000); // 100ms polling interval
            }
        }

        return $results;
    }

    /**
     * Run a single critique in a forked child process.
     *
     * Creates ResearchAgent, configures tools, calls critique(), writes result to temp file.
     */
    private function runChildCritique(
        string $question,
        string $agentName,
        array $agentInfo,
        string $critiquePrompt,
        string $sanitizedName
    ): void {
        // Reset signals for child (same as runChildProcess pattern)
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(0);

        $timedOut = false;
        pcntl_signal(SIGTERM, function (int $signo) use (&$timedOut): void {
            $timedOut = true; // Flag only -- no I/O in handler
        });

        try {
            $agentLogger = new Logger(
                $this->logFilePath(),
                $agentName,
                $this->correlationId
            );

            $agent = new ResearchAgent(
                $agentInfo['dir'],
                new Loader(),
                $agentLogger
            );

            $http = new HttpHelper();
            $toolRegistry = $this->agentManager->configureTools(
                $http,
                $agentInfo['config'],
                $agentLogger
            );
            $agent->setToolRegistry($toolRegistry);

            // Deadline: same as parent's per-child deadline
            $critiqueTimeout = (int) ($this->config['critique']['timeout_seconds'] ?? 120);
            $deadline = microtime(true) + $critiqueTimeout;

            $result = $agent->critique($question, [], $critiquePrompt, $deadline);

            $this->writeTempFile($sanitizedName, [
                'critiques'        => $result['critiques'],
                'model'            => $result['model'],
                'response_time_ms' => $result['response_time_ms'],
                'usage'            => $result['usage'],
                'correlation_id'   => $result['correlation_id'],
            ]);
        } catch (\Throwable $e) {
            if ($timedOut) {
                $partialResult = [
                    'critiques'        => '[Critique interrupted by timeout]',
                    'model'            => $agentInfo['config']['model'] ?? 'unknown',
                    'response_time_ms' => (int) ($critiqueTimeout ?? 120) * 1000,
                    'usage'            => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                    'correlation_id'  => $this->correlationId,
                ];
                $this->writeTempFile($sanitizedName, $partialResult);
            } else {
                $this->writeTempFile($sanitizedName, [
                    'critiques'        => '',
                    'model'            => $agentInfo['config']['model'] ?? 'unknown',
                    'response_time_ms' => 0,
                    'usage'            => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                    'correlation_id'  => $this->correlationId,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute Round 2 critique sequentially (fallback when pcntl_fork unavailable).
     */
    private function executeCritiqueSequential(
        string $question,
        array $batch,
        array $agentConfigs,
        array $critiquePrompts,
        int $critiqueTimeout
    ): array {
        $results = [];

        foreach ($batch as $agentName) {
            $deadline = microtime(true) + $critiqueTimeout;

            try {
                $agentLogger = new Logger(
                    $this->logFilePath(),
                    $agentName,
                    $this->correlationId
                );

                $agent = new ResearchAgent(
                    $agentConfigs[$agentName]['dir'],
                    new Loader(),
                    $agentLogger
                );

                $http = new HttpHelper();
                $toolRegistry = $this->agentManager->configureTools(
                    $http,
                    $agentConfigs[$agentName]['config'],
                    $agentLogger
                );
                $agent->setToolRegistry($toolRegistry);

                $result = $agent->critique($question, [], $critiquePrompts[$agentName], $deadline);
                $results[$agentName] = $result;
            } catch (\Throwable $e) {
                $results[$agentName] = [
                    'critiques'        => '',
                    'model'            => $agentConfigs[$agentName]['config']['model'] ?? 'unknown',
                    'response_time_ms' => 0,
                    'usage'            => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
                    'correlation_id'  => $this->correlationId,
                    'error'           => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Create an error result for a failed critique.
     */
    private function makeCritiqueErrorResult(string $agentName, array $agentInfo, string $errorMsg): array
    {
        return [
            'critiques'        => '',
            'model'            => $agentInfo['config']['model'] ?? 'unknown',
            'response_time_ms' => 0,
            'usage'            => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'correlation_id'  => $this->correlationId,
            'error'           => $errorMsg,
        ];
    }

    /**
     * Get the debate result after research() completes.
     *
     * Returns null if debate was skipped (fewer than 2 agents) or not yet run.
     *
     * @return array|null {winner: string, score_table: array, narrative: string} or null
     */
    public function getDebateResult(): ?array
    {
        return $this->debateResult;
    }
}
