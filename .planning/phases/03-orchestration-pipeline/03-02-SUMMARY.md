---
phase: 03-orchestration-pipeline
plan: 02
subsystem: orchestration
tags:
  - php
  - pcntl
  - signals
  - timeout
  - deadline
  - sigterm
  - sigalrm

requires:
  - phase: 03-orchestration-pipeline
    plan: 01
    provides: Arbitrator fork execution, temp file IPC, CURLOPT_NOSIGNAL

provides:
  - Layer 4 cooperative deadline check in ResearchAgent::research() (D-16)
  - Per-child deadline tracking with SIGTERM -> 2s grace -> SIGKILL enforcement (D-09)
  - Flag-based SIGTERM handler in forked children (D-10, T-03-13)
  - SIGKILL output: "[Agent name] timed out -- no partial answer" (D-11)
  - pcntl_alarm + SIGALRM batch-level timeout safety net (D-12)
  - 4-layer timeout architecture documentation (ORCH-10, D-13, D-14, D-15, D-16)

affects:
  - Phase 3 Plan 3: Receives Arbitrator with timeout enforcement for multi-batch execution
  - Phase 4 (debate/selection): Receives timed-out agents producing partial answers

tech-stack:
  added: []
  patterns:
    - Flag-based signal handlers (no file I/O in handler closures)
    - Per-child deadline tracking with polling loop enforcement
    - SIGTERM -> 2s grace period -> SIGKILL cascade
    - Batch-level pcntl_alarm as safety net
    - Cooperative deadline check with 5s buffer before hard timeout

key-files:
  modified:
    - src/Agent/ResearchAgent.php
    - src/Arbitrator/Arbitrator.php
  created:
    - tests/Agent/ResearchAgentTest.php
    - tests/Phase3/ArbitratorTest.php (added stubs)

key-decisions:
  - "D-09: Per-step timeout configurable per-agent (default 60s)"
  - "D-10: Flag-based SIGTERM handler in child (NO file I/O in signal handler)"
  - "D-11: Missing/empty temp file -> '[Agent name] timed out -- no partial answer'"
  - "D-12: Batch-level safety net pcntl_alarm + SIGALRM"
  - "D-13: Layer 1 max_execution_time -- document as inactive in CLI"
  - "D-14: Layer 2 HTTP socket timeout -- already implemented, no changes"
  - "D-15: Layer 3 stream-idle watchdog -- deferred to v2"
  - "D-16: Layer 4 cooperative deadline check in ResearchAgent"

requirements-completed:
  - ORCH-03 (per-step timeout)
  - ORCH-10 (4-layer timeout architecture)

duration: 18min
completed: 2026-06-13
---

# Phase 3 Plan 2: Timeout Architecture and Layer 4 Deadline Summary

**Complete 4-layer timeout cascade: Layer 1 documented inactive, Layer 2 existing HTTP timeout, Layer 3 deferred to v2, Layer 4 cooperative deadline check in ResearchAgent with per-child SIGTERM enforcement in Arbitrator**

## Performance

- **Duration:** 18 min
- **Started:** 2026-06-13T17:40:00Z
- **Completed:** 2026-06-13T17:58:00Z
- **Tasks:** 2
- **Files modified:** 2 (modified), 2 (new test files)

## Accomplishments

- Modified `ResearchAgent::research()` to accept optional `?float $deadline = null` parameter for Layer 4 cooperative deadline
- Added deadline check before `buildToolContext()` -- skips tool building when deadline minus 5 seconds is exceeded (D-16)
- Added deadline check before LLM call -- throws `RuntimeException` when deadline minus 5 seconds is exceeded
- Added per-child deadline tracking `$childDeadlines[$pid]` in `Arbitrator::executeBatchParallel()`
- Added `pcntl_alarm($batchTimeout) + SIGALRM` handler as batch-level safety net (D-12)
- Added per-child timeout enforcement: `posix_kill(SIGTERM)` -> 2s grace -> `posix_kill(SIGKILL)` -> `pcntl_waitpid()` (D-09)
- Added flag-based SIGTERM handler in child process (D-10) -- handler sets boolean only, no file I/O (T-03-13, T-03-08)
- Added partial answer writing in main context when `$timedOut` flag is detected after SIGTERM
- Added D-11 "timed out -- no partial answer" message for missing temp files on timeout
- Added 4-layer timeout architecture docblock to Arbitrator class (ORCH-10, D-13, D-14, D-15, D-16)
- Updated `runChildProcess()` signature to accept `float $deadline` and pass to ResearchAgent
- Created `tests/Agent/ResearchAgentTest.php` with `testDeadlineCheckSkipsToolsWhenDeadlineExceeded` and `testDeadlineCheckPassesWithSufficientTime`
- Added timeout stubs to `tests/Phase3/ArbitratorTest.php` (`testTimeoutProducesPartialAnswer`, `testLayer4DeadlineSkipBehavior`, `testLayer1DocumentedInactive`)

## Task Commits

Each task was committed atomically:

1. **Task 1: Add Layer 4 cooperative deadline check to ResearchAgent** - `798b2a6` (feat)
2. **Task 2: Add timeout enforcement, per-child deadline tracking, and partial answer handling to Arbitrator** - `a0619be` (feat)

## Files Modified

### Modified
- `src/Agent/ResearchAgent.php` - Added `$deadline` parameter to `research()`, Layer 4 deadline checks before `buildToolContext()` and LLM call
- `src/Arbitrator/Arbitrator.php` - Added 4-layer docblock, per-child deadline tracking, SIGALRM handler, per-child SIGTERM enforcement, flag-based child SIGTERM handler, partial answer handling

### Created
- `tests/Agent/ResearchAgentTest.php` - ResearchAgent deadline check unit tests
- `tests/Phase3/ArbitratorTest.php` - Added `testTimeoutProducesPartialAnswer`, `testLayer4DeadlineSkipBehavior`, `testLayer1DocumentedInactive` stubs

## Decisions Made

- D-09 through D-16 implemented as specified in CONTEXT.md
- Deadlines are computed as `microtime(true) + batchTimeout` for each child, ensuring per-child granularity
- Signal handlers are strictly flag-based (no file I/O, no complex logic) per PHP manual guidelines
- The 5-second buffer in ResearchAgent deadline checks ensures the agent stops before the hard SIGTERM deadline, leaving time for partial answer writes
- SIGTERM handler handler in child is only for graceful shutdown; parent still uses SIGKILL after 2s grace
- Sequential fallback path leaves deadline as null (default) -- Layer 4 check is opt-in for parallel execution

## Deviations from Plan

None -- plan executed exactly as written.

### Auto-fixed Issues

None -- no bugs, missing criticals, or blocking issues found during execution.

## Threat Surface Scan

No new threat flags beyond what is documented in the plan's threat model. All T-03-xx mitigations are implemented:
- T-03-07 (SIGALRM handler deadlock): Handler sets boolean only, no I/O/complex logic
- T-03-08 (SIGTERM mid-write): Handler sets flag only; partial write detection via JSON decode failure in parent
- T-03-09 (False partial answer): Accepted -- child's partial answer is honest best effort
- T-03-10 (Zombie processes): Both SIGTERM and SIGKILL paths call pcntl_waitpid
- T-03-11 (Inherited SIGALRM): Child explicitly resets SIGALRM to SIG_DFL + pcntl_alarm(0)
- T-03-12 (Layer 1 inactive): Documented in class docblock per D-13
- T-03-13 (Signal handler file I/O): Both handlers are strictly flag-based

## Known Stubs

The test file `tests/Phase3/ArbitratorTest.php` contains timeout stubs (`testTimeoutProducesPartialAnswer`, `testLayer4DeadlineSkipBehavior`) marked `markTestIncomplete`. These are intentional stubs for future waves to implement as integration tests requiring fork-capable environments.

## Issues Encountered

- PHPUnit was not installed initially (vendor/bin/phpunit missing). Resolved with `composer install`.
- `testLayer1DocumentedInactive` initially failed due to case sensitivity in the `assertStringContainsString` assertion -- "inactive" vs "Inactive". Fixed by matching the docblock's actual capitalization.

## Next Phase Readiness

- **Plan 03-03 (Output display, log format standardization, multi-batch refinements):** Ready. Arbitrator has timeout enforcement and partial answer collection. Next plan can focus on output formatting for partial results and multi-batch display.

---
*Phase: 03-orchestration-pipeline*
*Completed: 2026-06-13*
