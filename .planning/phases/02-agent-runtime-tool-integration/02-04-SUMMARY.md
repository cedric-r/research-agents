---
phase: 02-agent-runtime-tool-integration
plan: 04
subsystem: agent-runtime
tags: [error-isolation, code-review, http-client, null-safety, curl-multi]

# Dependency graph
requires:
  - phase: 02-03
    provides: AgentManager with multi-agent discovery, ToolRegistry, AcademicSearch, HttpHelper, LlmClient provider switching
provides:
  - Per-agent error isolation in AgentManager::research() — one agent crash never blocks others
  - Safe glob() failure handling in bin/check-config
  - Correct Semantic Scholar paper URLs (human-readable paper pages)
  - Dedup seen cache stays in sync — three+ duplicate papers handled correctly
  - curl_multi_select error fallback with usleep (no busy-wait)
  - arXiv API called with HTTPS only
  - Null-safe getLastResponseInfo() — no PHP warning on pre-chat call
affects: [03-arbitrator-debate (Phase 3)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-agent try/catch with error result structure for partial-failure tolerance"
    - "Socket timeout fallback: curl_multi_select -1 handled with usleep"

key-files:
  created: []
  modified:
    - src/Agent/AgentManager.php
    - bin/check-config
    - src/Tool/AcademicSearch.php
    - src/Http/HttpHelper.php
    - src/LlmClient/LlmClient.php

key-decisions:
  - "Error result for failed agents includes all standard keys (answer, model, response_time_ms, usage, correlation_id) plus error string — matches successful result shape for downstream consumers"

patterns-established: []

# Metrics
duration: 10min
completed: 2026-06-13
---

# Phase 2 Plan 04: Error Isolation and Code Review Fixes Summary

**Per-agent error isolation in AgentManager::research() with 6 code review fixes across 5 files — blocking gaps closed for multi-agent stability**

## Performance

- **Duration:** 10 min
- **Completed:** 2026-06-13
- **Tasks:** 2 (both auto)
- **Files modified:** 5

## Accomplishments

- AgentManager::research() now wraps each per-agent execution (Logger construction, ResearchAgent construction, configureTools, setToolRegistry, and research()) in try/catch for \Throwable. One agent failure no longer blocks subsequent agents.
- Failed agents produce structured error result entries matching the successful result shape plus an `error` key, ensuring downstream consumers (arbitrator) can handle partial failures uniformly.
- 6 code review findings (CR-01, CR-02, WR-03, WR-04, WR-05, WR-06) resolved with surgical changes — no regression risk.

## Task Commits

Each task was committed atomically:

1. **Task 1: Per-agent error isolation** - `1355fb6` (feat)
2. **Task 2: 6 code review fixes** - `858a604` (fix)

## Files Modified

- `src/Agent/AgentManager.php` — Wrapped research() foreach body in try/catch; added error result structure with `error` key; updated `@return` type annotation
- `bin/check-config` — Added `exit(0);` after glob empty guard to prevent foreach crash on false/empty
- `src/Tool/AcademicSearch.php` — Fixed Semantic Scholar URL to `www.semanticscholar.org/paper/`; added `$seen[$key] = $paper` in dedup replacement block; changed arXiv URL from HTTP to HTTPS
- `src/Http/HttpHelper.php` — Guarded `curl_multi_select` return value with `usleep(10000)` fallback on -1
- `src/LlmClient/LlmClient.php` — Added `$response = $this->lastResponse ?? []` null guard in `getLastResponseInfo()`

## Decisions Made

- Error result for failed agents includes all the standard keys (`answer`, `model`, `response_time_ms`, `usage`, `correlation_id`) plus an `error` string — this keeps the result structure consistent for downstream consumers like the arbitrator, which can check for the `error` key rather than testing existence of array keys.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None — all changes were straightforward surgical edits that passed syntax and autoloader verification immediately.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- AgentManager error isolation complete — ready for Phase 3 arbitrator debate implementation
- bin/check-config no longer crashes on empty agents directory
- AcademicSearch tool has correct URLs and dedup behavior
- HttpHelper has robust curl_multi error handling
- LlmClient null-safe for pre-chat calls

---

*Phase: 02-agent-runtime-tool-integration*
*Completed: 2026-06-13*
