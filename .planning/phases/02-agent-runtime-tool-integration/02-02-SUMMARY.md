---
phase: 02-agent-runtime-tool-integration
plan: 02
subsystem: api, integration
tags: web-search, brave-api, arxiv, semantic-scholar, academic-search, tool-registry

# Dependency graph
requires:
  - phase: 02-01
    provides: HttpHelper, ToolRegistry, LlmClient
provides:
  - WebSearch tool (Brave Search API) with formatted context block output
  - AcademicSearch tool (arXiv + Semantic Scholar merged, deduplicated)
  - Tool-wired ResearchAgent with preferences-driven tool selection
  - Updated CLI entry point (research.php) with tool configuration
affects:
  - 02-03 (AgentManager + multi-agent orchestration will use tools)
  - Phase 3 (parallel agents will benefit from tool context)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Tool-enabled LLM prompting: tool results prepended as context block in system prompt"
    - "Dual-API academic search: query both arXiv (Atom XML) and Semantic Scholar (REST JSON), merge and deduplicate"
    - "Provider-agnostic web search config: single Brave Search provider via config-driven api_key"
    - "Graceful degradation: tool failures return empty string, agent continues without crashing"

key-files:
  created:
    - src/Tool/WebSearch.php
    - src/Tool/AcademicSearch.php
    - tests/Tool/WebSearchTest.php
    - tests/Tool/AcademicSearchTest.php
  modified:
    - src/Agent/ResearchAgent.php
    - research.php
    - config/agents/researcher/preferences.json

key-decisions:
  - "Brave Search API as single web search provider, configured via api_key in agent config"
  - "AcademicSearch combines arXiv + Semantic Scholar in one tool, merged and deduplicated by arXiv ID and DOI"
  - "Tool context prepended to system message as additional paragraphs, not separate messages"
  - "Semantic Scholar uses standard /paper/search endpoint (not /bulk which returns 2.3MB responses)"
  - "Tools gracefully degrade on failure (empty string) instead of propagating exceptions to the agent"

patterns-established:
  - "Tool registration: tools are registered by name + callable handler + JSON schema via ToolRegistry"
  - "Context block injection: tool outputs formatted as markdown sections prepended to system prompt"
  - "Independent API failure: AcademicSearch handles arXiv and Semantic Scholar failures independently"

requirements-completed:
  - TOOL-02
  - TOOL-03
  - TOOL-04
  - TOOL-06
  - TOOL-07

# Metrics
duration: 28min
completed: 2026-06-13
---

# Phase 2 Plan 2: Web Search and Academic Search Tools

**WebSearch (Brave Search API) and AcademicSearch (arXiv + Semantic Scholar merged) tools created, registered via ToolRegistry, and integrated into ResearchAgent with preferences-driven tool selection and context block injection**

## Performance

- **Duration:** 28 min
- **Started:** 2026-06-13T18:55:00Z
- **Completed:** 2026-06-13T19:23:00Z
- **Tasks:** 3 (TDD: 5 commits including test-only)
- **Files modified:** 6

## Accomplishments

- WebSearch tool: Brave Search API integration with config-driven api_key and count, formatted context block output, graceful error handling
- AcademicSearch tool: combined arXiv (Atom XML via SimpleXMLElement) and Semantic Scholar (REST JSON) queries, merged and deduplicated results, sorted by year/citations
- ResearchAgent extended with setToolRegistry() and buildToolContext() — tool results prepended to system prompt as context block
- CLI entry point (research.php) updated with complete tool wiring, WebSearch and AcademicSearch registered via ToolRegistry
- Preferences-driven tool selection: llm_only flag, web_search toggle, paper_search toggle in preferences.json

## Task Commits

Each task was committed atomically:

1. **Task 1: WebSearch tool (TDD RED+GREEN)**
   - `db7fac0` (test): add failing test for WebSearch tool
   - `ec8a4b8` (feat): implement WebSearch tool for Brave Search API

2. **Task 2: AcademicSearch tool (TDD RED+GREEN)**
   - `a4f355a` (test): add failing test for AcademicSearch tool
   - `3aa74d3` (feat): implement AcademicSearch tool for arXiv and Semantic Scholar

3. **Task 3: Wire tools into ResearchAgent and update CLI**
   - `a483a8a` (feat): wire tools into ResearchAgent and update CLI

## Files Created/Modified

### Created
- `src/Tool/WebSearch.php` - Brave Search API web search tool with graceful degradation
- `src/Tool/AcademicSearch.php` - Combined arXiv + Semantic Scholar paper search tool with dedup
- `tests/Tool/WebSearchTest.php` - Unit tests for WebSearch (api_key validation, query param, formatting)
- `tests/Tool/AcademicSearchTest.php` - Unit tests for AcademicSearch (query param, context block output)

### Modified
- `src/Agent/ResearchAgent.php` - Added setToolRegistry(), buildToolContext(), tool context in system prompt
- `research.php` - Tool wiring: creates WebSearch, AcademicSearch, registers via ToolRegistry
- `config/agents/researcher/preferences.json` - Updated to enable web_search and paper_search

### Created (gitignored, local setup)
- `config/agents/researcher/config.json` - Added brave_api_key and semantic_scholar_api_key fields

## Decisions Made

- Brave Search API selected as single web search provider (TOOL-06 provider abstraction via config, future providers add their own handler)
- AcademicSearch queries both arXiv (Atom XML parsed with SimpleXMLElement) and Semantic Scholar (REST JSON) in one execution, merging and deduplicating by arXiv ID and DOI
- Tool results formatted as markdown "## Web Search Results" / "## Academic Paper Results" blocks and prepended to the system message content (not a separate message)
- Semantic Scholar endpoint: standard `/paper/search` not `/bulk`, as `/bulk` returns up to 2.3MB per response (unsuitable for interactive tool use)
- Tools gracefully degrade: HTTP failures, parse errors, and empty results all return empty string instead of crashing the agent

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Moved api_key validation from WebSearch constructor to execute() method**
- **Found during:** Task 1 (WebSearch verification)
- **Issue:** Constructor threw RuntimeException for missing api_key, but the plan's verification pattern wrapped only execute() in try-catch. The exception was uncaught, causing the verify script to crash.
- **Fix:** Moved api_key validation from constructor to execute() method. Constructor now accepts the config without validation; execute() throws if api_key is missing at call time.
- **Files modified:** `src/Tool/WebSearch.php`
- **Verification:** All tests pass, plan's verify command produces expected output
- **Committed in:** ec8a4b8

**2. [Rule 3 - Blocking] Changed Semantic Scholar from /bulk endpoint to standard search endpoint**
- **Found during:** Task 2 (AcademicSearch implementation)
- **Issue:** The `/graph/v1/paper/search/bulk` endpoint returns up to 2.3MB of JSON data per query (full paper data), making the tool response excessively large for LLM context windows.
- **Fix:** Changed to standard `/graph/v1/paper/search` endpoint which returns only 5 papers at ~11KB. Same field selection works identically.
- **Files modified:** `src/Tool/AcademicSearch.php`
- **Verification:** AcademicSearch returns 2-3KB formatted results vs 500KB+ from bulk endpoint
- **Committed in:** 3aa74d3

---

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking)
**Impact on plan:** Both fixes necessary for correct operation. No scope creep.

## Issues Encountered

- `config/agents/researcher/config.json` did not exist in the worktree (gitignored from repo). Created with required fields (provider, model, api_key) plus tool API key fields (brave_api_key, semantic_scholar_api_key) as local setup. This is expected — the config is gitignored and must be created per environment.
- Semantic Scholar rate limiting during development: HTTP 429 responses occurred from rapid testing. Code handles this gracefully (returns empty string, logs warning).

## TDD Gate Compliance

- Task 1: `test(02-02)` commit (db7fac0) exists before `feat(02-02)` commit (ec8a4b8) -- PASS
- Task 2: `test(02-02)` commit (a4f355a) exists before `feat(02-02)` commit (3aa74d3) -- PASS

## User Setup Required

None - tools gracefully degrade to empty results if API keys are not configured.

External services for production use:
- Brave Search API: register at https://api.search.brave.com/app/register, add `brave_api_key` to `config/agents/researcher/config.json`
- Semantic Scholar API: optional, for higher rate limits add `semantic_scholar_api_key` to `config/agents/researcher/config.json`

## Next Phase Readiness

- WebSearch and AcademicSearch tools ready for AgentManager (Plan 02-03)
- Tool wiring pattern established: create tool, register with ToolRegistry, inject via setToolRegistry()
- preferences.json supports llm_only flag to disable tools per-agent
- Two tools registered and verified: AgentManager can reuse the same pattern with `$agent->setToolRegistry($toolRegistry)`

---
*Phase: 02-agent-runtime-tool-integration*
*Completed: 2026-06-13*
