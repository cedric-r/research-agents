# Phase 3: Orchestration Pipeline - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-13
**Phase:** 3-orchestration-pipeline
**Areas discussed:** Arbitrator Design, Parallel Execution, Timeout + Partial Answers, 4-Layer Timeout Architecture

---

## Arbitrator Design

| Option | Description | Selected |
|--------|-------------|----------|
| `src/Arbitrator/` namespace | New namespace keeps separation of concerns clean. Arbitrator orchestrates, Agent manages discovery. | ✓ |
| `src/Agent/Arbitrator` | Keep all agent-related classes together. | |
| You decide | Planner/researcher picks based on codebase exploration. | |

**User's choice:** `src/Arbitrator/` namespace
**Notes:** Clean separation preferred. Arbitrator orchestrates, AgentManager handles discovery only.

| Option | Description | Selected |
|--------|-------------|----------|
| Arbitrator replaces orchestration | Arbitrator owns the orchestration loop directly. Uses AgentManager just for discovery (getAgentConfigs()), spawns agents itself. | ✓ |
| Arbitrator wraps AgentManager | Arbitrator calls AgentManager::research() which already handles agent lifecycle. | |

**User's choice:** Arbitrator replaces orchestration
**Notes:** Cleaner for parallel execution + timeout control. AgentManager keeps discovery, Arbitrator owns execution.

| Option | Description | Selected |
|--------|-------------|----------|
| research.php calls Arbitrator | research.php creates Arbitrator (which gets AgentManager via DI), calls $arbitrator->research(). | ✓ |
| New bin/arbitrate.php entry | Separate CLI entry point for multi-agent orchestration. | |

**User's choice:** research.php calls Arbitrator
**Notes:** Minimal CLI change. Keeps single entry point.

| Option | Description | Selected |
|--------|-------------|----------|
| Array of agent answers | Same return type as current AgentManager::research(). research.php output code stays unchanged. | ✓ |
| Structured ArbitratorResult | Dedicated result class with agent results, timing stats, per-agent status. | |

**User's choice:** Array of agent answers
**Notes:** Keeps output formatting code compatible. Simpler MVP.

---

## Parallel Execution

| Option | Description | Selected |
|--------|-------------|----------|
| pcntl_fork | Process-level isolation. Parent can SIGTERM on timeout. Full error isolation. Recommended in CLAUDE.md. | ✓ |
| curl_multi_exec | HTTP concurrency in single process. Less isolation but simpler IPC. | |
| Sequential | Current approach. No parallelism but simplest. | |

**User's choice:** pcntl_fork
**Notes:** Follows recommended stack. Critical for SIGTERM kill capability and full error isolation.

| Option | Description | Selected |
|--------|-------------|----------|
| Unlimited (fork all) | Fork one child per configured agent. | |
| Configurable max (default 5) | Arbitrator spawns up to N children at a time, queues rest. | ✓ |

**User's choice:** Configurable max (default 5)
**Notes:** Configurable in arbitrator config. Safe default for typical systems.

| Option | Description | Selected |
|--------|-------------|----------|
| Temp files | Each child writes result to temp file. Parent reads after child exits or is killed. Works even on SIGTERM. | ✓ |
| Pipes (proc_open) | Use proc_open for pipe-based stdout capture. More control but heavier. | |

**User's choice:** Temp files
**Notes:** Simplest IPC for pcntl_fork. Works even when child is killed (file persists on disk).

| Option | Description | Selected |
|--------|-------------|----------|
| Simple waitpid loop | Parent calls pcntl_waitpid() in loop, checks each child status. | ✓ |
| Signal handler + polling | SIGCHLD handler for async notification + polling loop. | |

**User's choice:** Simple waitpid loop
**Notes:** Standard Unix process management. Good enough for v1.

---

## Timeout + Partial Answers

| Option | Description | Selected |
|--------|-------------|----------|
| 30 seconds | Tight timeout. Good for simple questions. | |
| 60 seconds | Matches existing HttpHelper socket timeout. | |
| Configurable per-agent | Timeout value in agent config.json. Default 60s. | ✓ |

**User's choice:** Configurable per-agent
**Notes:** Agents with slower models or more tools can have higher limit. Default 60s.

| Option | Description | Selected |
|--------|-------------|----------|
| Whatever agent wrote to temp file | Agent writes progress incrementally. Parent reads what it has after kill. | |
| Graceful shutdown | Child registers SIGTERM handler. Catches signal, writes PARTIAL ANSWER + result, exits. | ✓ |
| LLM calls get own timeout | Each LLM call has CURLOPT_TIMEOUT matching remaining agent time. | |

**User's choice:** Graceful shutdown
**Notes:** More reliable partial capture. Child catches SIGTERM, writes what it has, exits cleanly.

| Option | Description | Selected |
|--------|-------------|----------|
| Show 'timed out — no partial answer' | Clear timeout message if temp file empty/missing. | ✓ |
| Never truly empty | Agent writes heartbeat timestamp at start. | |

**User's choice:** Show 'timed out — no partial answer'
**Notes:** Simpler implementation. Clear output for user.

| Option | Description | Selected |
|--------|-------------|----------|
| pcntl_alarm + SIGALRM | Set pcntl_alarm(timeout) before agent research. On SIGALRM, kill children. | ✓ |
| Parent polling loop with time check | waitpid loop tracks elapsed time, kills on timeout. | |
| Both — alarm + polling | Polling as primary, alarm as safety net. | |

**User's choice:** pcntl_alarm + SIGALRM
**Notes:** Built-in PHP process timeout. Clean SIGALRM-to-kill-children flow.

---

## 4-Layer Timeout Architecture

| Option | Description | Selected |
|--------|-------------|----------|
| L1: Implicit | PHP's built-in max_execution_time. Document only. | ✓ |
| L1: Explicit set_time_limit() | Parent calls set_time_limit() before forking. | |

**User's choice:** L1 Implicit
**Notes:** PHP default handles this. No code needed.

| Option | Description | Selected |
|--------|-------------|----------|
| L3: Skip for v1 | No streaming in current implementation. Defer. | ✓ |
| L3: Simple watchdog | Parent monitors child temp file modification time. | |

**User's choice:** L3 Skip for v1
**Notes:** No streaming = no stream-idle problem. Defer to v2.

| Option | Description | Selected |
|--------|-------------|----------|
| L4: Skip for v1 | Agent runs simple linear sequence. | |
| L4: Simple deadline check | Agent checks remaining time before each step. Skip if < 5s left. | ✓ |

**User's choice:** L4 Simple deadline check
**Notes:** Each step checks elapsed time. If < 5s remaining, skip remaining steps and return what's ready.

---

## Claude's Discretion

- Exact temp file naming convention and cleanup strategy
- Signal handler registration details in child process
- Deadline check implementation specifics in ResearchAgent
- Arbitrator constructor signature and config schema details

## Deferred Ideas

- **Stream-idle watchdog (Layer 3):** Would be needed if streaming LLM responses are added. Deferred to v2.
- **Parallel CLI progress display:** Showing per-agent completion as it happens. Defer to Phase 5 when CLI REPL gets real-time progress.
- **Arbitrator evaluating Round 1 quality:** That's Phase 4 (ORCH-05, ORCH-06).
