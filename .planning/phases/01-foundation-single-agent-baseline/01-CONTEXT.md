# Phase 1: Foundation & Single-Agent Baseline - Context

**Gathered:** 2026-06-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Config loading, logging infrastructure, and a single agent that can answer a research question via LLM call. Validates the core value proposition before multi-agent complexity. No multi-agent orchestration, no debate, no web search — Phase 1 proves the system can load configs, talk to an LLM, and produce a useful answer.

</domain>

<decisions>
## Implementation Decisions

### Config File Format
- **D-01:** Config files support JSON and PHP array formats. JSON for config.json, preferences.json. PHP arrays also accepted. Loader detects format by file extension.
- **D-02:** Config loader parses JSON via json_decode with strict validation. PHP arrays loaded via include.
- **D-03:** Each agent gets its own directory under `config/agents/{name}/` containing config.json, SOUL.md, and preferences.json.

### LLM API Client
- **D-04:** Single LlmClient class with provider adapter pattern. Provider differences (base URLs, auth header formats, response shapes) handled via config, not separate classes per provider.
- **D-05:** Uses PHP curl directly (no HTTP library dependency) — curl handles all provider API calls.
- **D-06:** Provider config specifies: `provider` (deepseek, openrouter), `model`, `api_key`.

### CLI Entry Point
- **D-07:** Flat script `research.php` at project root for v1. Single entry point, simple invocation: `php research.php "your question"`.
- **D-08:** Phase 1 supports direct question argument. Interactive REPL comes in Phase 5.

### SOUL.md Format
- **D-09:** Structured markdown sections: `### Identity` (who the agent is), `### Values` (principles guiding decisions), `### Instructions` (how to research and respond), `### Constraints` (limitations, boundaries).
- **D-10:** SOUL.md is read and used as the system prompt for LLM calls. Sections are concatenated into the system message.

### Logging Format
- **D-11:** Human-readable formatted text log. Console output shows timestamp, channel, level, and message in readable format.
- **D-12:** Log channels separated (agent, system). Phase 1 uses a single log file with channel prefix.
- **D-13:** Per-session log files deferred to Phase 5 — Phase 1 uses a single application log.

- **D-14:** API keys stored directly in config.json. Config files should be gitignored for local use.
- **D-15:** LLM response output formatted with metadata — model name, response time, token count alongside the answer.

### Claude's Discretion
- Directory structure beyond config layout — planner chooses src/ organization
- Error handling specifics — planner decides retry, error display approach
- Exact config schema fields beyond provider/model/key — planner extends as needed

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Context
- `.planning/PROJECT.md` — Project overview, core value, requirements, constraints
- `.planning/REQUIREMENTS.md` — Full v1 requirements with traceability
- `.planning/ROADMAP.md` — Phase structure, dependencies, success criteria

### Phase 1 Requirements
- `.planning/REQUIREMENTS.md` § Agent Configuration — CONF-01 through CONF-07
- `.planning/REQUIREMENTS.md` § Research Tools — TOOL-01 (LLM knowledge via API)
- `.planning/REQUIREMENTS.md` § Logging — LOG-01, LOG-02

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- No existing PHP code — greenfield project.

### Established Patterns
- Research recommended Vanilla PHP (no framework). Confirmed during project setup.

### Integration Points
- Phase 1 produces foundation that Phase 2 (AgentManager, LlmClient) extends.
- No existing system to integrate with.

</code_context>

<specifics>
## Specific Ideas

- Use PHP's built-in curl functions (curl_init, curl_setopt, curl_exec) — no Guzzle or HTTP client library.
- Config validation should report all missing fields at once (not fail on first missing field).
- The single agent should demonstrate the config loading → LLM call → answer display pipeline end-to-end.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 1-Foundation & Single-Agent Baseline*
*Context gathered: 2026-06-13*
