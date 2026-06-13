---
phase: 05-storage-presentation
plan: 02
wave: 2
status: complete
---

# Wave 2 Summary: CLI REPL and ANSI Formatter

## Files Created

| File | Description |
|------|-------------|
| `src/Output/Formatter.php` | ANSI color constants and helper methods for CLI output |
| `repl.php` | Interactive CLI REPL entry point with readline loop |

## Formatter class (`src/Output/Formatter.php`)

**Namespace:** `App\Output`

**Constants:**
- Foreground: GREEN, CYAN, YELLOW, RED, WHITE, RESET
- Text styles: BOLD, DIM
- Readline-safe: RL_CYAN, RL_BOLD, RL_RESET (\x01/\x02 wrapped)

**Static methods:**
- `section(string): string` -- Bold Cyan header
- `winner(string): string` -- Bold Green winner
- `score(float): string` -- Colored by threshold (>=8 green, >=5 yellow, <5 red)
- `error(string): string` -- Dim Red error
- `agentName(string): string` -- Name colored by crc32 hash (green/cyan/yellow cycle)
- `command(string): string` -- Bold Yellow command
- `separator(): string` -- Dim 72-dash line
- `prompt(): string` -- Readline-safe bold cyan "research> " prompt
- `sessionAge(string): string` -- Colored by recency (green <1h, yellow <1d, dim older)
- `statusLine(string, string): string` -- Progress line with colored agent name

## REPL (`repl.php`)

**Features implemented:**
- Interactive readline loop with persistent history (~/.research-agents_history, max 1000 entries)
- Tab completion for all 8 commands
- SIGINT handler (Ctrl+C shows "Interrupted." in yellow)
- Startup message showing agent count from AgentManager
- Full command set: /help, /replay, /config, /agents, /last, /history, /clear, /exit

**Research execution (runResearch):**
- Forks a child process to run the full research pipeline (Arbitrator::research)
- Parent displays spinner animation (|/-\\) with 200ms interval
- Parent polls session.log via ProgressLogger::readNewLines() for real-time progress events
- Child writes result to temp file (sys_get_temp_dir()) for parent to collect
- Supports synchronous fallback when pcntl_fork fails

**Output rendering (renderDebateOutput):**
- Replicates research.php output format with ANSI colors
- Score table with colored agent names, quality scores (green/yellow/red)
- Winner section with bold green "WINNER:" label
- Judge narrative with wordwrap
- Error summary in dim red
- ANSI strip on LLM output (T-05-02 mitigation)

**Session replay (replaySession):**
- Loads session.md from sessions/{slug}/
- Frontmatter ignored
- ## headers rendered in Bold Cyan
- **Winner:** rendered in Bold Green
- Error lines rendered in Dim Red
- Path traversal prevention (T-05-03): rejects slugs with / or ..

**Session save (saveSession):**
- Wraps SessionManager::createSession() with try/catch
- Saves to sessions/ directory
- Errors logged but do not crash the REPL

## Verification

| Check | Result |
|-------|--------|
| `php -l src/Output/Formatter.php` | No syntax errors |
| `php -l repl.php` | No syntax errors |
| `php vendor/bin/phpunit tests/Output/FormatterTest.php --no-coverage` | OK (1 test, 1 assertion) |

## Threat Mitigations

| Threat | Mitigation | Status |
|--------|------------|--------|
| T-05-02 (ANSI injection) | `preg_replace('/\e\[[\d;]*[a-zA-Z]/', '', $text)` on LLM output in renderDebateOutput | Implemented |
| T-05-03 (Path traversal) | Slug validation: reject / or .., basename() guard | Implemented |
| T-05-05 (Readline prompt breakage) | \x01/\x02 wrapping in Formatter::prompt() | Implemented |

## Design Decisions

- **AgentManager constructor**: repl.php passes full 4-arg constructor (agentsBaseDir, Loader, logFile) rather than the simplified 1-arg shown in the plan, because the actual AgentManager class requires 3 mandatory parameters.
- **match() and break**: Used `if (in_array(...)) { break; }` before match() for /exit and /quit since PHP match arms cannot contain break statements.
- **Score table alignment**: Agent name text is padded to 18 chars before ANSI coloring; quality text is right-aligned to 6 visual chars before coloring. This preserves column alignment despite invisible ANSI bytes.
