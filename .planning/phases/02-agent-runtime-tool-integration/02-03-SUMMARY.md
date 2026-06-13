---
phase: 02-agent-runtime-tool-integration
plan: 03
subsystem: agent, orchestration, configuration
tags: agent-manager, multi-agent, agent-discovery, lifecycle-management, config-validation
requires:
  - phase: 02-02
    provides: ResearchAgent, ToolRegistry, WebSearch, AcademicSearch, tool wiring pattern
provides:
  - AgentManager class with discoverAgents(), research(), getAgentConfigs()
  - Multi-agent CLI entry point (research.php uses AgentManager)
  - Dynamic multi-agent config validation (bin/check-config scans all agents)
affects:
  - Phase 3 (parallel agents will use AgentManager::research() as dispatch point)
tech-stack:
  added: []
  patterns:
    - "Agent discovery via glob-based directory scan (config/agents/*/config.json)"
    - "Per-agent fresh ResearchAgent instantiation per research() call (D-02)"
    - "Per-agent tool configuration from agent config (brave_api_key gates WebSearch)"
key-files:
  created:
    - src/Agent/AgentManager.php
  modified:
    - research.php
    - bin/check-config
  created-local:
    - config/agents/researcher/config.json
key-decisions:
  - "AgentManager takes agentsBaseDir, configLoader, logFile, and optional Logger for SYSTEM-level messages"
  - "HttpHelper shared across agents (stateless), ToolRegistry created per agent"
  - "configureTools() replicates research.php pattern: WebSearch gated on brave_api_key, AcademicSearch always attempted"
  - "check-config dynamically scans agent directories instead of hardcoded list"
requirements-completed:
  - CONF-08
metrics:
  duration: 2min
  completed: 2026-06-13
---

# Phase 2 Plan 3: AgentManager for Multi-Agent Discovery and Lifecycle

**AgentManager created with glob-based agent discovery, fresh ResearchAgent instantiation per call, per-agent tool configuration, and multi-agent CLI dispatch; check-config updated for dynamic agent validation.**

## Performance

- **Duration:** 2 min
- **Started:** 2026-06-13T13:59:13Z
- **Completed:** 2026-06-13T14:01:04Z
- **Tasks:** 3
- **Files modified:** 3 (1 created, 2 modified)

## Accomplishments

- **AgentManager** (`src/Agent/AgentManager.php`): Scans `config/agents/*/config.json` via `glob()` to discover configured agents. Creates fresh `ResearchAgent` instances per `research()` call. Configures tools (WebSearch gated on `brave_api_key`, AcademicSearch always attempted) per agent. Returns structured results keyed by agent name.
- **research.php updated**: Replaced direct `ResearchAgent` instantiation and per-script tool wiring with `AgentManager::research()`. Outputs per-agent answer sections with model and token usage. Shows overall "Research Complete" summary with agent count.
- **bin/check-config updated**: Replaced hardcoded config file list with dynamic agent directory scan. Validates `config.json` (required fields), `preferences.json` (JSON validity), and `SOUL.md` (exists and non-empty) per agent. Arbitrator config checked if file exists.

## Task Commits

Each task was committed atomically:

1. **Task 1: Create AgentManager**
   - `a44ebaf` (feat): create AgentManager for multi-agent discovery and lifecycle

2. **Task 2: Update research.php**
   - `4be2140` (feat): wire AgentManager into CLI entry point for multi-agent research

3. **Task 3: Update bin/check-config**
   - `47ea2b7` (feat): update check-config for dynamic multi-agent validation

## Files Created/Modified

### Created
- `src/Agent/AgentManager.php` - Agent discovery via glob, per-agent fresh ResearchAgent lifecycle, per-agent tool configuration, structured results keyed by agent name (262 lines)

### Modified
- `research.php` - Replaced direct ResearchAgent + tool wiring with AgentManager; multi-agent output with per-agent sections and summary (29 insertions, 93 deletions)
- `bin/check-config` - Dynamic agent directory scan with per-file validation; optional arbitrator check if file exists (69 insertions, 24 deletions)

### Created (gitignored, local setup)
- `config/agents/researcher/config.json` - Template config (recreated after being lost between waves per STATE.md risk tracking)

## Decisions Made

- AgentManager constructor takes `$agentsBaseDir`, `$configLoader`, `$logFile`, and optional `$logger` for SYSTEM-level messages. Per-agent Loggers are created internally using `$logFile` and the agent name as channel.
- `HttpHelper` is stateless and shared across all agents; `ToolRegistry` is per-agent since tool availability differs per agent config.
- `configureTools()` replicates the tool wiring from research.php: WebSearch gated on `brave_api_key`, AcademicSearch always attempted (arXiv requires no API key).
- `check-config` does not fail if `preferences.json` is missing (optional file, logged as WARN). Config.json and SOUL.md are required (logged as FAIL if missing).

## Deviations from Plan

None -- plan executed exactly as written.

## Known Stubs

- `config/agents/researcher/config.json` contains placeholder values (`YOUR_API_KEY_HERE`, empty `brave_api_key`). This is intentional -- the file is gitignored to prevent credential leaks, and users must populate their own API keys per environment.

## Threat Flags

No threat flags. The AgentManager reads config files with API keys (same pattern as existing ResearchAgent) but introduces no new network endpoints, auth paths, or file access patterns beyond those already in the codebase.

## Self-Check: PASSED

- All 3 files created/modified verified on disk
- All 3 commits verified in git log
- PHP syntax valid on all modified files
- `bin/check-config` runs successfully: 3/3 checks passed
