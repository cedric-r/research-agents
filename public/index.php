<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Session\Manager as SessionManager;
use App\Session\ProgressLogger;

define('PROJECT_ROOT', __DIR__ . '/..');
define('SESSIONS_DIR', PROJECT_ROOT . '/sessions');

// ---- ROUTE DISPATCHER ----

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

match (true) {
    $uri === '/' || $uri === ''                    => handleHome(),
    $uri === '/api/ask'                            => handleApiAsk(),
    $uri === '/sessions'                           => handleSessionsList(),
    (bool) preg_match('#^/session/([^/]+)$#', $uri, $m) => handleSessionView($m[1]),
    (bool) preg_match('#^/session/([^/]+)/stream$#', $uri, $m) => handleSseStream($m[1]),
    default                                        => handleNotFound(),
};

// ---- HANDLE HOME (GET /) ----

function handleHome(): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResearchAgents</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>ResearchAgents</h1>
            <p class="subtitle">Multi-agent research and debate system</p>
        </header>

        <main>
            <section class="card">
                <h2>Ask a Research Question</h2>
                <form action="/api/ask" method="POST" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Starting...';">
                    <div class="form-group">
                        <label for="question">Your Research Question</label>
                        <textarea id="question" name="question" rows="4" placeholder="Enter your research question here..." required></textarea>
                    </div>
                    <button type="submit" class="btn">Start Research</button>
                </form>
            </section>

            <nav class="nav-links">
                <a href="/sessions">View Past Research Sessions</a>
            </nav>
        </main>
    </div>
</body>
</html><?php
}

// ---- HANDLE API ASK (POST /api/ask) ----

function handleApiAsk(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        return;
    }

    $question = trim($_POST['question'] ?? '');
    if ($question === '') {
        http_response_code(400);
        echo 'Question cannot be empty.';
        return;
    }

    // Cap question length to prevent abuse
    $question = mb_substr($question, 0, 2000);

    // Generate session ID
    $slug = SessionManager::slugFromQuestion($question);
    $sessionId = date('Y-m-d') . '_' . $slug;
    $sessionDir = SESSIONS_DIR . '/' . $sessionId;

    // Create session directory
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }

    // Save the question text for display before research completes
    file_put_contents($sessionDir . '/question.txt', $question);

    // Escape question for shell (CRITICAL: T-05-02 command injection prevention)
    $escapedQuestion = escapeshellarg($question);

    // Output log for stdout/stderr from the forked process
    $outputLog = $sessionDir . '/output.log';

    // D-12: exec with nohup to detach from parent process
    // Redirect stdout and stderr to output.log to prevent blocking (Pitfall 3)
    $cmd = sprintf(
        'nohup php %s/research.php %s > %s 2>&1 & echo $!',
        escapeshellarg(PROJECT_ROOT),
        $escapedQuestion,
        escapeshellarg($outputLog)
    );
    exec($cmd, $output, $resultCode);

    // Save PID for status checking
    if (!empty($output) && is_numeric($output[0])) {
        file_put_contents($sessionDir . '/.pid', (string) ((int) $output[0]));
    }

    // Redirect to session page
    header('Location: /session/' . rawurlencode($sessionId));
    exit;
}

// ---- HANDLE SESSION VIEW (GET /session/{id}) ----

function handleSessionView(string $sessionId): void
{
    // Prevent path traversal (T-05-03)
    $sessionId = basename($sessionId);

    $sessionDir = SESSIONS_DIR . '/' . $sessionId;

    if (!is_dir($sessionDir)) {
        handleNotFound('Session "' . htmlspecialchars($sessionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" not found.');
        return;
    }

    // Try to read session data (if session.md already exists -- completed)
    $sessionData = null;
    $sessionMdPath = $sessionDir . '/session.md';
    if (file_exists($sessionMdPath)) {
        $manager = new SessionManager(SESSIONS_DIR);
        $sessionData = $manager->readSession($sessionId);
    }

    // Fallback: read question from question.txt if session not yet complete
    if ($sessionData === null) {
        $questionFile = $sessionDir . '/question.txt';
        $question = file_exists($questionFile)
            ? trim(file_get_contents($questionFile) ?: '')
            : 'Research in progress...';
    } else {
        $question = $sessionData['question'] ?? 'Research in progress...';
    }

    $truncatedQuestion = mb_substr($question, 0, 60) . (mb_strlen($question) > 60 ? '...' : '');

    $researchComplete = $sessionData !== null;

    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session: <?= htmlspecialchars($truncatedQuestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> - ResearchAgents</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Session: <?= htmlspecialchars($truncatedQuestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="session-id">ID: <?= htmlspecialchars($sessionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </header>

        <main>
            <section class="card">
                <h2>Progress Log</h2>
                <div id="log" class="log-display">
                    <?php if ($researchComplete): ?>
                        <div class="log-line">Research complete. <a href="/session/<?= rawurlencode($sessionId) ?>/transcript">View transcript</a></div>
                    <?php else: ?>
                        <div class="log-line system">Waiting for research to start...</div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($sessionData): ?>
            <section class="card">
                <h2>Summary</h2>
                <table class="session-table">
                    <tr><td>Winner:</td><td><strong><?= htmlspecialchars($sessionData['winner'] ?? 'N/A', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td></tr>
                    <tr><td>Agents:</td><td><?= htmlspecialchars((string) ($sessionData['agent_count'] ?? '0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                    <tr><td>Duration:</td><td><?= isset($sessionData['duration_ms']) ? round((int) $sessionData['duration_ms'] / 1000, 1) . 's' : 'N/A' ?></td></tr>
                    <?php if (!empty($sessionData['model_info'])): ?>
                    <tr><td>Models:</td><td><?= htmlspecialchars((string) $sessionData['model_info'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                    <?php endif; ?>
                </table>
            </section>
            <?php endif; ?>

            <?php if ($sessionData && file_exists($sessionDir . '/session.md')): ?>
            <section class="card">
                <h2>Full Transcript</h2>
                <div class="log-display" style="white-space: pre-wrap;"><?php
                    $content = file_get_contents($sessionDir . '/session.md');
                    echo $content !== false ? htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '[Unable to read transcript]';
                ?></div>
            </section>
            <?php endif; ?>

            <nav class="nav-links">
                <a href="/">New Research Question</a>
                <a href="/sessions">All Sessions</a>
            </nav>
        </main>
    </div>

    <?php if (!$researchComplete): ?>
    <script>
        const eventSource = new EventSource('/session/<?= rawurlencode($sessionId) ?>/stream');
        const logContainer = document.getElementById('log');

        eventSource.addEventListener('progress', function(e) {
            const line = document.createElement('div');
            line.className = 'log-line';
            line.textContent = e.data;
            logContainer.appendChild(line);
            logContainer.scrollTop = logContainer.scrollHeight;
        });

        eventSource.addEventListener('done', function(e) {
            const line = document.createElement('div');
            line.className = 'log-line complete';
            line.textContent = 'Research complete.';
            logContainer.appendChild(line);
            eventSource.close();
        });

        eventSource.onerror = function() {
            const line = document.createElement('div');
            line.className = 'log-line error';
            line.textContent = 'Connection lost. Refresh the page to reconnect.';
            logContainer.appendChild(line);
        };
    </script>
    <?php endif; ?>
</body>
</html><?php
}

// ---- HANDLE SESSIONS LIST (GET /sessions) ----

function handleSessionsList(): void
{
    $manager = new SessionManager(SESSIONS_DIR);
    $sessions = $manager->listSessions();

    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Past Research Sessions - ResearchAgents</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Past Research Sessions</h1>
        </header>

        <main>
            <?php if (empty($sessions)): ?>
            <section class="card">
                <p class="empty-state">No research sessions yet.</p>
                <p>Submit your first question using the form above.</p>
            </section>
            <?php else: ?>
            <section class="card">
                <table class="session-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Question</th>
                            <th>Agents</th>
                            <th>Winner</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $session): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($session['date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><a href="/session/<?= rawurlencode($session['id'] ?? '') ?>"><?= htmlspecialchars(mb_substr((string) ($session['question'] ?? ''), 0, 80), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></td>
                            <td><?= htmlspecialchars((string) ($session['agent_count'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><strong><?= htmlspecialchars((string) ($session['winner'] ?? 'N/A'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <nav class="nav-links">
                <a href="/">New Research Question</a>
            </nav>
        </main>
    </div>
</body>
</html><?php
}

// ---- HANDLE SSE STREAM (GET /session/{id}/stream) ----

function handleSseStream(string $sessionId): void
{
    // Prevent path traversal (T-05-03)
    $sessionId = basename($sessionId);

    $sessionDir = SESSIONS_DIR . '/' . $sessionId;
    $logFile = $sessionDir . '/session.log';

    // SSE headers (UI-SPEC 4.6)
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    // Disable output buffering (Pitfall 2)
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_implicit_flush(true);

    set_time_limit(0);

    $offset = 0;
    $keepaliveCounter = 0;
    $startTime = time();
    $maxDuration = 300; // 5 minute max SSE connection

    while (true) {
        // Check for client disconnect (T-05-04)
        if (connection_aborted()) {
            break;
        }

        // Max duration safety net
        if (time() - $startTime > $maxDuration) {
            echo "event: done\ndata: {}\n\n";
            ob_flush();
            flush();
            break;
        }

        // Poll for new content in session.log
        if (file_exists($logFile)) {
            $size = filesize($logFile);
            if ($size > $offset) {
                $handle = fopen($logFile, 'r');
                if ($handle) {
                    fseek($handle, $offset);
                    $content = stream_get_contents($handle);
                    fclose($handle);

                    if ($content !== false && $content !== '') {
                        $lines = array_filter(explode("\n", $content), fn($l) => $l !== '');
                        foreach ($lines as $line) {
                            echo "event: progress\n";
                            echo "data: {$line}\n\n";
                            ob_flush();
                            flush();
                        }
                    }
                    $offset = $size;
                }
                $keepaliveCounter = 0;
            }
        }

        // Keepalive comment every 15 seconds (UI-SPEC 4.6)
        $keepaliveCounter++;
        if ($keepaliveCounter >= 15) {
            echo ": keepalive\n\n";
            ob_flush();
            flush();
            $keepaliveCounter = 0;
        }

        // Check if research process completed
        // Method: check if session.md exists (written by research.php on completion)
        $sessionMd = $sessionDir . '/session.md';
        if (file_exists($sessionMd) && filesize($sessionMd) > 0) {
            // Process completed -- send done event and exit
            echo "event: done\ndata: {}\n\n";
            ob_flush();
            flush();
            break;
        }

        sleep(1); // Polling interval
    }
}

// ---- HANDLE NOT FOUND (default) ----

function handleNotFound(string $message = 'Page not found.'): void
{
    http_response_code(404);
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Not Found - ResearchAgents</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>ResearchAgents</h1>
        </header>
        <main>
            <section class="card error-card">
                <h2>Not Found</h2>
                <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <a href="/" class="btn">Back to Home</a>
            </section>
        </main>
    </div>
</body>
</html><?php
}

// ---- INITIALIZATION ----

// Ensure sessions directory exists
if (!is_dir(SESSIONS_DIR)) {
    @mkdir(SESSIONS_DIR, 0775, true);
}
