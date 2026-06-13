# Phase 2: Agent Runtime & Tool Integration - Context

**Gathered:** 2026-06-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Extend single-agent baseline with AgentManager for multi-agent discovery, web search (Brave Search), scientific paper search (arXiv + Semantic Scholar), centralized HTTP timeout enforcement, and config-driven provider switching in LlmClient. AgentManager manages multiple ResearchAgent instances. Tools feed results as context blocks into LLM prompts.

</domain>

<decisions>
## Implementation Decisions

### AgentManager
- **D-01:** AgentManager discovers agents by scanning `config/agents/*/` for config.json at startup. No explicit registration list.
- **D-02:** AgentManager creates a fresh ResearchAgent instance per `research()` call. Clean state per query.
- **D-03:** AgentManager returns array of agent answers — same structure as ResearchAgent output, wrapped per agent.
- **D-04:** AgentManager lives in `src/Agent/` namespace alongside ResearchAgent.

### Web Search
- **D-05:** Brave Search API as web search provider. API key configured per-agent in config.json.
- **D-06:** Tool results injected as context block in system prompt before user message, not returned as structured data to agent.

### Tool Abstraction
- **D-07:** ToolRegistry pattern — tools registered by name + handler + schema. ResearchAgent calls `run_tool(name, params)`.
- **D-08:** ToolRegistry and all tool classes live in `src/Tool/` namespace (new directory).
- **D-09:** Academic search is one combined AcademicSearch tool querying both arXiv (Atom XML via SimpleXMLElement) and Semantic Scholar (REST JSON). Returns merged, deduplicated results.

### Academic Search Format
- **D-10:** Full detail results — title, authors, abstract (~300 chars truncated), URL, published date, DOI/citation count when available.

### LlmClient Provider Model
- **D-11:** Config-driven provider switching per D-04 pattern. Single LlmClient class reads provider + model from agent config per request. Provider-specific behavior via config (base URL, auth header format, model name).

### HTTP Timeouts
- **D-12:** Centralized HttpHelper utility for all external HTTP calls (LLM API, Brave Search, arXiv, Semantic Scholar). Enforces CURLOPT_TIMEOUT=60s, CURLOPT_CONNECTTIMEOUT=10s. Single config point for all timeouts.

### Claude's Discretion
- Exact tool result formatting in context block — planner designs schema
- AgentManager constructor signature and ResearchAgent instantiation details
- HttpHelper class name and internal curl_multi_exec support (future-proofing for Phase 3 parallel agents)
- Error handling specifics per tool (retry, fallback, partial failure)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Context
- `.planning/PROJECT.md` — Project overview, core value, requirements, constraints
- `.planning/REQUIREMENTS.md` — Full v1 requirements with traceability
- `.planning/ROADMAP.md` — Phase structure, dependencies, success criteria

### Phase 1 Decisions (inherited)
- `.planning/phases/01-foundation-single-agent-baseline/01-CONTEXT.md` — D-04 (single LlmClient with provider adapter), D-07 (flat CLI entry point), D-11/D-15 (logging + output format)

### Phase 2 Requirements
- `.planning/REQUIREMENTS.md` § Agent Configuration — CONF-08
- `.planning/REQUIREMENTS.md` § Research Tools — TOOL-02 through TOOL-08

### Existing Implementation
- `src/Agent/ResearchAgent.php` — AgentManager manages instances of this class
- `src/LlmClient/LlmClient.php` — Extended for config-driven provider switching
- `src/Config/Loader.php` — Tool configs loaded via existing loader
- `config/agents/researcher/config.json` — Existing agent config, model for new tool configs

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- Config/Loader with aggregate validation — tool config files loaded via same pattern
- Logger with channels — tool activity logged to `tool` channel
- LlmClient HTTP timeout pattern (CURLOPT_TIMEOUT=60, CURLOPT_CONNECTTIMEOUT=10) — template for centralized HttpHelper

### Established Patterns
- No external dependencies (vanilla PHP) — tools use curl + SimpleXMLElement directly
- Config per directory under `config/` — each tool gets its own config file
- ResearchAgent loads config + SOUL.md at construction — AgentManager follows same pattern

### Integration Points
- AgentManager → instantiates ResearchAgent per configured agent
- ResearchAgent → calls ToolRegistry → registered tools
- ToolRegistry → tools use centralized HttpHelper for HTTP calls
- Tool results → injected as context block in system prompt before user message

</code_context>

<specifics>
## Specific Ideas

- Brave Search JSON API format: `https://api.search.brave.com/res/v1/web/search?q=...` with `Accept: application/json` and `X-Subscription-Token: {key}` headers
- arXiv API: `http://export.arxiv.org/api/query?search_query=...&start=0&max_results=5` — Atom XML parsed with SimpleXMLElement
- Semantic Scholar API: `https://api.semanticscholar.org/graph/v1/paper/search?query=...&limit=5` — JSON response
- Tool result in context block: formatted as "## Web Search Results\n{title}: {snippet}\n---\n..." prepended to system message
- LlmClient provider config extended: add `provider_base_url` and `provider_model_name` fields alongside existing `provider` and `model`

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 2-Agent Runtime & Tool Integration*
*Context gathered: 2026-06-13*
