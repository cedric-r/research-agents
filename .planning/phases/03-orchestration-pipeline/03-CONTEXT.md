# Phase 3: Orchestration Pipeline - Context

**Gathered:** 2026-06-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver the multi-agent orchestration layer — an Arbitrator class that runs all configured agents in parallel (Round 1 independent answers), with per-step timeout enforcement and the 4-layer timeout architecture that prevents any single hung agent from blocking the session. No debate, selection, or quality evaluation — those are Phase 4.

</domain>

<decisions>
## Implementation Decisions

### Arbitrator Class Design
- **D-01:** New `src/Arbitrator/` namespace for the Arbitrator class. Clean separation from AgentManager and ResearchAgent.
- **D-02:** Arbitrator replaces the orchestration loop currently in `AgentManager::research()`. Uses AgentManager **only** for agent discovery (`getAgentConfigs()`). Spawns and manages agent execution itself.
- **D-03:** `research.php` creates the Arbitrator instance and calls `$arbitrator->research()`. Minimal CLI change — keeps the same entry point.
- **D-04:** Arbitrator returns array of agent results in the same shape as current `AgentManager::research()` — `array<string, array{answer, model, response_time_ms, usage, correlation_id}>`. research.php output formatting code stays compatible.

### Parallel Execution
- **D-05:** `pcntl_fork` for process-level parallel execution. Each agent runs in its own forked child. Parent can SIGTERM on timeout. Full error isolation.
- **D-06:** Configurable max concurrent agents (default 5). Configured in Arbitrator configuration. If more agents exist than the limit, remaining agents wait in a queue.
- **D-07:** IPC via temp files. Each child writes its result to `sys_get_temp_dir()/agent_{name}_{correlationId}.json`. Parent reads after child exits or is killed. Works even on SIGTERM — temp file persists.
- **D-08:** Simple `pcntl_waitpid()` loop for parent tracking child completion and status.

### Timeout & Partial Answers
- **D-09:** Per-step timeout configurable per-agent in `config.json`. Default: 60 seconds.
- **D-10:** Graceful shutdown on timeout. Child process registers a SIGTERM handler — catches the signal, writes a `PARTIAL_ANSWER` marker with whatever result it has buffered to the temp file, then exits.
- **D-11:** If temp file is empty or missing on timeout, display: `"[Agent name] timed out — no partial answer"`.
- **D-12:** `pcntl_alarm` + `SIGALRM` for timeout enforcement from parent process.

### 4-Layer Timeout Architecture (ORCH-10)
- **D-13:** Layer 1 (PHP max_execution_time) — **Implicit.** Rely on existing PHP defaults. Document but don't add code.
- **D-14:** Layer 2 (HTTP socket timeout) — **Already implemented** in `HttpHelper` (`CURLOPT_TIMEOUT=60s`, `CURLOPT_CONNECTTIMEOUT=10s`). No changes needed.
- **D-15:** Layer 3 (stream-idle watchdog) — **Skipped for v1.** No streaming in current LLM implementation (single `curl_exec` response). Defer to v2.
- **D-16:** Layer 4 (cooperative agent-step deadline) — **Simple deadline check** in `ResearchAgent::research()`. Before each major step (tool call, LLM call), check elapsed time. If less than 5s remain before agent timeout, skip remaining steps and return what's ready.

### Claude's Discretion
- Exact temp file naming convention and cleanup strategy
- Signal handler registration details in child process
- Deadline check implementation specifics in ResearchAgent
- Arbitrator constructor signature and config schema details

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Context
- `.planning/PROJECT.md` — Project overview, core value, constraints
- `.planning/REQUIREMENTS.md` — Full v1 requirements with traceability
- `.planning/ROADMAP.md` — Phase structure, dependencies, success criteria

### Phase 3 Requirements
- `.planning/REQUIREMENTS.md` § Orchestration — ORCH-01 through ORCH-04 (Round 1 pipeline)
- `.planning/REQUIREMENTS.md` § Orchestration — ORCH-10 (4-layer timeout architecture)

### Prior Phase Decisions (inherited)
- `.planning/phases/01-foundation-single-agent-baseline/01-CONTEXT.md` — D-04 (single LlmClient with provider adapter), D-07 (flat CLI entry point), D-11/D-15 (logging + output format)
- `.planning/phases/02-agent-runtime-tool-integration/02-CONTEXT.md` — D-01 (AgentManager discovery by scanning), D-02 (fresh ResearchAgent per call), D-12 (centralized HttpHelper timeouts)

### Existing Implementation
- `src/Agent/AgentManager.php` — Agent discovery via `getAgentConfigs()`. Currently has sequential orchestration loop that Arbitrator will replace.
- `src/Agent/ResearchAgent.php` — Single-agent research lifecycle. Will be forked per child in parallel execution.
- `src/Http/HttpHelper.php` — Centralized HTTP with CURLOPT_TIMEOUT=60s, CURLOPT_CONNECTTIMEOUT=10s (Layer 2 of 4-layer arch).
- `research.php` — Current CLI entry point. Will instantiate Arbitrator instead of calling AgentManager::research() directly.

### State
- `.planning/STATE.md` — Current project state, Phase 2 completed, active risk register

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **AgentManager::getAgentConfigs()** — Agent discovery by scanning `config/agents/*/config.json`. Arbitrator calls this for agent list — no duplicate discovery logic.
- **HttpHelper** — Centralized HTTP with 60s socket timeout. Layer 2 already in place. No changes needed.
- **ResearchAgent** — Per-agent research lifecycle (config loading, SOUL.md, LLM call, tool integration). Each forked child creates its own instance.
- **research.php** — CLI entry point. Minimal change: swap `$agentManager->research()` for `$arbitrator->research()`.

### Established Patterns
- Vanilla PHP, no external dependencies — pcntl_fork for parallelism, curl for HTTP, files for storage
- Config per directory under `config/` — Arbitrator gets its own config directory
- Error isolation via process boundaries (Phase 2 established per-agent error handling in AgentManager)

### Integration Points
- `research.php` → creates Arbitrator → calls `$arbitrator->research()`
- Arbitrator → AgentManager::getAgentConfigs() (discovery only)
- Arbitrator → pcntl_fork child → ResearchAgent (per-agent instance)
- Child → temp file IPC → parent reads results
- Layers 2-4 timeout enforcement (L2: HttpHelper, L4: ResearchAgent deadline check)

</code_context>

<specifics>
## Specific Ideas

- Temp file naming: `agent_{agentName}_{correlationId}.json` in sys_get_temp_dir()
- Result JSON schema in temp file: `{status: "completed"|"partial"|"killed", answer: string, model: string, response_time_ms: int, usage: array, correlation_id: string, error?: string}`
- Deadline check: compute `$deadline = microtime(true) + $timeout - 5` at start. Check `microtime(true) > $deadline` before each major step.
- Arbitrator config lives in `config/arbitrator/config.json` — mirroring agent config pattern.

</specifics>

<deferred>
## Deferred Ideas

- **Stream-idle watchdog (Layer 3):** Would be needed if streaming LLM responses are added. Deferred to v2.
- **Parallel CLI progress display:** Showing per-agent completion as it happens (instead of batching at end). Currently output stays sequential-at-end. Defer to Phase 5 when CLI REPL gets real-time progress display (CLI-03).
- **Arbitrator evaluating Round 1 quality:** That's Phase 4 (ORCH-05, ORCH-06).

</deferred>

---

*Phase: 3-Orchestration Pipeline*
*Context gathered: 2026-06-13*
