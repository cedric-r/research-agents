---
phase: 03-orchestration-pipeline
plan: 01
subsystem: orchestration
tags:
  - php
  - pcntl
  - fork
  - parallel-execution
  - ipc
  - arbitrator

requires:
  - phase: 02-agent-runtime-tool-integration
    provides: AgentManager discovery, ResearchAgent lifecycle, HttpHelper timeouts

provides:
  - Parallel agent execution via pcntl_fork with process isolation
  - Temp file IPC for inter-process result transfer
  - Batch processing with configurable max concurrent agents
  - Fork-safe curl configuration (CURLOPT_NOSIGNAL)
  - AgentManager stripped to discovery-only role

affects:
  - Phase 4 (debate/selection): Receives Arbitrator with research() return shape
  - Phase 5 (CLI REPL): Receives research.php entry point with Arbitrator wiring

tech-stack:
  added: []
  patterns:
    - Fork + pcntl_waitpid polling loop for child reaping
    - Temp file IPC with PID-in-filename collision avoidance
    - Batch processing with max_concurrent_agents queue
    - Sequential fallback when pcntl_fork unavailable

key-files:
  created:
    - src/Arbitrator/Arbitrator.php
    - config/arbitrator/config.json
    - tests/Phase3/bootstrap.php
    - tests/Phase3/ArbitratorTest.php
  modified:
    - src/Http/HttpHelper.php
    - src/Agent/AgentManager.php
    - research.php
    - tests/Http/HttpHelperTest.php

key-decisions:
  - "D-01: New src/Arbitrator/ namespace for Arbitrator class"
  - "D-02: Arbitrator uses AgentManager only for getAgentConfigs()"
  - "D-03: research.php creates Arbitrator, calls $arbitrator->research()"
  - "D-04: Return same result shape as AgentManager::research()"
  - "D-05: pcntl_fork for parallelism"
  - "D-06: Max concurrent agents (default 5), queue overflow"
  - "D-07: IPC via temp files: sys_get_temp_dir()/agent_{name}_{corrId}_{pid}.json"
  - "D-08: pcntl_waitpid() loop for child tracking"

patterns-established:
  - "Pattern 1: Fork + polling wait loop for child reaping. Parent tracks $children[$pid] map, polls with pcntl_waitpid(WNOHANG) + usleep(100000), processes temp file results on each child exit."
  - "Pattern 2: Temp file IPC with PID-in-path. Each child writes sys_get_temp_dir()/agent_{sanitizedName}_{correlationId}_{getmypid()}.json with LOCK_EX. Parent reads by PID known from tracking."
  - "Pattern 3: Config file with safe defaults. Loader loads arbitrator config with no required fields; invalid/missing keys fall back to safe defaults."

requirements-completed:
  - ORCH-01
  - ORCH-02
  - ORCH-04

duration: 18min
completed: 2026-06-13
---

# Phase 3 Plan 1: Core Arbitrator with Fork Execution Summary

**pcntl_fork-based parallel agent executor with temp file IPC, agent discovery via AgentManager, batch processing with max_concurrent limit, and fork-safe curl configuration**

## Performance

- **Duration:** 18 min
- **Started:** 2026-06-13T15:18:00Z
- **Completed:** 2026-06-13T15:36:00Z
- **Tasks:** 3
- **Files modified:** 9

## Accomplishments

- Created `src/Arbitrator/Arbitrator.php` with `research()` method distributing questions across forked child processes in configurable batches
- Added `CURLOPT_NOSIGNAL => true` to `HttpHelper::buildCurlOptions()` for fork-safe curl operation (libcurl uses poll/select instead of SIGALRM)
- Stripped `AgentManager::research()` orchestration loop -- AgentManager now handles agent discovery only, with `configureTools()` made public for child process tool wiring
- Wired Arbitrator into `research.php` entry point via `$arbitrator->research()` (D-03), preserving same result shape (D-04)
- Implemented temp file IPC with PID in filename (`agent_{name}_{corrId}_{pid}.json`) for collision avoidance (T-03-02)
- Implemented signal reset in child processes: `pcntl_signal(SIGALRM, SIG_DFL)` + `pcntl_alarm(0)` per RESEARCH.md Pitfall 3
- Sequential execution fallback when `pcntl_fork` is unavailable, with warning log
- Agent name sanitization with `preg_replace('/[^a-zA-Z0-9_-]/', '_', $name)` for defense-in-depth (T-03-01)

## Task Commits

Each task was committed atomically:

1. **Task 1: Create arbitrator config, test infrastructure, and HttpHelper fork safety** - `8d49af1` (feat)
2. **Task 2: Implement Arbitrator class with fork execution and temp file IPC** - `cf6a167` (feat)
3. **Task 3: Wire Arbitrator into research.php entry point** - `70fa655` (feat)

## Files Created/Modified

### Created
- `src/Arbitrator/Arbitrator.php` - Orchestrator class with research(), fork/wait lifecycle, temp file IPC, batch processing
- `config/arbitrator/config.json` - Arbitrator configuration (max_concurrent_agents: 5, agent_timeout: 60)
- `tests/Phase3/bootstrap.php` - Autoloader bootstrap for Phase 3 tests
- `tests/Phase3/ArbitratorTest.php` - Stub test methods for ORCH-01, ORCH-02, ORCH-04

### Modified
- `src/Http/HttpHelper.php` - Added CURLOPT_NOSIGNAL => true to buildCurlOptions()
- `src/Agent/AgentManager.php` - Removed research(), made configureTools() public, updated docblock
- `research.php` - Creates Arbitrator instance, calls $arbitrator->research() instead of $agentManager->research()
- `tests/Http/HttpHelperTest.php` - Added testForkSafeCurlOptions() via reflection
- `.gitignore` - Added logs/ to gitignore

## Decisions Made

- D-01 through D-08 implemented as specified in CONTEXT.md
- Temp file path includes PID for collision avoidance (per RESEARCH.md Pitfall 4)
- `getTempFilePath()` takes required PID parameter -- no glob scanning in parent
- `readTempFile()` and `cleanTempFile()` both take PID for exact path reconstruction
- Config file loaded via Loader with no required fields; missing/invalid keys fall back to safe defaults
- `makeErrorResult()` helper extracted to DRY error result construction across fork failure, sequential failure, and missing temp file paths

## Deviations from Plan

None -- plan executed exactly as written.

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Added logs/ to .gitignore**
- **Found during:** Task 3 (verification)
- **Issue:** Running `php research.php "test"` created a `logs/` directory with runtime files that were untracked
- **Fix:** Added `logs/` to `.gitignore` following the same pattern as other generated directories
- **Files modified:** `.gitignore`
- **Verification:** `git status --short` no longer shows `logs/` as untracked
- **Committed in:** `70fa655` (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Minor -- runtime log directory needed gitignore entry to prevent untracked file pollution.

## Threat Surface Scan

No new threat flags beyond what is documented in the plan's threat model. All T-03-xx mitigations are implemented:
- T-03-01 (agent name tampering): `sanitizeAgentName()` with preg_replace
- T-03-02 (temp file integrity): `LOCK_EX` on write, PID in filename
- T-03-03 (fork bomb): Cap at `max_concurrent_agents` (default 5)
- T-03-04 (zombie processes): All children reaped via `pcntl_waitpid` in wait loop
- T-03-05 (information disclosure): Fresh HttpHelper/Logger instances per child
- T-03-06 (signal inheritance): SIGALRM reset to SIG_DFL + pcntl_alarm(0)

## Known Stubs

The test file `tests/Phase3/ArbitratorTest.php` contains 4 stub test methods marked `markTestIncomplete`. These are intentional stubs for future waves to implement as integration tests with agent config fixtures and fork-capable environments.

## Issues Encountered

- Git worktree isolation required using absolute paths for file operations (worktree at `.claude/worktrees/agent-*/`). All writes used the worktree-specific path.
- Config file `config/arbitrator/config.json` is gitignored by existing `.gitignore` pattern (API key configs). Documented but not committed.

## User Setup Required

None -- no external service configuration required.

## Next Phase Readiness

- **Plan 03-02 (Timeout enforcement and partial answers):** Ready. Arbitrator class has the hook points for per-agent timeout enforcement (polling loop already checks child completion). Next plan adds SIGTERM path, deadline checks, and partial answer handling.
- **Phase 4 (Debate/Selection):** Ready. Arbitrator::research() returns the same result shape as before. Phase 4 can add debate agent execution on top of this framework.

---
*Phase: 03-orchestration-pipeline*
*Completed: 2026-06-13*
