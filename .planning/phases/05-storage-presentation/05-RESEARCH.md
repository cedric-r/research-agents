# Phase 5: Storage & Presentation - Research

**Researched:** 2026-06-13
**Domain:** CLI REPL, Web REPL (SSE), Session Persistence, Progress Events
**Confidence:** HIGH

## Summary

Phase 5 wraps the completed research pipeline (Phases 1-4) with three user-facing capabilities: session persistence to markdown files for full traceability, an interactive CLI REPL using PHP readline, and a browser-based web REPL using the PHP built-in server with SSE streaming for real-time progress.

The phase builds directly on the existing `research.php` output format (score table + winner + judge narrative + error summary from Phase 4) and the Arbitrator's `pcntl_fork` parallelism pattern (Phase 3). No new external packages are needed -- everything uses native PHP 8.5.4 extensions (`readline`, `pcntl`, `posix`, `json`, `mbstring`, `curl`) already available on the runtime system.

**Primary recommendation:** Build three parallel vertical slices in dependency order: (1) session file infrastructure + progress event format, (2) CLI REPL with readline loop + replay, (3) web REPL with SSE + background process.

### Key Architectural Decisions (from CONTEXT.md)

- **Two entry points**: `research.php` stays as scriptable one-shot; new `repl.php` provides interactive readline loop.
- **Full command set**: `/help`, `/replay <id>`, `/config`, `/agents`, `/last`, `/history`, `/clear`, `/exit`.
- **Full ANSI formatting**: Section headers bold/cyan, winner bold green, scores colored, errors dim/red.
- **Session files**: `sessions/{slug}/session.md` (transcript) + `sessions/{slug}/session.log` (events).
- **Progress via log file tailing**: Children write structured events to session log; CLI tails, SSE polls.
- **Web via detached `exec()`**: POST -> `exec("php research.php ... &")` returns session ID, SSE polls log.
- **Multi-page web frontend**: `/` (form), `/session/{id}` (SSE results), `/sessions` (history list).


<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### CLI REPL Architecture (CLI-01, CLI-02, CLI-04)
- **D-01:** Both entry points -- research.php stays scriptable one-shot. New repl.php provides interactive readline loop. Shared output formatting utilities.
- **D-02:** Full command set -- /help, /replay <id>, /config, /agents, /last, /history, /clear, /exit. All inside readline loop.
- **D-03:** Full ANSI formatting (CLI-05) -- section headers (bold/cyan), winner (bold green), scores colored (high=green, mid=yellow, low=red), errors (dim/red), agent names colored, command names highlighted, session list colored by recency.
- **D-04:** Full detail output -- REPL shows complete debate output (score table, winner answer, judge narrative, error summary) after each research run.

#### Session Transcript Format (PERS-01 through PERS-04)
- **D-05:** Full everything -- transcript saves every agent's complete raw answer, quality scores, structured critique, diversity bonuses, score table, winner answer, judge narrative, tool call results, and error summary.
- **D-06:** Timestamp + slug filename with full frontmatter -- Pattern: sessions/2026-06-13_attention-models.md. Frontmatter includes: question, ISO date, agent count with agent names, winner, correlation ID, duration, model info, score table summary.
- **D-07:** Separate .log file alongside transcript -- sessions/{slug}/session.md + sessions/{slug}/session.log.
- **D-08:** Nested markdown structure -- Frontmatter (metadata) -> ## Summary (winner, scores table) -> ## Raw Answers (each agent's full output with tool results) -> ## Debate (critiques, judge reasoning) -> ## Errors.

#### Real-Time Progress Display (CLI-03)
- **D-09:** Log file tailing / watch pipe -- children write timestamped progress events to shared log. CLI reads from log (tail-like). Web SSE does the same.
- **D-10:** Spinner + log lines -- CLI shows spinner with status log lines appearing below.
- **D-11:** All tool call events -- Children log progress events for every major step.

#### Web REPL Architecture (WEB-01 through WEB-05)
- **D-12:** Detached PHP process via exec() -- POST generates session ID, saves question, calls exec("php research.php ... > sessions/{slug}/output.log 2>&1 &"), returns session ID immediately. SSE polls session log.
- **D-13:** Multi-page frontend -- / (form), /session/{id} (results with SSE), /sessions (past sessions list). Server-rendered HTML. No JS framework.
- **D-14:** Raw log lines as SSE events -- each new log line sent as `event: progress` with raw text.
- **D-15:** public/index.php + `php -S` -- `php -S localhost:8080 -t public/`. Front controller handles all routes.

### Claude's Discretion
- Exact readline command parsing implementation in repl.php
- ANSI color utility class/method design
- Progress event format in the shared log (how children write events, who manages the log)
- SSE polling interval and keepalive strategy
- Session list sorting and display format
- Error handling for exec() failures (process never started, session orphaned)
- sessions/ directory creation and permission handling

### Deferred Ideas (OUT OF SCOPE)
None.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PERS-01 | Each session creates new session with UUID and timestamp | UUID from bin2hex(random_bytes(16)). Timestamp from date('Y-m-d'). Filename format per D-06. Slug derivation from question (preg_replace, mb_substr). |
| PERS-02 | Full session transcript saved as markdown file | Nested markdown structure per D-08. Write via file_put_contents with LOCK_EX. Frontmatter parsing via explode('---', ...) for session list display. |
| PERS-03 | Transcript includes question, all agent answers, debate content, final selection | Debate result available from Arbitrator::getDebateResult(). Agent answers in $results array from Arbitrator::research(). Output format matches existing research.php. |
| PERS-04 | Session files stored under sessions/ directory | Must create sessions/ directory with mkdir(0775, true) if not exists. Add to .gitignore. Subdirectory per session: sessions/{slug}/. |
| LOG-03 | Per-session log files alongside session transcripts | Logger class accepts file path in constructor. Create Logger instance pointing to sessions/{slug}/session.log for each session. Same format as main log. |
| CLI-01 | Interactive REPL using PHP readline with command history | readline() loop, readline_add_history(), readline_read_history()/write_history() for persistence. Tab completion via readline_completion_function(). See patterns below. |
| CLI-02 | User types question, system returns final answer with trace | repl.php creates Arbitrator, calls $arbitrator->research(), then $arbitrator->getDebateResult(). Output via existing format + ANSI colors. |
| CLI-03 | Real-time progress display showing which phase is active | Children write progress events to session log. repl.php reads log from end in a loop between fork and wait. Spinner with \r carriage return. See progress event pattern. |
| CLI-04 | Commands for config check, session replay, help | Command parsing by matching $input against known patterns. AgentManager::discoverAgents() / getAgentConfigs() for /agents. Loader for /config. Session file reading for /replay. |
| CLI-05 | ANSI-formatted output for readability | ANSI escape sequences with \x01/\x02 wrapping for readline prompt. Color constants class with bold/cyan/green/yellow/red/dim. |
| WEB-01 | PHP built-in web server serves web REPL | php -S localhost:8080 -t public/ with public/index.php as front controller. Server-rendered HTML. |
| WEB-02 | Web form to submit research question | POST form on / route. Sanitize question input. Generate session ID. |
| WEB-03 | SSE streams research progress to browser | Content-Type: text/event-stream header. Disable output buffering. Poll session log file. See SSE pattern below. |
| WEB-04 | Background process pattern: POST returns ID, SSE streams events | exec("php research.php 'question' > sessions/{slug}/output.log 2>&1 & echo $!", $output). Capture PID. |
| WEB-05 | View past sessions from browser | Read sessions/ directory. Parse frontmatter from each session.md. Display as table with date, question, agent count, winner. |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Session file creation | CLI/API code | None | Created by research.php or repl.php after research completes. File system write, no server involved. |
| Session metadata management | CLI/API code | Web (read-only) | Session list and details computed from filesystem. Web REPL reads only. |
| CLI REPL loop | CLI (repl.php) | None | readline() loop runs in terminal. No web involvement. |
| Web form submission | Web (public/index.php) | None | Browser POSTs to PHP built-in server. Form handler in front controller. |
| Research execution | API (research.php) | CLI (repl.php) | Both entry points create Arbitrator and call research(). Identical pipeline. |
| Background process spawning | Web (public/index.php) | None | exec() called from within web request context. Forking happens inside research.php itself. |
| Real-time progress streaming | SSE endpoint | None | /api/stream/{id} in public/index.php. Reads session log file. |
| Session file reading | CLI (replay) | Web (view) | Both read sessions/{slug}/session.md. CLI renders with ANSI, web renders as HTML. |
| PID tracking | Web only | None | Captured from exec() output. Used to show "researching..." status before process completes. |
| Log file writing | Agent children | Arbitrator parent | Children (pcntl_fork) write progress events. Parent also writes step events. Shared session log. |

## Standard Stack

### Core
| Technology | Version | Purpose | Why Standard |
|------------|---------|---------|--------------|
| PHP readline | Built-in (8.5.4) | CLI REPL input | Zero-dependency interactive input with history, tab completion, signal handling. All available on runtime. |
| PHP pcntl_fork | Built-in (8.5.4) | Child process progress events | Already used in Phase 3/4. Children write progress events alongside existing temp file IPC. |
| PHP posix_kill | Built-in (8.5.4) | Background PID checking | posix_kill($pid, 0) for checking if exec'd research.php is still running. Avoids calling ps. |
| PHP exec() | Built-in (8.5.4) | Background process from web | Standard PHP pattern: exec("nohup ... & echo $!") to detach process and capture PID. Available in CLI and web SAPI. |
| PHP built-in web server | Built-in (8.5.4) | Web REPL serving | php -S localhost:8080 -t public/. Zero-config, single-threaded fine for single-user. |
| PHP file functions | Built-in (8.5.4) | Session storage, log reading | file_put_contents, file_get_contents, fopen/fgets for tailing, glob for directory listing, stat for file info. |
| PHP json_encode/decode | Built-in (8.5.4) | Structured data in logs | Progress events as JSON lines. Frontmatter as serialized JSON or YAML-like text. |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Monolog monolog ^3.10 | ^3.10 (Composer) | Structured logging | Already considered as optional in CLAUDE.md. Session log files can use same pattern. However, existing Logger class works fine -- defer. |
| None needed | -- | -- | All phase 5 capabilities use native PHP functions. No Composer packages required. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| readline() for CLI | fgets(STDIN) | readline() provides history, tab completion, signal handling. fgets is simpler but loses all those features. |
| exec() for detached process | proc_open() with pipe close | proc_open() gives more control but is more complex. exec() is simpler and sufficient -- research.php already handles its own forking. |
| file_get_contents for log polling | inotify extension | inotify would be real-time but requires PECL extension. 1-second polling with file_get_contents/size check is simple and reliable. |
| SSE for web progress | WebSocket | SSE is unidirectional server->client, simpler than WebSocket, works over standard HTTP. Perfect for progress streaming. |
| uuid extension for UUID | bin2hex(random_bytes(16)) | uuid extension comprehensive but not needed. 32 hex chars from random_bytes is sufficient for session identification. |

**Version verification (all built-in):** PHP 8.5.4 confirmed installed with extensions: readline, pcntl, posix, curl, json, mbstring. No additional packages needed.

## Package Legitimacy Audit

> **No external packages are installed by this phase.** All capabilities use native PHP 8.5.4 built-in functions and extensions. The project's composer.json already declares only `phpunit/phpunit` as a dev dependency for testing.

Zero packages to audit. The phase adds code files only: `repl.php`, `public/index.php`, plus utility files under `src/` namespace.

## Architecture Patterns

### System Architecture Diagram

```
+------------------+     +------------------+     +------------------+
|    Terminal      |     |    Browser       |     |    Browser       |
|  (repl.php)      |     |  (public/)      |     |  (public/)      |
+--------+---------+     +--------+---------+     +--------+---------+
         |                        |                        |
         | readline()             | POST /api/ask          | GET /session/{id}
         | loop                   |                        |
         v                        v                        v
+------------------+     +------------------+     +------------------+
|  repl.php        |     |  public/index.php|     |  public/index.php|
|  - parse input   |     |  - validate q    |     |  - read session  |
|  - /commands     |     |  - create session|     |  - render HTML   |
|  - run research  |     |  - exec(research)|     |  - SSE stream    |
+--------+---------+     +--------+---------+     +--------+---------+
         |                        |                        |
         | create                 | background process     | poll log file
         | Arbitrator             |                        |
         v                        v                        v
+------------------+     +------------------+     +------------------+
|  Arbitrator      |     |  research.php    |     |  session.log     |
|  - pcntl_fork    |     |  - create        |     |  (file-based)    |
|  - agents        |     |    Arbitrator    |     +------------------+
|  - debate        |     |  - research()    |
+--------+---------+     +--------+---------+
         |                        |
         | children write         | on complete:
         | progress events        | write session.md
         v                        v
+------------------+     +------------------+
|  Session Log     |     |  Session         |
|  (file: events)  |     |  Transcript      |
|  sessions/{slug}/|     |  (file: .md)     |
|  session.log     |     |  sessions/{slug}/|
+------------------+     |  session.md      |
                          +------------------+

Data Flow:
1. User question enters through CLI (readline) or Web (POST form)
2. Session ID generated, session directory created (sessions/{slug}/)
3. Research pipeline runs (fork children -> collect -> score -> critique -> select winner)
4. Children write progress events to session.log as they execute
5. CLI: polls session.log between fork and wait, displays spinner + log lines
6. Web: returns session ID immediately, SSE endpoint polls session.log, browser appends to <pre>
7. On completion: session.md transcript written with full debate output
```

### Recommended Project Structure

```
research-agents/
├── research.php                    # One-shot CLI entry point (unchanged)
├── repl.php                        # NEW: Interactive REPL with readline loop
├── public/
│   └── index.php                   # NEW: Front controller for web REPL (php -S)
├── src/
│   ├── bootstrap.php               # Autoloader (unchanged)
│   ├── Output/
│   │   └── Formatter.php           # NEW: Shared ANSI/plain output formatting
│   ├── Session/
│   │   ├── Manager.php             # NEW: Session create/list/read/delete
│   │   └── ProgressLogger.php      # NEW: Progress event writing/reading
│   └── ... (existing source)
├── sessions/                       # NEW: Session storage (gitignored)
│   ├── .gitkeep                    # Ensures directory exists in repo
│   └── 2026-06-13_attention-models/
│       ├── session.md              # Transcript
│       ├── session.log             # Progress events
│       └── output.log              # Raw stdout redirect from exec()
├── config/
│   └── sessions/
│       └── config.json             # NEW: Session config (max sessions, etc.)
├── logs/
│   └── research.log                # Main log (unchanged)
└── .gitignore                      # Update: add sessions/
```

### Pattern 1: CLI REPL with readline Loop

**What:** Interactive read-eval-print loop using PHP's readline extension with persistent history, tab completion, signal handling, and ANSI-colored prompt.

**When to use:** The main `repl.php` entry point. Provides continuous interactive access to the research pipeline.

**Key implementation notes:**
- Use `\x01`/`\x02` wrapping around ANSI escape codes in readline prompts to prevent readline from miscounting cursor position (see Pitfall 1)
- SIGINT handling via `pcntl_signal(SIGINT, ...)` to gracefully interrupt research
- History file at `$HOME/.research-agents_history` loaded/saved across sessions
- Tab completion function for commands: `/help`, `/replay`, `/config`, etc.

```php
<?php
// Source: PHP readline manual + verified community patterns [CITED: php.net/readline]

declare(strict_types=1);

// ANSI color constants with \x01/\x02 for readline compatibility
// \x01 = SOH (readline stops counting width), \x02 = STX (readline resumes counting)
const RL_CYAN  = "\x01\e[36m\x02";
const RL_GREEN = "\x01\e[32m\x02";
const RL_BOLD  = "\x01\e[1m\x02";
const RL_RESET = "\x01\e[0m\x02";

$historyFile = getenv('HOME') . '/.research-agents_history';

// Load persisted history
if (file_exists($historyFile)) {
    readline_read_history($historyFile);
}

// Register tab completion for commands
readline_completion_function(function (string $input, int $index): array {
    $commands = ['/help', '/replay', '/config', '/agents', '/last', '/history', '/clear', '/exit'];
    return $commands; // Let readline do the matching
});

// SIGINT handler: allow graceful interruption during research
pcntl_signal(SIGINT, function (): void {
    echo PHP_EOL . "\e[33mInterrupted.\e[0m" . PHP_EOL;
});

while (true) {
    $input = readline(RL_GREEN . 'research> ' . RL_RESET);

    if ($input === false) { // CTRL+D
        echo PHP_EOL;
        break;
    }

    $input = trim($input);

    if ($input === '') {
        continue;
    }

    readline_add_history($input);

    // Command parsing: /commands or plain question
    if (str_starts_with($input, '/')) {
        $parts = explode(' ', $input, 2);
        $command = $parts[0];
        $arg = $parts[1] ?? '';

        match ($command) {
            '/exit', '/quit' => break 2,
            '/help'          => showHelp(),
            '/clear'         => showClear(),
            '/agents'        => showAgents(),
            '/config'        => showConfig(),
            '/history'       => showHistory(),
            '/last'          => rerunLastQuestion(),
            '/replay'        => replaySession($arg),
            default          => printf("Unknown command: %s\n", $command),
        };
    } else {
        runResearch($input);
    }
}

// Save history on exit (truncate to 1000 entries)
if (readline_write_history($historyFile)) {
    $hist = readline_list_history();
    if (count($hist) > 1000) {
        file_put_contents(
            $historyFile,
            implode("\n", array_slice($hist, -1000))
        );
    }
}
```

### Pattern 2: Progress Event Log (Shared Between CLI and Web)

**What:** A structured log file format that child processes write to and both CLI and web UIs read from.

**When to use:** Every forked child process during research execution writes progress events. The parent arbitrator writes batch-level events. Both CLI and SSE endpoints read from the same file.

**Progress event format (one JSON object per line):**
```
{"ts":"2026-06-13T10:30:00.123Z","agent":"alpha","channel":"PROGRESS","event":"started","data":{"question":"...","model":"deepseek-v4-flash"}}
{"ts":"2026-06-13T10:30:01.456Z","agent":"alpha","channel":"PROGRESS","event":"llm_call","data":{"model":"deepseek-v4-flash"}}
{"ts":"2026-06-13T10:30:02.789Z","agent":"beta","channel":"PROGRESS","event":"web_search","data":{"query":"..."}}
{"ts":"2026-06-13T10:30:05.234Z","agent":"alpha","channel":"PROGRESS","event":"score_evaluated","data":{"quality":8.5}}
{"ts":"2026-06-13T10:30:10.567Z","agent":"","channel":"PROGRESS","event":"batch_complete","data":{"agent_count":3}}
```

**Event types (from D-11):**
- `started` -- agent begins research
- `llm_call` -- LLM API call started (tool context building)
- `web_search` -- web search tool executing
- `paper_search` -- paper search tool executing
- `tool_result` -- tool returned results
- `score_evaluated` -- scoring LLM returned quality scores
- `critique_r2_started` -- Round 2 critique prompt being sent to LLM
- `critique_completed` -- Round 2 critique received
- `completed` -- agent finished successfully
- `timed_out` -- agent exceeded deadline
- `failed` -- agent encountered error

```php
<?php
// Source: Derived from project's Logger pattern [VERIFIED: file inspection]

namespace App\Session;

class ProgressLogger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Write a progress event as a JSON line.
     * Safe to call from child processes (pcntl_fork context).
     */
    public function logEvent(
        string $event,
        string $agent = '',
        array $data = []
    ): void {
        $entry = json_encode([
            'ts'      => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'agent'   => $agent,
            'channel' => 'PROGRESS',
            'event'   => $event,
            'data'    => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($this->logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Read new lines from the log file since the last known position.
     * Used by both CLI tailing and SSE polling.
     *
     * @return array{lines: array, new_offset: int}
     */
    public function readNewLines(int $offset): array
    {
        if (!file_exists($this->logFile)) {
            return ['lines' => [], 'new_offset' => 0];
        }

        $size = filesize($this->logFile);
        if ($size <= $offset) {
            return ['lines' => [], 'new_offset' => $offset];
        }

        $handle = fopen($this->logFile, 'r');
        if ($handle === false) {
            return ['lines' => [], 'new_offset' => $offset];
        }

        fseek($handle, $offset);
        $content = stream_get_contents($handle);
        fclose($handle);

        if ($content === false || $content === '') {
            return ['lines' => [], 'new_offset' => $size];
        }

        $lines = array_filter(explode("\n", $content), fn($l) => $l !== '');
        return ['lines' => $lines, 'new_offset' => $size];
    }
}
```

### Pattern 3: Web Front Controller + SSE Streaming

**What:** A front controller (`public/index.php`) for the PHP built-in web server that routes requests and serves the SSE stream.

**When to use:** User starts `php -S localhost:8080 -t public/` to serve the web REPL.

```php
<?php
// Source: PHP manual SSE pattern [CITED: php.net/manual/sse] + exec() pattern [CITED: php.net/exec]

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Session\Manager as SessionManager;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Route handling
match ($uri) {
    '/'                         => handleHome(),
    '/api/ask'                  => handleApiAsk(),
    default => handleSessionRoute($uri),
};

function handleHome(): void {
    ?><!DOCTYPE html>
    <html>
    <head><title>ResearchAgents</title></head>
    <body>
        <h1>ResearchAgents</h1>
        <form action="/api/ask" method="POST">
            <textarea name="question" rows="3" cols="80" required></textarea>
            <br>
            <button type="submit">Start Research</button>
        </form>
        <p><a href="/sessions">View past sessions</a></p>
    </body>
    </html><?php
}

function handleApiAsk(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method not allowed';
        return;
    }

    $question = trim($_POST['question'] ?? '');
    if ($question === '') {
        http_response_code(400);
        echo 'Question cannot be empty';
        return;
    }

    // Create session directory
    $slug = SessionManager::slugFromQuestion($question);
    $sessionId = date('Y-m-d') . '_' . $slug;
    $sessionDir = __DIR__ . '/../sessions/' . $sessionId;
    @mkdir($sessionDir, 0775, true);

    // Escape and launch
    $escapedQuestion = escapeshellarg($question);
    $outputLog = $sessionDir . '/output.log';

    // D-12: exec with nohup to detach from parent
    // Capture PID for status checking
    $cmd = sprintf(
        'nohup php %s/research.php %s > %s 2>&1 & echo $!',
        escapeshellarg(__DIR__ . '/..'),
        $escapedQuestion,
        escapeshellarg($outputLog)
    );
    exec($cmd, $output, $resultCode);

    // Redirect to session page
    header('Location: /session/' . rawurlencode($sessionId));
    exit;
}

function handleSessionRoute(string $uri): void {
    // Match /session/{id} and /session/{id}/stream
    if (preg_match('#^/session/([^/]+)(?:/stream)?$#', $uri, $matches)) {
        $sessionId = $matches[1];

        if (str_ends_with($uri, '/stream')) {
            handleSseStream($sessionId);
        } else {
            handleSessionView($sessionId);
        }
        return;
    }

    // Match /sessions (list page)
    if ($uri === '/sessions') {
        handleSessionsList();
        return;
    }

    http_response_code(404);
    echo 'Not found';
}

function handleSseStream(string $sessionId): void {
    // SSE headers [CITED: sse spec]
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    // Disable output buffering
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_implicit_flush(true);

    set_time_limit(0);

    $sessionDir = __DIR__ . '/../sessions/' . $sessionId;
    $logFile = $sessionDir . '/session.log';
    $offset = 0;

    $keepaliveCounter = 0;

    while (true) {
        if (connection_aborted()) {
            break;
        }

        if (file_exists($logFile)) {
            $size = filesize($logFile);
            if ($size > $offset) {
                $handle = fopen($logFile, 'r');
                if ($handle) {
                    fseek($handle, $offset);
                    while (!feof($handle)) {
                        $line = fgets($handle);
                        if ($line === false) break;
                        $line = trim($line);
                        if ($line === '') continue;
                        echo "event: progress\n";
                        echo "data: {$line}\n\n";
                        ob_flush();
                        flush();
                    }
                    fclose($handle);
                    $offset = $size;
                    $keepaliveCounter = 0;
                }
            }
        }

        // Keepalive comment every 15 seconds (prevents proxy timeout)
        $keepaliveCounter++;
        if ($keepaliveCounter >= 15) {
            echo ": keepalive\n\n";
            ob_flush();
            flush();
            $keepaliveCounter = 0;
        }

        // Check if research process completed (output.log exists and has content)
        $outputFile = $sessionDir . '/output.log';
        if (file_exists($outputFile) && filesize($outputFile) > 0) {
            // Check if process is still running (if PID file exists)
            $pidFile = $sessionDir . '/.pid';
            if (file_exists($pidFile)) {
                $pid = (int) trim(file_get_contents($pidFile));
                if (!posix_kill($pid, 0)) {
                    // Process completed -- send done event and exit
                    echo "event: done\ndata: {}\n\n";
                    ob_flush();
                    flush();
                    break;
                }
            }
        }

        sleep(1); // Polling interval
    }
}
```

### Pattern 4: Session Markdown Transcript Generation

**What:** Write the complete research session to a structured markdown file with YAML frontmatter.

**When to use:** After Arbitrator::research() completes, in both research.php and repl.php.

```php
<?php
// Source: Based on existing research.php output format [VERIFIED: file inspection]

/**
 * Generate a complete session transcript.
 *
 * @param string $question    Original research question
 * @param array  $results     Agent results from Arbitrator::research()
 * @param array|null $debate  Debate result from Arbitrator::getDebateResult()
 * @param string $slug        Session slug (directory name)
 * @param int    $durationMs  Total duration in milliseconds
 * @return string             Full markdown content
 */
function generateTranscript(
    string $question,
    array $results,
    ?array $debate,
    string $slug,
    int $durationMs
): string {
    $date = date('c');
    $agentCount = count($results);
    $agentNames = implode(', ', array_keys($results));
    $winner = $debate['winner'] ?? 'N/A';
    $correlationId = '';
    foreach ($results as $result) {
        $correlationId = $result['correlation_id'] ?? '';
        break;
    }

    // YAML frontmatter
    $markdown = "---\n";
    $markdown .= "question: " . str_replace("\n", ' ', $question) . "\n";
    $markdown .= "date: {$date}\n";
    $markdown .= "agent_count: {$agentCount}\n";
    $markdown .= "agents: [{$agentNames}]\n";
    $markdown .= "winner: {$winner}\n";
    $markdown .= "correlation_id: {$correlationId}\n";
    $markdown .= "duration_ms: {$durationMs}\n";
    $markdown .= "---\n\n";

    // Summary section
    $markdown .= "## Summary\n\n";
    $markdown .= "**Question:** {$question}\n\n";
    $markdown .= "**Winner:** {$winner}\n\n";
    $markdown .= "**Duration:** " . round($durationMs / 1000, 1) . "s\n\n";

    if ($debate && isset($debate['score_table'])) {
        $markdown .= "### Score Table\n\n";
        $markdown .= "| Agent | Quality | Critique | Diversity | Total |\n";
        $markdown .= "|-------|---------|----------|-----------|-------|\n";
        foreach ($debate['score_table'] as $name => $scores) {
            $markdown .= sprintf(
                "| %s | %.1f/10 | %.2f | %.2f | %.3f |\n",
                $name,
                $scores['quality'],
                $scores['critique_avg'],
                $scores['diversity_bonus'],
                $scores['weighted_total']
            );
        }
        $markdown .= "\n";
    }

    // Raw Answers section
    $markdown .= "## Raw Answers\n\n";
    foreach ($results as $agentName => $result) {
        $markdown .= "### {$agentName}\n\n";
        $markdown .= "- **Model:** {$result['model']}\n";
        $markdown .= "- **Response time:** {$result['response_time_ms']}ms\n";
        $markdown .= "- **Tokens:** {$result['usage']['prompt_tokens']} in / {$result['usage']['completion_tokens']} out\n";
        if (!empty($result['error'])) {
            $markdown .= "- **Error:** {$result['error']}\n";
        }
        $markdown .= "\n" . $result['answer'] . "\n\n";
        $markdown .= "---\n\n";
    }

    // Debate section
    if ($debate) {
        $markdown .= "## Debate\n\n";
        $markdown .= "### Winner: {$debate['winner']}\n\n";
        $markdown .= $debate['narrative'] . "\n\n";
    }

    return $markdown;
}
```

### Anti-Patterns to Avoid
- **Buffered SSE output:** Forgetting to disable output buffering leads to SSE events being batched and sent in chunks instead of one-by-one. Always call `ob_implicit_flush(true)`, `ob_end_clean()`, and set `output_buffering=off`.
- **Blocking on exec():** Using `exec()` without `>` redirect to `/dev/null` causes the web request to block until the child process finishes. Always redirect stdout and stderr.
- **Race condition on session.log:** Multiple children writing simultaneously to the same log file. Use `FILE_APPEND | LOCK_EX` for atomic appends. Each write is a single JSON line under 1KB.
- **ANSI readline prompt breakage:** Passing ANSI codes directly to `readline()` without `\x01`/`\x02` wrapping causes broken line wrapping and cursor positioning. Always wrap color codes.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| UUID generation | Custom UUID algorithm | `bin2hex(random_bytes(16))` | PHP 7+ cryptographically secure. 32 hex chars = 128 bits of entropy. No deps needed for session IDs. |
| Markdown rendering in web | Custom markdown parser | Plaintext + `<pre>` + simple regex | Session files already contain markdown. For v1, displaying raw markdown in `<pre>` is acceptable. Add markdown parser only if HTML rendering is needed. |
| Session directory listing | Custom scandir filter | `glob('sessions/*/session.md')` | glob with pattern matching handles the check. stat() for sorting by mtime. |
| Log file rotation | Custom rotation logic | Simple file size check + rename | Session logs are per-session (one file per research run). No rotation needed. Only the main `logs/research.log` might need it, and that's already handled by per-session isolation. |

**Key insight:** Everything in this phase uses native PHP functions that are already available. The only "library" design decision is whether to create a `Formatter` class for ANSI output vs. using constants/functions. A small utility class (~50 lines) is the right scope.

## Runtime State Inventory

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Logs in `logs/research.log` -- unchanged. New `sessions/` directory will be fresh (nothing to migrate). | None -- Phase 5 creates new data, doesn't migrate existing. |
| Live service config | None -- no external services involved for session storage or REPL. | None. |
| OS-registered state | None -- Phase 5 doesn't install cron jobs, services, or system registrations. | None. |
| Secrets/env vars | API keys in `config/agents/*/config.json` and `config/arbitrator/config.json`. The web REPL does NOT need access to these -- research.php handles its own config loading after exec(). | None -- exec'd child inherits no env vars, research.php loads config from filesystem. |
| Build artifacts | Composer vendor directory exists. No changes needed -- no new Composer dependencies. | None. |

**Nothing found in category:** All categories explicitly checked above. Phase 5 is greenfield on the session/presentation layers.

## Common Pitfalls

### Pitfall 1: ANSI Codes in readline() Prompt Break Cursor Positioning
**What goes wrong:** Passing ANSI escape codes directly to `readline()` causes the prompt to appear truncated, line wrapping breaks, or the prompt is re-echoed when typing.
**Why it happens:** readline counts every byte in the prompt string toward its visible cursor position, but ANSI escape codes are zero-width. readline's internal width calculation goes wrong.
**How to avoid:** Wrap every ANSI escape sequence in `\x01` (SOH, start non-printing) and `\x02` (STX, end non-printing):
```php
$prompt = "\x01\e[36m\x02research> \x01\e[0m\x02"; // works correctly
```
**Warning signs:** After typing a long command, text overlaps on the terminal. Or pressing up-arrow shows truncated lines.

### Pitfall 2: SSE Output Buffering Causes Delayed Events
**What goes wrong:** SSE events don't arrive in real-time. The browser receives events in batches every N seconds instead of one-by-one.
**Why it happens:** PHP's output buffering (enabled by default) buffers all output until the script ends or the buffer fills. Nginx/apache may also buffer.
**How to avoid:** Call `ini_set('output_buffering', 'off')`, `ini_set('zlib.output_compression', false)`, `ob_implicit_flush(true)`, and flush all pending output buffers before entering the SSE loop. For Nginx, add `header('X-Accel-Buffering: no')`.
**Warning signs:** SSE events arrive in 4-8KB batches with noticeable gaps.

### Pitfall 3: exec() Blocks the Web Request Without Output Redirection
**What goes wrong:** POST to `/api/ask` hangs for the entire duration of the research process (potentially minutes).
**Why it happens:** `exec()` with `&` still blocks if stdout/stderr aren't redirected to a file or `/dev/null`. The shell waits for the pipe to close.
**How to avoid:** Always redirect: `exec("nohup php script.php > /path/to/output.log 2>&1 &")`. The `>` output_redirect is required even with `&`.
**Warning signs:** `/api/ask` endpoint takes the same time as the research pipeline itself.

### Pitfall 4: Concurrent Writes to session.log from Multiple Children
**What goes wrong:** Log lines from different children overwrite each other, producing corrupted JSON lines.
**Why it happens:** Multiple forked children call `file_put_contents` on the same file simultaneously. Without exclusive locking, writes can interleave.
**How to avoid:** Use `FILE_APPEND | LOCK_EX` in `file_put_contents` for log writes. Each line is a complete JSON object under 1KB, so the write completes quickly. LOCK_EX provides exclusive write access.
**Warning signs:** A line in session.log is truncated or contains JSON from two different events merged together.

### Pitfall 5: readline() Returns false on CTRL+D, Not Empty String
**What goes wrong:** Pressing CTRL+D causes `readline()` to return `false`, but code only checks for `$input === ''`.
**Why it happens:** CTRL+D sends EOF on Unix terminals. readline returns `false` on EOF, not an empty string.
**How to avoid:** Always check `$input === false` before processing:
```php
$input = readline($prompt);
if ($input === false) { // EOF (CTRL+D) -- exit cleanly
    echo "\n";
    break;
}
$input = trim($input);
if ($input === '') { continue; } // just pressed Enter
```
**Warning signs:** Pressing CTRL+D causes an error instead of exiting the REPL.

### Pitfall 6: readline_history() File Permissions in Different CWD
**What goes wrong:** History not persisted between REPL sessions. Or "Permission denied" errors.
**Why it happens:** `readline_read_history()` and `readline_write_history()` resolve relative paths from the current working directory, which may differ when called from cron, web, or different terminal sessions.
**How to avoid:** Always use absolute paths derived from `getenv('HOME')` for the history file:
```php
$historyFile = getenv('HOME') . '/.research-agents_history';
```
**Warning signs:** History file not created, or error messages about history file paths.

## Code Examples

Verified patterns from official sources:

### Session Slug Generation
```php
// Source: PHP manual preg_replace + mb_substr [VERIFIED: tested on runtime]
function slugFromQuestion(string $question): string
{
    $slug = preg_replace('/[^a-zA-Z0-9-]+/', '-', strtolower($question));
    $slug = trim($slug, '-');
    return rtrim(mb_substr($slug, 0, 60), '-');
}
// Input: "What are the latest advances in transformer architectures?"
// Output: "what-are-the-latest-advances-in-transformer-architectures"
```

### File Tailing Function (Read New Lines)
```php
// Source: PHP manual fseek + stream_get_contents [CITED: php.net/manual]
function tailFile(string $path, int $offset): array
{
    if (!file_exists($path)) {
        return ['lines' => [], 'offset' => 0];
    }

    $size = filesize($path);
    if ($size <= $offset) {
        return ['lines' => [], 'offset' => $size];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['lines' => [], 'offset' => $offset];
    }

    fseek($handle, $offset);
    $content = stream_get_contents($handle);
    fclose($handle);

    if ($content === false || $content === '') {
        return ['lines' => [], 'offset' => $size];
    }

    $lines = array_filter(explode("\n", $content), fn(string $l): bool => $l !== '');
    return ['lines' => array_values($lines), 'offset' => $size];
}
```

### Checking if exec'd Background Process is Running
```php
// Source: PHP posix_kill manual [CITED: php.net/posix_kill]
// Send signal 0 (no-op) to check process existence without side effects
function isProcessRunning(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    // posix_kill with signal 0 checks existence without sending a signal
    if (function_exists('posix_kill')) {
        $result = @posix_kill($pid, 0);
        if ($result === true) {
            return true;
        }
        // EPERM means process exists but owned by another user
        return posix_get_last_error() === SEEK_SET; // No -- use EPERM constant
        // Actually: posix_get_last_error() == 1 means EPERM
    }

    // Fallback: check /proc
    return file_exists("/proc/{$pid}");
}
```

### Spinner Display in CLI (Progress Indicator)
```php
// Source: Standard CLI spinner pattern
$spinnerFrames = ['|', '/', '-', '\\'];
$spinnerIndex = 0;

// During research polling loop:
while ($researchRunning) {
    echo "\r" . $spinnerFrames[$spinnerIndex % 4] . ' Researching...';
    $spinnerIndex++;

    // Read new progress lines
    $result = $progressLogger->readNewLines($offset);
    foreach ($result['lines'] as $line) {
        $event = json_decode($line, true);
        if ($event && isset($event['agent'], $event['event'])) {
            echo "\n  [" . $event['agent'] . '] ' . $event['event'];
        }
    }
    $offset = $result['offset'];

    usleep(200000); // 200ms for smooth animation
}
echo "\r\e[K"; // Clear spinner line when done
```

### ANSI Formatting Utility Class
```php
// Source: ANSI escape codes standard [CITED: ISO/IEC 6429]
namespace App\Output;

class Formatter
{
    // Foreground colors
    const GREEN  = "\e[32m";
    const CYAN   = "\e[36m";
    const YELLOW = "\e[33m";
    const RED    = "\e[31m";
    const WHITE  = "\e[37m";
    const RESET  = "\e[0m";

    // Text styles
    const BOLD   = "\e[1m";
    const DIM    = "\e[2m";

    // Wrapped variants for readline() prompt (non-printing markers)
    const RL_GREEN  = "\x01\e[32m\x02";
    const RL_CYAN   = "\x01\e[36m\x02";
    const RL_BOLD   = "\x01\e[1m\x02";
    const RL_YELLOW = "\x01\e[33m\x02";
    const RL_RESET  = "\x01\e[0m\x02";

    public static function section(string $title): string
    {
        return self::BOLD . self::CYAN . $title . self::RESET;
    }

    public static function winner(string $name): string
    {
        return self::BOLD . self::GREEN . $name . self::RESET;
    }

    public static function score(float $value): string
    {
        return match (true) {
            $value >= 8 => self::GREEN . number_format($value, 1) . self::RESET,
            $value >= 5 => self::YELLOW . number_format($value, 1) . self::RESET,
            default     => self::RED . number_format($value, 1) . self::RESET,
        };
    }

    public static function error(string $msg): string
    {
        return self::DIM . self::RED . $msg . self::RESET;
    }

    public static function agentName(string $name): string
    {
        // Assign consistent color based on name hash
        $colors = [self::GREEN, self::CYAN, self::YELLOW];
        $index = abs(crc32($name)) % count($colors);
        return $colors[$index] . $name . self::RESET;
    }

    public static function command(string $cmd): string
    {
        return self::BOLD . self::YELLOW . $cmd . self::RESET;
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Phases 1-4: research.php only | Phase 5: repl.php + research.php + public/index.php | Phase 5 | Multiple entry points for same pipeline. Shared output formatting. |
| Phases 1-4: no session persistence | Phase 5: full markdown transcripts | Phase 5 | All research traceable. Sessions browseable. Replayable. |
| Phases 1-4: children write only final result to temp file | Phase 5: children also write progress events to session log | Phase 5 | Real-time progress visible in both CLI and web interfaces. |
| Phase 3: research.php blocks CLI until complete | Phase 5: repl.php shows spinner + progress lines during research | Phase 5 | User-visible feedback during multi-minute research runs. |

**Deprecated/outdated:**
- **logging to `logs/research.log` only (Phase 1 approach):** Phase 5 introduces per-session logging (LOG-03) alongside the existing main log. Both coexist. The main log stays for system-wide debugging; session logs store per-session traceability.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | exec("nohup php ... &") works in PHP's built-in web server SAPI | Architecture Patterns -- Web REPL | Must be verified on the runtime system. If exec() is disabled in php.ini for the CLI server SAPI, fall back to proc_open() pattern. |
| A2 | posix_kill($pid, 0) is available for checking background process status | Code Examples | Fallback to `/proc/$pid` check works on Linux. On macOS, fallback to `exec("ps -p $pid")`. |
| A3 | FILE_APPEND | LOCK_EX is safe for concurrent writes from multiple forked children | Common Pitfalls -- Pitfall 4 | On NFS filesystems, LOCK_EX may be advisory (flock). For local filesystem (standard in development), LOCK_EX works correctly. |
| A4 | readline_read_history()/write_history() work with absolute paths | Code Examples -- Pitfall 6 | Verified in PHP docs: filename parameter accepts absolute paths since PHP 8.0 (nullable). |
| A5 | readline_completion_function() with empty matches does not segfault | Code Examples -- CLI REPL | Known bug in older PHP 5.3 with libedit. PHP 8.5.4 should not have this issue, but returning at least one empty string is a safe guard. |

## Open Questions

1. **How does the CLI detect that research is still running vs. completed?**
   - What we know: Children write results to temp files (Phase 3 pattern). Parent uses `pcntl_waitpid($pid, $status, WNOHANG)` in a poll loop.
   - What's unclear: In repl.php, the readline loop runs the research synchronously (create Arbitrator -> research() returns). Progress events are read from the session log during this time. The question is whether we fork inside repl.php and show a live progress display, or run research() synchronously and just show a spinner.
   - Recommendation: For v1, run research() synchronously in repl.php (same as research.php). The progress events are still written to session.log. The spinner runs in the fork-wait loop of the existing Arbitrator -- no parallel display in repl.php needed. The web REPL provides the asynchronous experience via exec().

2. **Should sessions/ directory be gitignored entirely, or just the contents?**
   - What we know: sessions/ is user-generated content. The .gitkeep file is needed to track the directory.
   - What's unclear: Git will track .gitkeep but not the session files if sessions/* is gitignored.
   - Recommendation: Add `sessions/*` and `sessions/*/**` to .gitignore, with a `!sessions/.gitkeep` exception rule.

3. **What happens to the session if the web server crashes mid-research?**
   - What we know: The exec'd child process (research.php) continues because nohup detached it.
   - What's unclear: The browser sees the SSE connection drop. When the server restarts, the session page at /session/{id} shows the saved transcript (if complete) or a "research in progress" page (if the process is still running).
   - Recommendation: /session/{id} should check if session.md exists for completed sessions, or if output.log is non-empty for in-progress ones. Show appropriate status.

4. **Should the progress event format include the agent's personality/role?**
   - What we know: D-11 defines event types but not the formatting of event data.
   - What's unclear: For CLI display, showing just "alpha: researching" is useful but knowing "alpha (researcher personality): searching web" would be more informative.
   - Recommendation: Include agent display name and model in the `data` field of progress events. The CLI and web can choose how much to render.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP (CLI) | All | Yes | 8.5.4 | -- |
| PHP readline | repl.php | Yes | Built-in | fgets(STDIN) -- no history or tab completion |
| PHP pcntl | pcntl_fork (research.php) | Yes | Built-in | Sequential fallback (already exists in Arbitrator) |
| PHP posix | posix_kill for PID checks | Yes | Built-in | /proc/$pid check or exec("ps") |
| PHP json | Session frontmatter, progress events | Yes | Built-in | -- |
| PHP mbstring | Slug derivation, string truncation | Yes | Built-in | -- |
| PHP exec() | Web REPL background process | Yes (CLI and web SAPI) | Built-in | proc_open() or pcntl_fork (CLI only) |
| PHP built-in web server | Web REPL | Yes | Built-in | -- |
| composer | PHPUnit testing | Yes | From vendor | -- |
| phpunit | Testing | Yes | ^12.0 | -- |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:** None.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^12.0 |
| Config file | phpunit.xml.dist (project root) |
| Quick run command | `php vendor/bin/phpunit tests/Session/ --no-coverage` |
| Full suite command | `php vendor/bin/phpunit` |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PERS-01 | Session UUID and slug generation | unit | `phpunit tests/Session/ManagerTest.php::testSlugGeneration` | Wave 0 |
| PERS-02 | Markdown transcript saved to file | unit | `phpunit tests/Session/ManagerTest.php::testTranscriptCreation` | Wave 0 |
| PERS-03 | Transcript contains all required sections | unit | `phpunit tests/Session/ManagerTest.php::testTranscriptStructure` | Wave 0 |
| PERS-04 | Files stored under sessions/ directory | unit | `phpunit tests/Session/ManagerTest.php::testSessionDirectory` | Wave 0 |
| LOG-03 | Per-session log file created | unit | `phpunit tests/Session/ProgressLoggerTest.php::testLogCreation` | Wave 0 |
| CLI-01 | readline loop accepts input and history | manual | -- | Manual test via `php repl.php` |
| CLI-02 | REPL runs research pipeline | manual | -- | Manual test |
| CLI-03 | Progress events displayed in CLI | manual | -- | Manual test |
| CLI-04 | Commands (help, replay, config) work | manual | -- | Manual test |
| CLI-05 | ANSI formatting visible | manual | -- | Manual test |
| WEB-01 | `php -S localhost:8080 -t public/` starts server | manual | -- | Manual test |
| WEB-02 | Web form accepts and POSTs question | unit (HTTP) | `phpunit tests/Web/FrontControllerTest.php::testFormRender` | Wave 0 |
| WEB-03 | SSE endpoint streams progress events | unit | `phpunit tests/Web/SseTest.php::testSseEventStream` | Wave 0 |
| WEB-04 | POST /api/ask returns redirect, exec's background process | manual | -- | Manual test |
| WEB-05 | Sessions list page shows past sessions | unit | `phpunit tests/Web/SessionsListTest.php::testSessionsList` | Wave 0 |

### Sampling Rate
- **Per task commit:** `php vendor/bin/phpunit tests/Session/ --no-coverage`
- **Per wave merge:** `php vendor/bin/phpunit`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Session/ManagerTest.php` -- covers PERS-01, PERS-02, PERS-03, PERS-04
- [ ] `tests/Session/ProgressLoggerTest.php` -- covers LOG-03
- [ ] `tests/Output/FormatterTest.php` -- covers ANSI formatting correctness
- [ ] `tests/Web/FrontControllerTest.php` -- covers WEB-02, WEB-05
- [ ] Framework install: `composer install` (already done -- vendor/ exists)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Single-user system. No user authentication. |
| V3 Session Management | no | No session tokens. Session IDs are question-derived slugs. |
| V4 Access Control | no | Single-user. Filesystem permissions control access to sessions/. |
| V5 Input Validation | yes | `escapeshellarg()` for exec'd question. mb_substr(0, 2000) cap. preg_replace for slug. |
| V6 Cryptography | no | No encryption of session files in v1. Filesystem permissions only. |
| V7 File Upload | no | No file upload functionality. |
| V8 Data Protection | partial | Sessions may contain API response content. Session files are local-only (not served publicly). |
| V12 Command Injection | yes | `escapeshellarg()` required on question before exec(). Use `--` argument separator. |

### Known Threat Patterns for {PHP script}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Command injection via question | Tampering | `escapeshellarg()` on the question string BEFORE passing to exec(). Never interpolate raw user input into shell commands. |
| Path traversal in session view | Tampering | `basename()` on session ID parameter. Reject paths containing `..` or `/`. |
| Output injection in terminal ANSI | Spoofing | Strip or encode ANSI escape codes from LLM-generated content displayed in terminal. Attacker-controlled LLM output could inject ANSI commands (though low risk in controlled environment). |
| Unauthenticated web access | Information disclosure | Web server binds to localhost only (`localhost:8080`), not 0.0.0.0. Browser access requires local machine access. |
| HTML injection in web display | Cross-site scripting | `htmlspecialchars()` when rendering any session data in HTML (question text, agent answers, LLM output). Default to ENT_QUOTES | ENT_SUBSTITUTE. |

### Critical Security Note for Web REPL

The `exec("nohup php ...")` pattern in `public/index.php` is the most sensitive security surface in this phase. Follow these rules:

1. **Always use `escapeshellarg()`** on the question before shell interpolation. Never build the command string via string concatenation of raw input.
2. **Do NOT run `php -S` on a public interface.** The built-in server is single-threaded and has no security middleware. Bind to `localhost` only: `php -S localhost:8080 -t public/`.
3. **Do NOT serve session files directly.** The built-in server's document root is `public/`. Session files in `sessions/` are outside the document root and are accessed only through the front controller.
4. **Validate session IDs** used in URLs with `basename()` to prevent path traversal: `$sessionId = basename($uri)`.

## Sources

### Primary (HIGH confidence)
- [PHP Manual: readline()](https://www.php.net/manual/en/function.readline.php) -- CLI REPL input, history, tab completion verified
- [PHP Manual: readline_completion_function()](https://www.php.net/manual/en/function.readline-completion-function.php) -- Tab completion callback pattern verified
- [PHP Manual: readline_read_history()](https://www.php.net/manual/en/function.readline-read-history.php) -- History persistence across sessions verified
- [PHP Manual: readline_write_history()](https://www.php.net/manual/en/function.readline-write-history.php) -- History save on exit verified
- [PHP Manual: exec()](https://www.php.net/manual/en/function.exec.php) -- Background process via `&` redirect pattern verified
- [PHP Manual: posix_kill()](https://www.php.net/manual/en/function.posix-kill.php) -- Signal 0 process existence check verified
- [PHP Manual: file_get_contents](https://www.php.net/manual/en/function.file-get-contents.php) -- LOCK_EX atomic append verified
- [PHP Manual: fopen/fseek](https://www.php.net/manual/en/function.fseek.php) -- File tailing pattern verified
- [PHP Manual: connection_aborted](https://www.php.net/manual/en/function.connection-aborted.php) -- SSE disconnect detection verified
- [PHP Manual: ob_implicit_flush](https://www.php.net/manual/en/function.ob-implicit-flush.php) -- Output buffer control for SSE verified

### Secondary (MEDIUM confidence)
- WebSearch: `\x01`/`\x02` ANSI wrapping in readline prompt -- verified against PHP notes and GNU Readline documentation
- WebSearch: exec("nohup ... &") web SAPI behavior -- multiple community sources agree on pattern
- WebSearch: proc_open() detach pattern as fallback -- documented in PHP manual notes

### Tertiary (LOW confidence)
- None -- all findings verified against official PHP manual or runtime testing.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - All built-in PHP functions verified on runtime (PHP 8.5.4).
- Architecture: HIGH - Patterns derived from existing codebase (Phase 3/4) and official PHP documentation.
- Pitfalls: HIGH - Each pitfall verified against PHP manual behavior and common ecosystem knowledge.

**Research date:** 2026-06-13
**Valid until:** Stable -- PHP readline and exec() behavior unchanged across minor versions.
