#!/usr/bin/env php
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

require_once __DIR__ . '/src/bootstrap.php';

use App\Agent\AgentManager;
use App\Arbitrator\Arbitrator;
use App\Config\Loader;
use App\Log\Logger;
use App\Output\Formatter;
use App\Session\Manager as SessionManager;
use App\Session\ProgressLogger;

// ---------------------------------------------------------------------------
// -- Setup
// ---------------------------------------------------------------------------

$projectRoot = __DIR__;
$sessionsDir = $projectRoot . '/sessions';
$historyFile = getenv('HOME') . '/.research-agents_history';

$configLoader = new Loader();

$agentManager = new AgentManager(
    $projectRoot . '/config/agents',
    $configLoader,
    $projectRoot . '/logs/research.log'
);

$agentConfigs = $agentManager->getAgentConfigs();
$agentCount = count($agentConfigs);

// -- History loading --------------------------------------------------------

if (file_exists($historyFile)) {
    readline_read_history($historyFile);
}

// -- Tab completion ---------------------------------------------------------

readline_completion_function(function (string $input, int $index): array {
    return ['/help', '/replay', '/config', '/agents', '/last', '/history', '/clear', '/exit'];
});

// -- SIGINT handler ---------------------------------------------------------

pcntl_signal(SIGINT, function (): void {
    echo PHP_EOL . "\e[33mInterrupted.\e[0m" . PHP_EOL;
});

// -- Startup message --------------------------------------------------------

echo Formatter::section('ResearchAgents REPL');
echo ' -- ' . $agentCount . ' agents configured, ready.' . PHP_EOL;

if ($agentCount === 0) {
    echo "\e[33mNo agents configured. Check your config/agents/ directory.\e[0m" . PHP_EOL;
}

// -- State for /last command ------------------------------------------------

$lastResult = null;
$lastDurationMs = 0;
$lastCorrelationId = '';

// ---------------------------------------------------------------------------
// -- Main REPL loop
// ---------------------------------------------------------------------------

while (true) {
    $input = readline(Formatter::prompt());

    if ($input === false) {
        // CTRL+D = EOF
        echo PHP_EOL;
        break;
    }

    $input = trim($input);

    if ($input === '') {
        continue;
    }

    readline_add_history($input);

    if (str_starts_with($input, '/')) {
        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $arg = $parts[1] ?? '';

        // Exit handled with if/else (match arms cannot contain break)
        if (in_array($command, ['/exit', '/quit'], true)) {
            break;
        }

        match ($command) {
            '/help'          => showHelp(),
            '/clear'         => showClear(),
            '/agents'        => showAgents($agentManager, $agentConfigs),
            '/config'        => showConfig($configLoader),
            '/history'       => showHistory($historyFile),
            '/last'          => showLast($lastResult, $lastDurationMs, $lastCorrelationId),
            '/replay'        => replaySession($arg, $projectRoot),
            default          => printf(
                "Unknown command: %s\nType /help for available commands.\n",
                $command
            ),
        };
    } else {
        $result = runResearch($input, $agentManager, $configLoader, $sessionsDir);
        if ($result !== null) {
            $lastResult = $result;
            $lastDurationMs = $result['duration_ms'] ?? 0;
            $lastCorrelationId = $result['correlation_id'] ?? '';
        }
    }
}

// -- Save history on exit ---------------------------------------------------

readline_write_history($historyFile);
$hist = readline_list_history();
if (is_array($hist) && count($hist) > 1000) {
    file_put_contents($historyFile, implode("\n", array_slice($hist, -1000)));
}

// ===================================================================
//  FUNCTION DEFINITIONS
// ===================================================================

/**
 * Run the full research pipeline for a question.
 *
 * Forks a child process to run Arbitrator::research() while the parent
 * displays a spinner and reads progress events from the session log.
 *
 * @param  string       $question      Research question
 * @param  AgentManager $agentManager  Agent discovery
 * @param  Loader       $configLoader  Config loader
 * @param  string       $sessionsDir   Path to sessions directory
 * @return array|null                  Research results or null on failure
 */
function runResearch(
    string $question,
    AgentManager $agentManager,
    Loader $configLoader,
    string $sessionsDir
): ?array {
    $correlationId = Logger::generateCorrelationId();
    $logger = new Logger(__DIR__ . '/logs/research.log', 'REPL', $correlationId);

    try {
        $slug = SessionManager::slugFromQuestion($question);
        $sessionDir = $sessionsDir . '/' . date('Y-m-d') . '_' . $slug;
        $progressLogPath = $sessionDir . '/session.log';

        // Ensure session directory exists for progress log
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0775, true);
        }

        $startTime = microtime(true);

        // Fork a child to run the research pipeline
        $researchPid = pcntl_fork();

        if ($researchPid === -1) {
            // Fork failed -- run synchronously (no progress display)
            $logger->warn('REPL: fork failed, running research synchronously');

            $arbitrator = new Arbitrator($agentManager, $configLoader, $logger);
            $arbitrator->setProgressLogFile($progressLogPath);
            $results = $arbitrator->research($question, $correlationId);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $debateResult = $arbitrator->getDebateResult();

            renderDebateOutput($results, $debateResult, $durationMs, $correlationId);
            saveSession($question, $results, $debateResult, $durationMs, $sessionsDir, $logger);

            return [
                'results'         => $results,
                'debate'          => $debateResult,
                'duration_ms'     => $durationMs,
                'correlation_id'  => $correlationId,
            ];
        }

        if ($researchPid === 0) {
            // ---- CHILD PROCESS ----
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm(0);
            pcntl_async_signals(true);

            $childTimedOut = false;
            pcntl_signal(SIGTERM, function (int $signo) use (&$childTimedOut): void {
                $childTimedOut = true;
            });

            $childArbitrator = new Arbitrator($agentManager, $configLoader, $logger);
            $childArbitrator->setProgressLogFile($progressLogPath);
            $childResults = $childArbitrator->research($question, $correlationId);

            // Write result to temp file for parent to collect
            $tempFile = sys_get_temp_dir() . '/repl_result_' . $correlationId . '.json';
            $resultData = [
                'results'     => $childResults,
                'debate'      => $childArbitrator->getDebateResult(),
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ];
            file_put_contents($tempFile, json_encode($resultData, JSON_UNESCAPED_SLASHES), LOCK_EX);
            exit(0);
        }

        // ---- PARENT: Show progress while child runs ----
        $spinnerFrames = ['|', '/', '-', '\\'];
        $spinnerIndex = 0;
        $progressLogger = new ProgressLogger($progressLogPath);
        $offset = 0;
        $childRunning = true;

        while ($childRunning) {
            // Check if child finished
            $reaped = pcntl_waitpid($researchPid, $status, WNOHANG);
            if ($reaped > 0 || $reaped === -1) {
                $childRunning = false;
                break;
            }

            // Display spinner
            echo "\r" . $spinnerFrames[$spinnerIndex % 4] . ' Researching...';
            $spinnerIndex++;

            // Read new progress lines from session.log
            $readResult = $progressLogger->readNewLines($offset);
            foreach ($readResult['lines'] as $line) {
                $event = json_decode($line, true);
                if ($event && isset($event['agent'], $event['event'])) {
                    $agentName = $event['agent'];
                    $eventName = $event['event'];
                    $agentLabel = $agentName !== '' ? $agentName : 'system';
                    echo "\n" . Formatter::statusLine($agentLabel, $eventName);
                    if (!empty($event['data'])) {
                        $dataInfo = $event['data'];
                        if (isset($dataInfo['model'])) {
                            echo ' (' . $dataInfo['model'] . ')';
                        } elseif (isset($dataInfo['tool'])) {
                            echo ' (' . $dataInfo['tool'] . ')';
                        }
                    }
                }
            }
            $offset = $readResult['new_offset'];

            usleep(200000); // 200ms for smooth animation
        }

        // Clear spinner line
        echo "\r\e[K";

        // Read research result from temp file
        $tempFile = sys_get_temp_dir() . '/repl_result_' . $correlationId . '.json';
        $resultData = null;
        if (file_exists($tempFile)) {
            $raw = file_get_contents($tempFile);
            if ($raw !== false) {
                $resultData = json_decode($raw, true);
            }
            @unlink($tempFile);
        }

        if ($resultData === null) {
            echo Formatter::error('Error: Research process failed to return results.') . PHP_EOL;
            return null;
        }

        // Render full output
        $durationMs = $resultData['duration_ms'];
        $results = $resultData['results'];
        $debateResult = $resultData['debate'];
        renderDebateOutput($results, $debateResult, $durationMs, $correlationId);

        // Save session
        saveSession($question, $results, $debateResult, $durationMs, $sessionsDir, $logger);

        return [
            'results'         => $results,
            'debate'          => $debateResult,
            'duration_ms'     => $durationMs,
            'correlation_id'  => $correlationId,
        ];
    } catch (\Throwable $e) {
        echo Formatter::error('Error: ' . $e->getMessage()) . PHP_EOL;
        $logger->error('REPL: research failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Render the complete debate output with ANSI colors.
 *
 * Replicates the research.php output format (score table, winner answer,
 * judge narrative, error summary) with colored formatting.
 *
 * @param array      $results       Agent results from Arbitrator::research()
 * @param array|null $debateResult  Debate result from Arbitrator::getDebateResult()
 * @param int        $durationMs    Total duration in milliseconds
 * @param string     $correlationId Correlation ID for tracing
 */
function renderDebateOutput(
    array $results,
    ?array $debateResult,
    int $durationMs,
    string $correlationId
): void {
    $agentCount = count($results);

    if ($debateResult === null) {
        // No debate (fewer than 2 agents) -- show simple completion
        echo PHP_EOL;
        echo Formatter::separator() . PHP_EOL;
        echo '  Research Complete' . PHP_EOL;
        printf("  Agents: %d | Correlation ID: %s" . PHP_EOL, $agentCount, $correlationId);
        echo Formatter::separator() . PHP_EOL;
        return;
    }

    $scoreTable = $debateResult['score_table'];
    $winner = $debateResult['winner'];
    $winnerResult = $results[$winner] ?? null;
    $narrative = $debateResult['narrative'] ?? '';

    // -- Section header --
    echo PHP_EOL;
    echo Formatter::section(str_repeat('=', 72)) . PHP_EOL;
    echo Formatter::section('  RESEARCH DEBATE RESULTS') . PHP_EOL;
    echo Formatter::section(str_repeat('=', 72)) . PHP_EOL;
    echo PHP_EOL;

    // -- Score table header --
    printf("  %-18s %6s %7s  %7s  %7s" . PHP_EOL,
        Formatter::section('Agent'),
        Formatter::section('Quality'),
        Formatter::section('Critique'),
        Formatter::section('Diversity'),
        Formatter::section('Total')
    );
    echo '  ' . Formatter::DIM . str_repeat('-', 60) . Formatter::RESET . PHP_EOL;

    // -- Score table rows --
    foreach ($scoreTable as $agentName => $scores) {
        $marker = ($scores['is_candidate'] ?? false) ? ' *' : '  ';

        // Agent name: pad raw text to 18 chars, then color
        $namePadded = str_pad($agentName . $marker, 18);
        echo '  ' . Formatter::agentName($namePadded) . ' ';

        // Quality: right-align number to 6 visual chars, color the number
        $qualityNum = number_format((float) ($scores['quality'] ?? 0), 1);
        echo str_repeat(' ', max(0, 6 - strlen($qualityNum)));
        echo Formatter::score((float) ($scores['quality'] ?? 0)) . '/10 ';

        // Critique, Diversity, Total: simple formatted values
        printf(
            "%7.2f  %7.2f  %7.3f",
            $scores['critique_avg'] ?? 0,
            $scores['diversity_bonus'] ?? 0,
            $scores['weighted_total'] ?? 0
        );

        if ($agentName === $winner) {
            echo Formatter::winner('  << WINNER');
        }
        echo PHP_EOL;
    }

    echo '  ' . Formatter::DIM . str_repeat('-', 60) . Formatter::RESET . PHP_EOL;
    echo "    * Candidate for final selection" . PHP_EOL;
    echo PHP_EOL;

    // -- Winner section --
    echo Formatter::separator() . PHP_EOL;
    echo '  ' . Formatter::winner('WINNER: ' . $winner) . PHP_EOL;
    echo Formatter::separator() . PHP_EOL;
    echo PHP_EOL;

    if ($winnerResult !== null) {
        // Strip potential ANSI codes from LLM output (T-05-02)
        $answerText = preg_replace('/\e\[[\d;]*[a-zA-Z]/', '', $winnerResult['answer']);
        echo $answerText . PHP_EOL;
        echo PHP_EOL;
        echo "---" . PHP_EOL;
        printf(
            "Model: %s | Response time: %dms | Tokens: %d in / %d out" . PHP_EOL,
            $winnerResult['model'] ?? 'unknown',
            $winnerResult['response_time_ms'] ?? 0,
            $winnerResult['usage']['prompt_tokens'] ?? 0,
            $winnerResult['usage']['completion_tokens'] ?? 0
        );
    }

    // -- Judge's reasoning --
    echo PHP_EOL;
    echo Formatter::separator() . PHP_EOL;
    echo '  ' . Formatter::section("JUDGE'S REASONING") . PHP_EOL;
    echo Formatter::separator() . PHP_EOL;
    echo PHP_EOL;

    $narrativeText = trim($narrative);
    if ($narrativeText === '') {
        $narrativeText = '[No narrative reasoning provided by the judge.]';
    }
    // Strip potential ANSI codes from LLM output (T-05-02)
    $narrativeText = preg_replace('/\e\[[\d;]*[a-zA-Z]/', '', $narrativeText);
    echo wordwrap($narrativeText, 72) . PHP_EOL;
    echo PHP_EOL;

    // -- Error summary --
    $errors = [];
    foreach ($results as $agentName => $result) {
        if (!empty($result['error'])) {
            $errors[$agentName] = $result['error'];
        }
    }
    if (!empty($errors)) {
        echo PHP_EOL;
        echo "---" . PHP_EOL;
        echo '  AGENT ERRORS:' . PHP_EOL;
        foreach ($errors as $name => $error) {
            echo '  - ' . Formatter::error($name . ': ' . $error) . PHP_EOL;
        }
    }

    // -- Research complete footer --
    echo PHP_EOL;
    echo Formatter::section(str_repeat('=', 72)) . PHP_EOL;
    echo Formatter::section('  Research Complete') . PHP_EOL;
    printf("  Agents: %d | Correlation ID: %s" . PHP_EOL, $agentCount, $correlationId);
    echo Formatter::section(str_repeat('=', 72)) . PHP_EOL;
}

/**
 * Display the /help command output.
 */
function showHelp(): void
{
    echo Formatter::section('Available commands:') . PHP_EOL;
    $commands = [
        '/help'          => 'Show this help message',
        '/replay <slug>' => 'Replay a past session by slug',
        '/config'        => 'Show arbitrator configuration',
        '/agents'        => 'List configured agents',
        '/last'          => 'Show the most recent research result',
        '/history'       => 'Show past questions from history',
        '/clear'         => 'Clear the screen',
        '/exit'          => 'Exit the REPL',
    ];
    foreach ($commands as $cmd => $desc) {
        echo '  ' . Formatter::command($cmd) . ': ' . $desc . PHP_EOL;
    }
}

/**
 * Display the /clear command output.
 */
function showClear(): void
{
    echo "\e[2J\e[H"; // ANSI clear screen + cursor home
}

/**
 * Display the /agents command output.
 */
function showAgents(AgentManager $agentManager, array $agentConfigs): void
{
    if (empty($agentConfigs)) {
        echo Formatter::error('No agents configured.') . PHP_EOL;
        return;
    }

    echo Formatter::section('Configured Agents:') . PHP_EOL;
    foreach ($agentConfigs as $name => $config) {
        $model = $config['config']['model'] ?? 'unknown';
        echo '  ' . Formatter::agentName($name) . ' ' . Formatter::DIM . $model . Formatter::RESET . PHP_EOL;
    }
}

/**
 * Display the /config command output.
 */
function showConfig(Loader $configLoader): void
{
    $configPath = __DIR__ . '/config/arbitrator/config.json';
    if (!file_exists($configPath)) {
        echo Formatter::error('Config file not found.') . PHP_EOL;
        return;
    }
    try {
        $config = $configLoader->load($configPath, required: [], types: []);
    } catch (\Throwable $e) {
        echo Formatter::error('Cannot load config: ' . $e->getMessage()) . PHP_EOL;
        return;
    }

    echo Formatter::section('Arbitrator Configuration:') . PHP_EOL;
    foreach ($config as $key => $value) {
        $display = is_array($value) ? json_encode($value) : (string) $value;
        if ($key === 'api_key' && $value !== '') {
            $display = '****'; // Mask API key
        }
        echo '  ' . Formatter::DIM . $key . Formatter::RESET . ': ' . $display . PHP_EOL;
    }
}

/**
 * Display the /history command output.
 */
function showHistory(string $historyFile): void
{
    if (!file_exists($historyFile)) {
        echo 'No history yet.' . PHP_EOL;
        return;
    }

    $history = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($history === false) {
        echo 'No history yet.' . PHP_EOL;
        return;
    }

    $history = array_filter($history, fn (string $line): bool => !str_starts_with($line, '/'));

    if (empty($history)) {
        echo 'No research questions in history.' . PHP_EOL;
        return;
    }

    // Show last 20 questions
    $recent = array_slice(array_values($history), -20);
    foreach ($recent as $i => $q) {
        echo ($i + 1) . '. ' . $q . PHP_EOL;
    }
}

/**
 * Display the /last command output -- re-renders the most recent research result.
 */
function showLast(?array $lastResult, int $lastDurationMs, string $lastCorrelationId): void
{
    if ($lastResult === null) {
        echo 'No research has been run yet. Type a question to start.' . PHP_EOL;
        return;
    }
    renderDebateOutput(
        $lastResult['results'] ?? [],
        $lastResult['debate'] ?? null,
        $lastDurationMs,
        $lastCorrelationId
    );
}

/**
 * Replay a session from its slug -- loads session.md and renders with ANSI.
 */
function replaySession(string $slug, string $projectRoot): void
{
    if ($slug === '') {
        echo 'Usage: /replay <slug>' . PHP_EOL;
        echo 'Example: /replay 2026-06-13_what-is-quantum-computing' . PHP_EOL;
        return;
    }

    // Prevent path traversal (T-05-03)
    if (str_contains($slug, '/') || str_contains($slug, '..')) {
        echo Formatter::error('Invalid session slug.') . PHP_EOL;
        return;
    }

    $sessionsDir = $projectRoot . '/sessions';
    $manager = new SessionManager($sessionsDir);
    $session = $manager->readSession($slug);

    if ($session === null) {
        echo Formatter::error('Session not found: ' . $slug) . PHP_EOL;
        echo 'The session "' . $slug . '" does not exist.' . PHP_EOL;
        return;
    }

    // Read raw session markdown for rendering
    $safeSlug = basename($slug);
    $markdownPath = $sessionsDir . '/' . $safeSlug . '/session.md';
    $content = file_get_contents($markdownPath);
    if ($content === false) {
        echo Formatter::error('Cannot read session file.') . PHP_EOL;
        return;
    }

    // Render markdown with ANSI:
    // -- frontmatter ignored
    // -- ## headers in Bold Cyan
    // -- **Winner:** in Bold Green
    // -- Errors in Dim Red
    $lines = explode("\n", $content);
    $inFrontmatter = false;
    foreach ($lines as $line) {
        if (str_starts_with($line, '---')) {
            if (!$inFrontmatter) {
                $inFrontmatter = true;
                continue;
            }
            $inFrontmatter = false;
            continue;
        }

        if ($inFrontmatter) {
            continue;
        }

        // Section headers (## ...)
        if (str_starts_with($line, '## ')) {
            echo Formatter::section($line) . PHP_EOL;
            continue;
        }

        // Winner line (**Winner:**)
        if (str_starts_with($line, '**Winner:**') || str_starts_with($line, '**WINNER:**')) {
            echo Formatter::winner($line) . PHP_EOL;
            continue;
        }

        // Error lines (- **Name:** Error|Failed|Timeout)
        if (preg_match('/^- \*\*(.+?)\*\*:\s*(Error|Failed|Timeout)/i', $line)) {
            echo Formatter::error($line) . PHP_EOL;
            continue;
        }

        // Table rows (| ... | ... | ...)
        if (str_starts_with($line, '|') && substr_count($line, '|') >= 4) {
            echo Formatter::DIM . $line . Formatter::RESET . PHP_EOL;
            continue;
        }

        echo $line . PHP_EOL;
    }
}

/**
 * Save the session transcript using SessionManager.
 *
 * Called after research completes. Errors are caught so they don't
 * crash the REPL.
 */
function saveSession(
    string $question,
    array $results,
    ?array $debateResult,
    int $durationMs,
    string $sessionsDir,
    Logger $logger
): void {
    try {
        $manager = new SessionManager($sessionsDir);
        $data = [
            'results'    => $results,
            'debate'     => $debateResult,
            'duration_ms' => $durationMs,
        ];
        $slug = $manager->createSession($question, $data);
        $logger->info('REPL: session saved', ['slug' => $slug]);
    } catch (\Throwable $e) {
        $logger->error('REPL: session save failed', ['error' => $e->getMessage()]);
    }
}
