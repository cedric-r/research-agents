<?php

declare(strict_types=1);

namespace App\Agent;

use App\Config\Loader;
use App\Http\HttpHelper;
use App\Log\Logger;
use App\Tool\AcademicSearch;
use App\Tool\ToolRegistry;
use App\Tool\WebSearch;

/**
 * Agent discovery and lifecycle management.
 *
 * Scans agent directories under the configured base path for config.json
 * files to discover configured agents, creates fresh ResearchAgent instances
 * per research() call (D-02), and returns structured results from every agent.
 *
 * @package App\Agent
 */
class AgentManager
{
    private string $agentsBaseDir;
    private Loader $configLoader;
    private string $logFile;
    private ?Logger $logger;

    /** @var array<string, array{dir: string, config: array}> */
    private array $discoveredAgents = [];

    /**
     * @param string      $agentsBaseDir Base path containing agent directories (e.g., config/agents)
     * @param Loader      $configLoader  Config file loader instance
     * @param string      $logFile       Path to the shared log file for agent loggers
     * @param Logger|null $logger        Optional logger for AgentManager's own SYSTEM messages
     */
    public function __construct(
        string $agentsBaseDir,
        Loader $configLoader,
        string $logFile,
        ?Logger $logger = null
    ) {
        $this->agentsBaseDir = rtrim($agentsBaseDir, '/');
        $this->configLoader = $configLoader;
        $this->logFile = $logFile;
        $this->logger = $logger;
    }

    /**
     * Discover all configured agents by scanning agent directories for config.json.
     *
     * Each agent directory must contain:
     * - config.json with provider, model, api_key
     * - preferences.json (optional, loaded by ResearchAgent)
     * - SOUL.md (required by ResearchAgent)
     *
     * Agents with invalid configs are skipped with a warning.
     *
     * @return array<string, array{dir: string, config: array}> Agent name (directory) => agent info
     */
    public function discoverAgents(): array
    {
        $pattern = $this->agentsBaseDir . '/*/config.json';
        $configFiles = glob($pattern);

        $agents = [];

        if ($configFiles === false || $configFiles === []) {
            if ($this->logger) {
                $this->logger->info('AgentManager: no agent configs found', [
                    'pattern' => $pattern,
                ]);
            }
            $this->discoveredAgents = $agents;
            return $agents;
        }

        foreach ($configFiles as $configFile) {
            $agentDir = dirname($configFile);
            $agentName = basename($agentDir);

            try {
                $config = $this->configLoader->load(
                    $configFile,
                    required: ['provider', 'model', 'api_key'],
                    types: ['provider' => 'string', 'model' => 'string', 'api_key' => 'string']
                );

                $agents[$agentName] = [
                    'dir'    => $agentDir,
                    'config' => $config,
                ];

                if ($this->logger) {
                    $this->logger->info('AgentManager: discovered agent', [
                        'agent' => $agentName,
                        'model' => $config['model'] ?? 'unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warn('AgentManager: skipped invalid agent config', [
                        'agent' => $agentName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->discoveredAgents = $agents;
        return $agents;
    }

    /**
     * Get discovered agent configs.
     *
     * Lazily discovers agents on first call if discoverAgents() hasn't been called yet.
     *
     * @return array<string, array{dir: string, config: array}>
     */
    public function getAgentConfigs(): array
    {
        if ($this->discoveredAgents === []) {
            $this->discoverAgents();
        }
        return $this->discoveredAgents;
    }

    /**
     * Run research through all configured agents.
     *
     * Discovers agents if not already cached, creates a fresh ResearchAgent
     * per agent (D-02), configures tools based on each agent's config, and
     * collects structured results keyed by agent name.
     *
     * @param  string $question      Research question (capped at 2000 chars per ResearchAgent)
     * @param  string $correlationId Correlation ID tying all agent activity to a session
     * @return array<string, array{answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string}>
     * @throws \RuntimeException If no agents are configured
     */
    public function research(string $question, string $correlationId): array
    {
        $agents = $this->getAgentConfigs();

        if ($agents === []) {
            throw new \RuntimeException(
                'No agents configured for research. Create agent configs under: ' . $this->agentsBaseDir
            );
        }

        if ($this->logger) {
            $this->logger->info('AgentManager: starting multi-agent research', [
                'agent_count' => count($agents),
                'agents'      => array_keys($agents),
            ]);
        }

        // HttpHelper is stateless — shared across all agents
        $http = new HttpHelper();
        $results = [];

        foreach ($agents as $agentName => $agentInfo) {
            if ($this->logger) {
                $this->logger->info('AgentManager: running agent', [
                    'agent' => $agentName,
                ]);
            }

            // D-02: fresh ResearchAgent instance per research() call
            $agentLogger = new Logger($this->logFile, $agentName, $correlationId);

            $agent = new ResearchAgent(
                $agentInfo['dir'],
                $this->configLoader,
                $agentLogger
            );

            // Configure tools based on agent config
            $toolRegistry = $this->configureTools($http, $agentInfo['config'], $agentLogger);
            $agent->setToolRegistry($toolRegistry);

            $result = $agent->research($question);
            $results[$agentName] = $result;

            if ($this->logger) {
                $this->logger->info('AgentManager: agent completed', [
                    'agent'   => $agentName,
                    'time_ms' => $result['response_time_ms'] ?? 0,
                ]);
            }
        }

        return $results;
    }

    /**
     * Configure tools for an agent based on its config and preferences.
     *
     * Replicates the tool wiring from research.php: WebSearch (gated on
     * brave_api_key) and AcademicSearch (always available via arXiv).
     *
     * @param  HttpHelper $http   Centralized HTTP client
     * @param  array      $config Agent config.json (may contain brave_api_key)
     * @param  Logger     $logger Agent-specific logger
     * @return ToolRegistry       Configured tool registry
     */
    private function configureTools(HttpHelper $http, array $config, Logger $logger): ToolRegistry
    {
        $toolRegistry = new ToolRegistry($logger);

        // Web search: only if Brave API key is configured
        $braveApiKey = $config['brave_api_key'] ?? '';
        if ($braveApiKey !== '') {
            try {
                $webSearch = new WebSearch($http, [
                    'api_key' => $braveApiKey,
                ], $logger);
                $toolRegistry->register('web_search', [
                    'handler' => fn(array $params): string => $webSearch->execute($params),
                    'schema'  => [
                        'name'        => 'web_search',
                        'description' => 'Search the web via Brave Search API',
                        'parameters'  => [
                            'q'     => ['type' => 'string', 'description' => 'Search query'],
                            'count' => ['type' => 'integer', 'description' => 'Max results (1-20)'],
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                $logger->warn('AgentManager: WebSearch not configured', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Academic search: always attempted (arXiv requires no API key)
        try {
            $academicSearch = new AcademicSearch($http, [
                'max_results' => 5,
            ], $logger);
            $toolRegistry->register('paper_search', [
                'handler' => fn(array $params): string => $academicSearch->execute($params),
                'schema'  => [
                    'name'        => 'paper_search',
                    'description' => 'Search academic papers via arXiv and Semantic Scholar',
                    'parameters'  => [
                        'q'           => ['type' => 'string', 'description' => 'Search query'],
                        'max_results' => ['type' => 'integer', 'description' => 'Max results per API (1-20)'],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $logger->warn('AgentManager: AcademicSearch not configured', [
                'error' => $e->getMessage(),
            ]);
        }

        return $toolRegistry;
    }
}
