#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use App\Agent\AgentManager;
use App\Arbitrator\Arbitrator;
use App\Config\Loader;
use App\Log\Logger;

// Usage check per D-08
if ($argc < 2) {
    echo "Usage: php research.php \"<research question>\"" . PHP_EOL;
    echo PHP_EOL;
    echo "Example: php research.php \"What are the latest advances in transformer architectures?\"" . PHP_EOL;
    exit(1);
}

$question = trim($argv[1]);

if ($question === '') {
    echo "Usage: php research.php \"<research question>\"" . PHP_EOL;
    echo PHP_EOL;
    echo "Error: Research question cannot be empty." . PHP_EOL;
    exit(1);
}

// Initialize components with session correlation ID
$correlationId = Logger::generateCorrelationId();
$logger = new Logger(__DIR__ . '/logs/research.log', 'SYSTEM', $correlationId);

// Log question start (truncated to 200 chars per T-01-11)
$logger->info('Research started', [
    'question' => mb_substr($question, 0, 200),
]);

try {
    $configLoader = new Loader();

    $agentManager = new AgentManager(
        __DIR__ . '/config/agents',
        $configLoader,
        __DIR__ . '/logs/research.log',
        $logger
    );

    $arbitrator = new Arbitrator(
        $agentManager,
        $configLoader,
        $logger
    );

    $results = $arbitrator->research($question, $correlationId);

    // Formatted output per agent (D-15, extended for multi-agent)
    $agentCount = count($results);

    foreach ($results as $agentName => $result) {
        echo PHP_EOL;
        echo "=== Agent: {$agentName} ===" . PHP_EOL;
        echo PHP_EOL;
        echo $result['answer'] . PHP_EOL;
        echo PHP_EOL;
        echo "---" . PHP_EOL;
        printf(
            "Model: %s | Response time: %dms | Tokens: %d in / %d out" . PHP_EOL,
            $result['model'],
            $result['response_time_ms'],
            $result['usage']['prompt_tokens'],
            $result['usage']['completion_tokens']
        );
    }

    echo PHP_EOL;
    echo "== Research Complete ==" . PHP_EOL;
    printf("Agents: %d | Correlation ID: %s" . PHP_EOL, $agentCount, $correlationId);

    $logger->info('Research completed successfully', [
        'agent_count' => $agentCount,
        'correlation' => $correlationId,
    ]);

    exit(0);
} catch (\Throwable $e) {
    $logger->error('Research failed', [
        'error' => $e->getMessage(),
    ]);

    echo "Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
