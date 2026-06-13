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

    // Formatted output per agent (D-15, extended for multi-agent with Phase 4 debate)
    $agentCount = count($results);

    // Check for debate result (Phase 4)
    $debateResult = $arbitrator->getDebateResult();

    if ($debateResult !== null) {
        // === Phase 4: Debate output with score table + winner ===

        // Score breakdown table
        echo PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo "  RESEARCH DEBATE RESULTS" . PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo PHP_EOL;

        echo "  QUESTION: " . $question . PHP_EOL;
        echo PHP_EOL;

        // Score table header
        printf(
            "  %-20s %8s %10s %10s %10s" . PHP_EOL,
            'Agent', 'Quality', 'Critique', 'Diversity', 'Total'
        );
        printf("  %s\n", str_repeat('-', 62));

        // Score table rows
        $scoreTable = $debateResult['score_table'];
        foreach ($scoreTable as $agentName => $scores) {
            $marker = $scores['is_candidate'] ? ' *' : '  ';
            printf(
                "  %-18s %6.1f/10 %7.2f  %7.2f  %7.3f%s" . PHP_EOL,
                $agentName . $marker,
                $scores['quality'],
                $scores['critique_avg'],
                $scores['diversity_bonus'],
                $scores['weighted_total'],
                ($agentName === $debateResult['winner']) ? '  << WINNER' : ''
            );
        }
        printf("  %s\n", str_repeat('-', 62));
        echo "    * Candidate for final selection" . PHP_EOL;
        echo PHP_EOL;

        // Winner narrative
        $winnerName = $debateResult['winner'];
        $winnerResult = $results[$winnerName] ?? null;

        echo str_repeat('-', 72) . PHP_EOL;
        echo "  WINNER: {$winnerName}" . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo PHP_EOL;

        if ($winnerResult !== null) {
            echo $winnerResult['answer'] . PHP_EOL;
            echo PHP_EOL;
            echo "---" . PHP_EOL;
            printf(
                "Model: %s | Response time: %dms | Tokens: %d in / %d out" . PHP_EOL,
                $winnerResult['model'],
                $winnerResult['response_time_ms'],
                $winnerResult['usage']['prompt_tokens'] ?? 0,
                $winnerResult['usage']['completion_tokens'] ?? 0
            );
        }

        // Judge narrative
        echo PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo "  JUDGE'S REASONING" . PHP_EOL;
        echo str_repeat('-', 72) . PHP_EOL;
        echo PHP_EOL;
        $narrative = $debateResult['narrative'] ?? '';
        if (trim($narrative) === '') {
            $narrative = '[No narrative reasoning provided by the judge.]';
        }
        echo wordwrap($narrative, 72) . PHP_EOL;
        echo PHP_EOL;

        // Error summary (if any agents had issues)
        $errors = [];
        foreach ($results as $agentName => $result) {
            if (!empty($result['error'])) {
                $errors[$agentName] = $result['error'];
            }
        }
        if (!empty($errors)) {
            echo PHP_EOL;
            echo "---" . PHP_EOL;
            echo "  AGENT ERRORS:" . PHP_EOL;
            foreach ($errors as $name => $error) {
                echo "  - {$name}: {$error}" . PHP_EOL;
            }
        }

        echo PHP_EOL;
        echo str_repeat('=', 72) . PHP_EOL;
        echo "  Research Complete" . PHP_EOL;
        printf("  Agents: %d | Correlation ID: %s" . PHP_EOL, $agentCount, $correlationId);
        echo str_repeat('=', 72) . PHP_EOL;

    } else {
        // Fallback: pre-debate per-agent output (Phase 3 style)
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
    }

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
