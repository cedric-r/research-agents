# Phase 5: Storage & Presentation - Context

**Gathered:** 2026-06-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver session persistence (full markdown transcripts saved to `sessions/`), interactive CLI REPL with readline loop and real-time progress display, and web-based REPL via PHP built-in server with SSE streaming — wrapping the research pipeline (Phases 1-4) with rich user-facing interfaces.

Builds on Phase 4's debate protocol (score table, winner narrative, error summary) and Phase 3's Arbitrator (pcntl_fork parallelism, temp file IPC). The core research pipeline is complete — this phase makes it usable, traceable, and accessible from both terminal and browser.

</domain>

<decisions>
## Implementation Decisions

### CLI REPL Architecture (CLI-01, CLI-02, CLI-04)
- **D-01:** **Both entry points** — `research.php` stays as the scriptable one-shot CLI (existing API contract). New `repl.php` provides an interactive readline loop that prompts, runs research, displays output, then loops. Shared output formatting utilities.
- **D-02:** **Full command set** — `/help`, `/replay <id>`, `/config`, `/agents`, `/last`, `/history`, `/clear`, `/exit`. All implemented inside the readline loop.
- **D-03:** **Full ANSI formatting** (CLI-05) — section headers (bold/cyan), winner (bold green), scores colored (high=green, mid=yellow, low=red), errors (dim/red), agent names colored, command names highlighted, session list colored by recency.
- **D-04:** **Full detail output** — REPL shows the complete debate output (score table, winner answer, judge narrative, error summary) after each research run, same format as current `research.php`.

### Session Transcript Format (PERS-01 through PERS-04)
- **D-05:** **Full everything** — Transcript saves every agent's complete raw answer, quality scores, structured critique, diversity bonuses, score table, winner answer, judge narrative, tool call results, and error summary. Maximum traceability.
- **D-06:** **Timestamp + slug filename with full frontmatter** — Pattern: `sessions/2026-06-13_attention-models.md`. Frontmatter includes: question, ISO date, agent count with agent names, winner, correlation ID, duration, model info, score table summary.
- **D-07:** **Separate `.log` file alongside transcript** — Per-session log file (same Logger channel format) in the same directory as the transcript. Pattern: `sessions/{slug}/session.md` + `sessions/{slug}/session.log`. LOG-03 requirement.
- **D-08:** **Nested markdown structure** — Frontmatter (metadata) → ## Summary (winner, scores table) → ## Raw Answers (each agent's full output with tool results) → ## Debate (critiques, judge reasoning) → ## Errors.

### Real-Time Progress Display (CLI-03)
- **D-09:** **Log file tailing / watch pipe** — Each child process writes timestamped progress events to a shared log. The CLI display reads from the log (tail-like), and the web SSE endpoint does the same. Single mechanism for both interfaces.
- **D-10:** **Spinner + log lines** — CLI shows a spinner/indicator (`⏳ Researching... (2/3 agents)`) with status log lines appearing below as agents emit events. Not a full ANSI table, but dynamic enough to show progress.
- **D-11:** **All tool call events** — Children log progress events for every major step: started, researching (LLM call), web_search, paper_search, score_evaluated, critique_r2_started, critique_completed, completed/timed_out/failed.

### Web REPL Architecture (WEB-01 through WEB-05)
- **D-12:** **Detached PHP process via `exec()`** — POST to `/api/ask` generates a session ID, saves the question, calls `exec("php research.php 'question' > sessions/{slug}/output.log 2>&1 &")`, and returns the session ID immediately. SSE endpoint polls the session's log file for new lines. No pcntl_fork in web request context — research.php handles its own forking internally.
- **D-13:** **Multi-page frontend** — `/` (form to submit question), `/session/{id}` (results page with SSE streaming), `/sessions` (list of past sessions). All server-rendered HTML. No JS framework, no build step.
- **D-14:** **Raw log lines as SSE events** — SSE stream sends each new log line as an `event: progress` with the raw log text. Browser appends to a results log div. Simplest possible SSE implementation.
- **D-15:** **public/index.php + `php -S`** — Serve from `public/` directory: `php -S localhost:8080 -t public/`. Front controller at `public/index.php` handles all routes. Separates web-facing files from source code.

### Claude's Discretion
- Exact readline command parsing implementation in repl.php
- ANSI color utility class/method design
- Progress event format in the shared log (how children write events, who manages the log)
- SSE polling interval and keepalive strategy
- Session list sorting and display format
- Error handling for `exec()` failures (process never started, session orphaned)
- `sessions/` directory creation and permission handling

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Context
- `.planning/PROJECT.md` — Project overview, core value, constraints
- `.planning/REQUIREMENTS.md` — Full v1 requirements with traceability (PERS-01..04, LOG-03, CLI-01..05, WEB-01..05)
- `.planning/ROADMAP.md` — Phase structure, dependencies, success criteria
- `.planning/STATE.md` — Current project state, active risk register

### Prior Phase Decisions (inherited)
- `.planning/phases/04-debate-system-echo-chamber-prevention/04-CONTEXT.md` — D-01..D-05 (quality scoring, critique format, diversity weighting, two-stage selection, reasoning trace output format)
- `.planning/phases/03-orchestration-pipeline/03-CONTEXT.md` — D-01..D-16 (Arbitrator design, pcntl_fork, temp file IPC, 4-layer timeout architecture)
- `.planning/phases/02-agent-runtime-tool-integration/02-CONTEXT.md` — D-01..D-12 (AgentManager discovery, tool registry, HttpHelper timeouts)
- `.planning/phases/01-foundation-single-agent-baseline/01-CONTEXT.md` — D-01..D-15 (config format, LlmClient, SOUL.md, logging)

### Existing Implementation
- `research.php` — Current CLI entry point. Scriptable one-shot (D-01). Output format with score table + winner + narrative reused in transcript.
- `src/Arbitrator/Arbitrator.php` — Orchestrator with pcntl_fork. Needs progress event logging in children (D-09, D-11). `getDebateResult()` returns debate output for transcript.
- `src/Agent/ResearchAgent.php` — Per-agent research lifecycle. Needs to emit progress events during execution.
- `src/Log/Logger.php` — Logger with channels and correlation ID. Template for per-session log file (D-07).
- `src/bootstrap.php` — Autoloader. All new entry points (repl.php, public/index.php) require this.
- `src/Agent/AgentManager.php` — Agent discovery. Used by repl.php for /config and /agents commands.
- `src/Config/Loader.php` — Config validation. Used for /config command.
- `config/arbitrator/config.json` — Arbitrator config with scoring/judge settings. Session config references.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Existing `research.php` output format** (score table + winner + narrative + error summary) — embedded directly into session transcript and REPL output. No redesign needed.
- **Logger with channels + correlation ID** — Template for per-session log files. Same format, different file path per session.
- **Temp file IPC pattern** (Phase 3/4) — Extends naturally to progress status files (D-09). Same sys_get_temp_dir()+JSON pattern.
- **Arbitrator with pcntl_fork** — Children can emit progress events via shared log file before their final result write.
- **Config Loader** — Session save/load uses same config validation patterns (sessions directory config, naming options).
- **ResearchAgent::research($deadline)** — Add progress event writes alongside existing deadline checking.

### Established Patterns
- Vanilla PHP, zero external dependencies — readline and SSE are built-in, no npm/composer for UI
- Config per directory under `config/` — session config may live in its own directory or as a session section in arbitrator config
- File-based storage (logs, temp files, config) — sessions continue this pattern with `sessions/` directory
- Process isolation via pcntl_fork — progress events extend the child→parent communication beyond final result

### Integration Points
- `repl.php` → creates Arbitrator → calls `$arbitrator->research()` — same pattern as research.php but in readline loop
- `research.php` → output redirected to session file when called from web exec (D-12)
- Logger → per-session log file alongside transcript (D-07), separate from main `logs/research.log`
- Arbitrator fork children → write progress events to session log (D-09, D-11) in addition to temp file IPC
- `public/index.php` routes: `/` (form), `/api/ask` (POST + exec), `/api/stream/{id}` (SSE), `/sessions` (list), `/session/{id}` (display transcript)
- Sessions directory: `sessions/` at project root — needs creation and `.gitkeep` or gitignore rules

### Creative Options
- **REPL prompt format:** `research> ` with color, showing agent count, session slug
- **SSE keepalive:** send `: heartbeat` comment lines every 15s to prevent browser timeout
- **Session file .gitignore:** `sessions/*.md` and `sessions/*.log` should be gitignored (user-generated content)

</code_context>

<specifics>
## Specific Ideas

- REPL prompt: `research> ` in bold cyan, showing no. of configured agents on startup: `"ResearchAgents REPL — 3 agents configured, ready."`
- Session filename slug: derive from first 60 chars of question, lowercase, spaces→hyphens, strip non-alphanumeric. Example: `2026-06-13_what-are-latest-advances-in-transformer-architectures.md`
- Session metadata in frontmatter (YAML-style between `---` delimiters): question, date, agents, winner, correlation_id, duration_ms, model_info
- Progress log format: `[timestamp] [AGENT] [PROGRESS] [agent_name] [correlation_id] step_name optional_data` — structured enough for SSE parsing, readable enough for log inspection
- SSE approach: PHP script polls `sessions/{slug}/session.log` from end, sends new lines as `data:` events. `sleep(1)` loop with flush(). Browser EventSource reads and appends to \<pre\> element.
- Web `/sessions` page: read `sessions/` directory, parse frontmatter from each `.md` file, display as a table with date, question (truncated), agent count, winner. Sort newest first.
- CLI session replay (`/replay <slug>`): load and display the session markdown file, rendered with ANSI. Use `cat` with `less`-like behavior or render headers in bold.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 5-Storage & Presentation*
*Context gathered: 2026-06-13*
