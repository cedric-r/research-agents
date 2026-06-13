<?php

declare(strict_types=1);

namespace App\Arbitrator;

use App\Agent\AgentManager;
use App\Agent\ResearchAgent;
use App\Config\Loader;
use App\Http\HttpHelper;
use App\Log\Logger;

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
 * @package App\Arbitrator
 */
class Arbitrator
{
    private AgentManager $agentManager;
    private Loader $configLoader;
    private ?Logger $logger;
    private array $config;
    private string $correlationId;

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

        return $config;
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
        $results = [];

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
                $this->runChildProcess($question, $agentName, $agentInfo, $sanitizedName);
                exit(0);
            }

            // ---- PARENT ----
            $children[$pid] = [
                'name'           => $agentName,
                'sanitized_name' => $sanitizedName,
            ];

            if ($this->logger) {
                $this->logger->info('Arbitrator: forking agent ' . $agentName . ' (pid ' . $pid . ')');
            }
        }

        // Wait loop: poll for children to complete
        $running = $children;
        while (!empty($running)) {
            foreach ($running as $pid => $info) {
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
                }
            }

            if (!empty($running)) {
                usleep(100000); // 100ms poll interval
            }
        }

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
     */
    private function runChildProcess(string $question, string $agentName, array $agentInfo, string $sanitizedName): void
    {
        // Reset inherited signal handlers (RESEARCH.md Pitfall 3)
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(0);

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

            $result = $agent->research($question);

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
            // Write error result to temp file
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
}
