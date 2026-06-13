# Phase 5: Storage & Presentation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-13
**Phase:** 5-Storage & Presentation
**Areas discussed:** CLI REPL architecture, Session transcript format, Real-time progress display, Web REPL architecture

---

## CLI REPL Architecture

**Turn 1: REPL vs enhanced one-shot vs both**

| Option | Description | Selected |
|--------|-------------|----------|
| Interactive REPL (repl.php) | php repl.php → prompt loop with history, commands. Loops after answering. | |
| Enhanced one-shot CLI | Keep php research.php "question" with ANSI+session save. Simple, scriptable. | |
| Both entry points | research.php stays scriptable API. repl.php wraps it with readline loop. | ✓ |
| You decide | Claude picks based on v1 needs. | |

**User's choice:** Both entry points

**Turn 2: Command set**

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal: /help, /replay, /config | Keep it tight for v1. | |
| Add /agents and /last | /agents lists agents, /last re-runs last question. | |
| Full set | All of above + /history, /clear, /exit. | ✓ |
| You decide | Claude picks right set. | |

**User's choice:** Full set — /help, /replay, /config, /agents, /last, /history, /clear, /exit

**Turn 3: ANSI formatting scope**

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal: winner + score table only | Winner bold green, score header highlighted. Plain text for rest. | |
| Moderate: full debate output | Section headers bold/cyan, winner bold green, scores colored, errors red. | |
| Full: everything styled | All of above + progress colors, command highlighting, session list colored. | ✓ |
| You decide | Claude picks sensible defaults. | |

**User's choice:** Full ANSI formatting — everything styled

**Turn 4: Output layout**

| Option | Description | Selected |
|--------|-------------|----------|
| Full detail always | Complete score table, winner answer, judge narrative, error summary. | ✓ |
| Compact summary + /detail | Brief summary by default, /detail for full output. | |
| You decide | Claude picks. | |

**User's choice:** Full detail always

---

## Session Transcript Format

**Turn 1: Transcript detail level**

| Option | Description | Selected |
|--------|-------------|----------|
| Full everything | Every agent's complete raw answer, scores, critique, tool results. | ✓ |
| Debate output + winner only | Score table, winner, judge narrative, errors. Skips losing agents' full answers. | |
| Both: full in file, compact in CLI | Transcript saves everything. CLI stays compact. | |
| You decide | Claude picks balanced level. | |

**User's choice:** Full everything — maximum traceability

**Turn 2: File naming and frontmatter**

| Option | Description | Selected |
|--------|-------------|----------|
| UUID filename, basic frontmatter | sessions/{uuid}.md with title, date, agent count, winner, correlation ID. | |
| Timestamp + slug filename, full frontmatter | sessions/2026-06-13_attention-models.md with all metadata. Human-readable. | ✓ |
| UUID filename, full frontmatter | UUID safe uniqueness. Full frontmatter metadata. | |
| You decide | Claude picks sensible scheme. | |

**User's choice:** Timestamp + slug filename with full frontmatter

**Turn 3: Per-session logging (LOG-03)**

| Option | Description | Selected |
|--------|-------------|----------|
| Log integrated into transcript | ## Log section in the session markdown file. | |
| Separate .log file alongside transcript | sessions/{slug}/session.md + session.log | ✓ |
| Log file in sessions/ dir, named same | sessions/{slug}.md + sessions/{slug}.log in same dir. | |
| You decide | Claude picks. | |

**User's choice:** Separate .log file alongside transcript

**Turn 4: Transcript structure**

| Option | Description | Selected |
|--------|-------------|----------|
| Flat: question → answers → debate → winner | Single linear flow. Simple. | |
| Nested: metadata → debate summary → raw data | Frontmatter + summary + raw answers + debate + errors. | ✓ |
| You decide | Claude picks clear structure. | |

**User's choice:** Nested structure — frontmatter → summary → raw answers → debate → errors

---

## Real-Time Progress Display

**Turn 1: Progress mechanism**

| Option | Description | Selected |
|--------|-------------|----------|
| Status files polled from waitpid loop | Children write status.json on key steps. Parent reads in 100ms poll. | |
| Log file tailing / watch pipe | Children write log lines. Display process tails. Works for CLI + Web. | ✓ |
| Signal-based (SIGUSR1) | Lowest latency but trickier with multiple children. | |
| You decide | Claude picks simplest approach. | |

**User's choice:** Log file tailing / watch pipe

**Turn 2: CLI display format**

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal: one-line per completed agent | Prints "Agent X: completed" as each finishes. | |
| Status table: live-updating grid | ANSI table updating in-place. Shows status per agent. | |
| Spinner + log lines | Spinner with "Researching... (2/3)" and status lines below. | ✓ |
| You decide | Claude picks balanced UX. | |

**User's choice:** Spinner + log lines

**Turn 3: Progress event types**

| Option | Description | Selected |
|--------|-------------|----------|
| Entry + exit only | Child logs started + completed/timed out/failed. | |
| Major step events | started, researching, web_search, paper_search, critique, completed. | |
| All tool calls | Everything in major steps + individual tool calls. | ✓ |
| You decide | Claude picks useful granularity. | |

**User's choice:** All tool call events

---

## Web REPL Architecture

**Turn 1: Background process strategy**

| Option | Description | Selected |
|--------|-------------|----------|
| Detached PHP process via exec | POST → exec("php research.php ... &"). SSE polls session files. | ✓ |
| pcntl_fork in request handler | POST forks research as child. Blocks server during fork setup. | |
| Separate background worker script | Persistent daemon watching job queue. More complex. | |
| You decide | Claude picks simplest approach. | |

**User's choice:** Detached PHP process via exec()

**Turn 2: Web frontend design**

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal HTML + CSS | Single page with form + results area. SSE pushes to div. | |
| Multi-page: form + results + history | / form, /session/{id} results, /sessions list. SSE on results page. | ✓ |
| You decide | Claude picks. | |

**User's choice:** Multi-page — form on /, results on /session/{id}, history on /sessions

**Turn 3: SSE event format**

| Option | Description | Selected |
|--------|-------------|----------|
| Raw log lines as text/event-stream | Each log line becomes SSE event:progress with raw text. | ✓ |
| Structured JSON events | Typed events (agent_started, completed, error) with JSON data. | |
| You decide | Claude picks. | |

**User's choice:** Raw log lines streamed as SSE events

**Turn 4: Web server setup**

| Option | Description | Selected |
|--------|-------------|----------|
| server.php router + php -S | Single router file handles all routes. | |
| public/index.php + php -S | Serve from public/ directory. Front controller. | ✓ |
| You decide | Claude picks clean setup. | |

**User's choice:** public/index.php + php -S -t public/

---

## Claude's Discretion

- Exact readline command parsing implementation in repl.php
- ANSI color utility class/method design
- Progress event format in the shared log
- SSE polling interval and keepalive strategy
- Session list sorting and display format
- Error handling for exec() failures
- sessions/ directory creation and permission handling

## Deferred Ideas

None — discussion stayed within phase scope.
