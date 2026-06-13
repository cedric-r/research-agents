---
phase: 02-agent-runtime-tool-integration
verified: 2026-06-13T19:00:00Z
status: gaps_found
score: 16/17 must-haves verified
overrides_applied: 0
gaps:
  - truth: "One agent failure does not block others (D-03 / T-02-06)"
    status: failed
    reason: "AgentManager::research() does not wrap $agent->research($question) in try/catch per agent. If one agent throws (e.g., LlmException from an API failure), the exception propagates and blocks all subsequent agents. This violates design decision D-03 and threat mitigation T-02-06."
    artifacts:
      - path: "src/Agent/AgentManager.php"
        issue: "Lines 164-192: $agent->research($question) on line 184 not wrapped in per-agent try/catch"
    missing:
      - "Wrap $agent->research($question) in try/catch in AgentManager::research()"
      - "On failure, store error result (not throw) and continue to next agent"
---

# Phase 2: Agent Runtime & Tool Integration -- Verification Report

**Phase Goal:** Extend single-agent baseline with AgentManager for multi-agent discovery, web search (Brave Search), scientific paper search (arXiv + Semantic Scholar), centralized HTTP timeout enforcement, and config-driven provider switching in LlmClient.

**Verified:** 2026-06-13T19:00:00Z

**Status:** gaps_found

## Goal Achievement

### Observable Truths (from ROADMAP Success Criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | AgentManager discovers all configured agents by scanning `config/agents/` directories at runtime | VERIFIED | `AgentManager::discoverAgents()` uses `glob($this->agentsBaseDir . '/*/config.json')`. Tested: 1 agent discovered (researcher). |
| 2 | Agent can perform web searches via a configurable search API provider and use results in its answer | VERIFIED | `WebSearch` class implements Brave Search API with config-driven api_key. Tool results formatted as context block and prepended to system prompt via `ResearchAgent::buildToolContext()`. |
| 3 | Agent can search arXiv and Semantic Scholar for scientific papers and citations, returning structured results | VERIFIED | `AcademicSearch` class queries both APIs independently, merges, deduplicates (by arXiv ID / DOI). arXiv via SimpleXMLElement (Atom XML), Semantic Scholar via JSON. |
| 4 | LlmClient handles LLM API calls with model/provider selection, supporting at minimum the provider configured in Phase 1 | VERIFIED | `LlmClient` receives provider config (base_url, api_key, model). `ResearchAgent::resolveBaseUrl()` maps provider names (deepseek/openrouter) or uses explicit `provider_base_url`. |
| 5 | HTTP socket-level timeouts are enforced on all external API calls -- a hanging API does not freeze the system | VERIFIED | `HttpHelper` enforces `CURLOPT_TIMEOUT=60` and `CURLOPT_CONNECTTIMEOUT=10` via `buildCurlOptions()`. All external HTTP (LlmClient, WebSearch, AcademicSearch) goes through `HttpHelper`. |

### Observable Truths (from Plan must_haves)

#### Plan 02-01: Shared Infrastructure Layer (TOOL-05, TOOL-08)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | All external HTTP calls use HttpHelper with enforced timeouts (CURLOPT_TIMEOUT=60, CURLOPT_CONNECTTIMEOUT=10) | VERIFIED | `HttpHelper::buildCurlOptions()` line 166-167 sets both timeouts. `LlmClient::chat()` calls `$this->http->post()`, `WebSearch::execute()` calls `$this->http->get()`, `AcademicSearch` calls `$this->http->get()`. |
| 2 | Tools can be registered and dispatched by name via ToolRegistry::run() | VERIFIED | `ToolRegistry::register()` validates callable handler + schema array. `ToolRegistry::run()` dispatches via `call_user_func()`. Returns string result. |
| 3 | LlmClient uses HttpHelper instead of direct curl calls | VERIFIED | `LlmClient` constructor accepts optional `HttpHelper` (defaults to new instance). `chat()` method uses `$this->http->post()` (line 57). No direct `curl_init()`/`curl_exec()` in LlmClient. |
| 4 | curl_multi_exec is available for future parallel HTTP requests | VERIFIED | `HttpHelper::getMulti()` implements `curl_multi_exec` with `curl_multi_select()` polling. Returns results keyed by URL key. |

#### Plan 02-02: Web Search and Academic Search Tools (TOOL-02, TOOL-03, TOOL-04, TOOL-06, TOOL-07)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Agent can search the web via Brave Search API | VERIFIED | `WebSearch::execute()` constructs URL `https://api.search.brave.com/res/v1/web/search?q=...`, sends `X-Subscription-Token` header via `HttpHelper::get()`, parses JSON response `data['web']['results']`, returns formatted context block. |
| 2 | Agent can search scientific papers via arXiv and Semantic Scholar | VERIFIED | `AcademicSearch::queryArxiv()` calls arXiv API, parses Atom XML with SimpleXMLElement using correct namespaces (`http://www.w3.org/2005/Atom`, `http://arxiv.org/schemas/atom`). `AcademicSearch::querySemanticScholar()` calls Semantic Scholar standard search endpoint. |
| 3 | Tool results appear as a context block in the LLM system prompt | VERIFIED | `ResearchAgent::buildToolContext()` collects tool results. `ResearchAgent::research()` prepends tool context to system message via `$systemContent .= "\n\n" . $toolContext`. Format: "## Web Search Results" and "## Academic Paper Results" markdown blocks. |
| 4 | Academic search returns merged, deduplicated results from both APIs | VERIFIED | `AcademicSearch::execute()` queries both APIs independently, merges via `array_merge()`, deduplicates via `deduplicate()` (arXiv ID primary, DOI secondary, title md5 hash fallback with data-completeness preferring non-empty abstracts). |
| 5 | Full detail results include title, authors, truncated abstract, URL, date, DOI/citation count | VERIFIED | `AcademicSearch::formatResults()` produces lines with title, authors (first 3 + et al.), year/n.d., abstract (300 chars), URL, Citations, DOI. Matching D-10 spec. |

#### Plan 02-03: AgentManager (CONF-08)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | AgentManager discovers all agents by scanning config/agents/*/config.json | VERIFIED | `discoverAgents()` globs `$this->agentsBaseDir . '/*/config.json'`, extracts agent name from `basename(dirname($configFile))`, sorts alphabetically. |
| 2 | AgentManager creates fresh ResearchAgent per research() call per D-02 | VERIFIED | `research()` loop creates `new ResearchAgent($agentDir, $this->configLoader, $agentLogger)` inside the foreach body, for each agent on each call. |
| 3 | AgentManager returns array of agent answers keyed by agent name | VERIFIED | `$results[$agentName] = $result;` inside the foreach, returns `$results` array. Each result has answer, model, response_time_ms, usage, correlation_id. |
| 4 | User can run multi-agent research and see all agents' answers | VERIFIED | `research.php` uses `AgentManager`, outputs `=== Agent: {name} ===` sections with answer text, metadata, and `== Research Complete ==` summary with agent count. |
| 5 | bin/check-config validates all agent configs | VERIFIED | `bin/check-config` dynamically scans `config/agents/*/` directories via `glob()` with `GLOB_ONLYDIR`. Validates config.json (required fields), preferences.json (JSON validity), SOUL.md (exists and non-empty). Tests: 3/4 checks pass (api_key is empty placeholder -- expected failure for gitignored file). |

**Score:** 16/17 truths verified (1 gap)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `src/Http/HttpHelper.php` | Centralized HTTP utility with timeout enforcement | VERIFIED | 207 lines. get(), post(), getMulti() implemented. CURLOPT_TIMEOUT=60, CURLOPT_CONNECTTIMEOUT=10. URL sanitization in error messages (T-02-03). |
| `src/Http/HttpException.php` | Typed exception for HTTP failures | VERIFIED | 9 lines. Extends `\RuntimeException`. |
| `src/Tool/ToolRegistry.php` | Tool registration and dispatch by name | VERIFIED | 152 lines. register() with handler/schema validation. run() dispatches via call_user_func. getSchemas(), has() methods. Logger integration. |
| `src/Tool/WebSearch.php` | Brave Search API web search tool | VERIFIED | 146 lines. execute() with X-Subscription-Token auth. Formatted context block output. Graceful degradation on non-200 / JSON parse failure. |
| `src/Tool/AcademicSearch.php` | Combined arXiv + Semantic Scholar paper search | VERIFIED | 407 lines. queryArxiv() with SimpleXMLElement, querySemanticScholar() with JSON. Deduplication by arXiv ID / DOI / title. Sorted by year, citations. |
| `src/Agent/AgentManager.php` | Agent discovery and lifecycle management | VERIFIED | 262 lines. discoverAgents(), research(), getAgentConfigs(). Per-agent tool configuration via configureTools(). |
| `src/Agent/ResearchAgent.php` | Tool-enabled research agent | VERIFIED | 232 lines. setToolRegistry(), buildToolContext(), research() with tool context block injection. |
| `research.php` | Multi-agent CLI entry point | VERIFIED | 86 lines. Uses AgentManager. Per-agent formatted output. Top-level try/catch for error handling. |
| `bin/check-config` | Health check for all agent configs | VERIFIED | 98 lines. Dynamic glob-based agent directory scan. Validates config.json, preferences.json, SOUL.md. Arbitrator config checked if exists. |
| `composer.json` | Composer config with PHPUnit dependency | VERIFIED | 20 lines. phpunit/phpunit ^12.0 dev dependency, PSR-4 autoload. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `LlmClient::chat()` | `HttpHelper::post()` | HTTP POST to /chat/completions | VERIFIED | `$this->http->post($url, $payload, $headers)` at line 57 |
| `ToolRegistry::run()` | registered handler | callable dispatch | VERIFIED | `call_user_func($handler, $params)` at line 87 |
| `ResearchAgent::research()` | `ToolRegistry::run()` | buildToolContext() method | VERIFIED | `$this->toolRegistry->run('web_search', ...)` at line 110, `$this->toolRegistry->run('paper_search', ...)` at line 125 |
| `WebSearch::execute()` | `HttpHelper::get()` | HTTP GET to Brave Search API | VERIFIED | `$this->http->get($url, $headers)` at line 79 |
| `AcademicSearch::queryArxiv()` | `HttpHelper::get()` | HTTP GET to arXiv API | VERIFIED | `$this->http->get($url, ['Accept: application/atom+xml'])` at line 101 |
| `AcademicSearch::querySemanticScholar()` | `HttpHelper::get()` | HTTP GET to Semantic Scholar | VERIFIED | `$this->http->get($url, $headers)` at line 208 |
| `AgentManager::discoverAgents()` | `config/agents/*/config.json` | glob() scan | VERIFIED | `glob($pattern)` at line 66 |
| `AgentManager::research()` | `ResearchAgent::research()` | Fresh ResearchAgent per call | VERIFIED | `$agent = new ResearchAgent(...)` at line 174 |
| `research.php` | `AgentManager::research()` | CLI dispatch | VERIFIED | `$agentManager->research($question, $correlationId)` at line 48 |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|-------------------|--------|
| `WebSearch::execute()` | `$this->config['api_key']` | Agent config.json brave_api_key | Config-driven, passes through to HTTP header | FLOWING |
| `AcademicSearch::execute()` | query results | arXiv API via HttpHelper::get() + SimpleXMLElement | Both APIs queried independently, merged, deduplicated | FLOWING |
| `ResearchAgent::buildToolContext()` | tool output strings | ToolRegistry::run() -> WebSearch/AcademicSearch -> HttpHelper | Formatted context block prepended to system prompt | FLOWING |
| `ResearchAgent::research()` | system message | SOUL.md + buildToolContext() | Tool context joined with soul into single message string | FLOWING |
| `LlmClient::chat()` | API response | HttpHelper::post() to provider | Actual HTTP response from LLM provider API | FLOWING |
| `AgentManager::research()` | `$results[$agentName]` | ResearchAgent::research() per agent | Collected into array keyed by agent name | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Autoloader resolves all Phase 2 classes | `php -r "require 'src/bootstrap.php'; echo class_exists('App\\\Http\\\HttpHelper');"` | All 12 classes load without error | PASS |
| PHP syntax all modified files | `php -l` on 10 files | All 10 pass syntax check | PASS |
| bin/check-config runs and validates | `php bin/check-config` | 3/4 checks pass. api_key=empty triggers expected FAIL for gitignored config | PASS (expected config validation works) |
| ToolRegistry registers and validates | Autoload + ToolRegistry constructor/create | Classes instantiate without error | PASS |
| WebSearch loads and validates | `WebSearch` with empty api_key | Constructor accepts, execute() throws RuntimeException for missing key | PASS |

### Probe Execution

No probes declared for Phase 2. Skipped.

### Requirements Coverage

| Req ID | Source Plan | Description | Status | Evidence |
|--------|-----------|-------------|--------|----------|
| CONF-08 | 02-03-PLAN | AgentManager discovers agents by scanning config directories at runtime | SATISFIED | `AgentManager::discoverAgents()` globs `config/agents/*/config.json`, validates config, sorted alphabetically |
| TOOL-02 | 02-02-PLAN | Agent can perform web searches via configurable search API provider | SATISFIED | `WebSearch` with Brave Search API, config-driven api_key, formatted context block output |
| TOOL-03 | 02-02-PLAN | Agent can search arXiv API for scientific papers | SATISFIED | `AcademicSearch::queryArxiv()` with Atom XML via SimpleXMLElement, proper namespace handling |
| TOOL-04 | 02-02-PLAN | Agent can search Semantic Scholar API for scientific papers | SATISFIED | `AcademicSearch::querySemanticScholar()` with standard /paper/search endpoint, optional API key for rate limits |
| TOOL-05 | 02-01-PLAN | LlmClient abstracts LLM API calls with model/provider selection | SATISFIED | `LlmClient` reads base_url, api_key, model from config. `ResearchAgent::resolveBaseUrl()` supports deepseek/openrouter + provider_base_url override |
| TOOL-06 | 02-02-PLAN | WebSearch tool has provider abstraction for swapping search APIs | SATISFIED | `WebSearch` configured via $config array (api_key, count). Provider type switch via config -- future providers add their own handler |
| TOOL-07 | 02-02-PLAN | PaperSearch tool wraps arXiv and Semantic Scholar endpoints | SATISFIED | `AcademicSearch` calls both APIs, merges results, deduplicates by arXiv ID/DOI, sorts by year/citations |
| TOOL-08 | 02-01-PLAN | API response timeouts enforced at HTTP socket level | SATISFIED | `HttpHelper` enforces CURLOPT_TIMEOUT=60, CURLOPT_CONNECTTIMEOUT=10 on all external HTTP calls |

All 8 Phase 2 requirement IDs are SATISFIED. No orphaned requirements found.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `src/Agent/AgentManager.php` | 184 | Missing per-agent error isolation | BLOCKER | `$agent->research($question)` not wrapped in try/catch. One agent exception (from any cause) blocks all subsequent agents. Violates D-03 and T-02-06. |

No TBD, FIXME, or XXX debt markers found in any file. No stub patterns detected (all artifacts contain substantive implementations).

### Human Verification Required

1. **Authenticated API test with tools**
   **Test:** Set real API keys (api_key for LLM provider, brave_api_key for Brave Search), then run `php research.php "What are the latest advances in transformer architectures?"`
   **Expected:** All agents produce answers. If tools are enabled in preferences.json (web_search: true, paper_search: true), the answers should reference external web and academic sources. Output shows per-agent sections with model metadata.
   **Why human:** Requires valid API keys. Tool results require live Brave Search and ArXiv/Semantic Scholar API calls.

2. **Visual output format review**
   **Test:** Run with tools enabled and inspect output format.
   **Expected:** Each agent answer shows tool-informed content. The output format is: `=== Agent: {name} ===`, answer text, `---`, model metadata line, then `== Research Complete ==` summary.
   **Why human:** Visual formatting quality and tool context injection require human judgment.

3. **Multi-agent test (config validation)**
   **Test:** Create a second agent directory under `config/agents/` with a different model/provider, ensure both have valid config files, then run `php research.php "test"`.
   **Expected:** Both agents are discovered and produce answers. `== Research Complete ==` summary shows "Agents: 2".
   **Why human:** Requires manual setup of a second agent directory with valid config.

### Gaps Summary

**One blocking gap found:**

**Error isolation missing in AgentManager::research() (D-03 / T-02-06):** The AgentManager research loop does not wrap `$agent->research($question)` in a per-agent try/catch. If one agent throws an exception (e.g., LlmException from an API failure, or any RuntimeException from ResearchAgent construction), the exception propagates out of the foreach loop and blocks all remaining agents from executing.

The PLAN explicitly requires this:
- D-03: "AgentManager returns array of agent answers -- same structure as ResearchAgent output, wrapped per agent."
- T-02-06 threat mitigation: "Per-agent try/catch. One agent failure never blocks others. Exceptions caught and wrapped."
- Task 1 behavior: "One agent failure does not block others"

The `configureTools()` method (lines 209-258) properly uses per-tool try/catch, but the outer `research()` method is missing the same pattern for the agent execution itself.

**Fix:** Wrap `$agent->research($question)` in try/catch. On failure, store error result (including agent name, error message) and `continue` to the next agent, collecting partial results from all agents that succeeded.

### Deferred Items

No deferred items identified. This gap is not addressed by any later phase's goals or success criteria in the current roadmap.

---

_Verified: 2026-06-13T19:00:00Z_
_Verifier: Claude (gsd-verifier)_
