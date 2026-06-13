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

namespace App\Agent;

use App\Config\Loader;
use App\Http\HttpHelper;
use App\Log\Logger;
use App\Tool\AcademicSearch;
use App\Tool\ToolRegistry;
use App\Tool\WebSearch;

/**
 * Agent discovery only -- orchestration moved to Arbitrator.
 *
 * Scans agent directories under the configured base path for config.json
 * files to discover configured agents. Returns agent configs via getAgentConfigs()
 * for the Arbitrator to manage execution.
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
    public function configureTools(HttpHelper $http, array $config, Logger $logger): ToolRegistry
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
