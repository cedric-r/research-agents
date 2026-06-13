# Phase 2: Agent Runtime & Tool Integration - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-13
**Phase:** 2-agent-runtime-tool-integration
**Areas discussed:** AgentManager design, Web search + tool pattern, Academic search format, LlmClient provider model

---

## AgentManager Design

| Option | Description | Selected |
|--------|-------------|----------|
| Scan directory at startup | On instantiation, scan config/agents/*/ for config.json, instantiate one ResearchAgent per directory | ✓ |
| Config-driven registration | Explicit list of agent names in arbitrator config.json | |
| New instance per query | AgentManager creates a ResearchAgent per configured agent when research() called | ✓ |
| Singleton per agent | One ResearchAgent instance created at startup, reused across calls | |
| Array of agent answers | Returns [][agent_name => answer, model, time, tokens] | ✓ |
| Single best answer | Runs all agents, returns only the best one | |
| src/Agent/ namespace | AgentManager.php alongside ResearchAgent.php | ✓ |
| src/Manager/ or src/Core/ | Separate namespace for orchestration classes | |

**User's choice:** Scan directory at startup, new instance per query, returns array, src/Agent/
**Notes:** Clean state per query preferred. No singleton reuse.

---

## Web Search + Tool Pattern

| Option | Description | Selected |
|--------|-------------|----------|
| Brave Search | Free tier. Simple API key header auth | ✓ |
| SerpAPI | Google results via API. CLAUDE.md recommended | |
| Tavily (IVAN-AI) | Purpose-built for AI agents. Paid API | |
| ToolRegistry pattern | Register tools with name + handler + schema. Agent calls run_tool() | ✓ |
| Direct methods on Agent | Add webSearch(), arxivSearch() directly on ResearchAgent | |
| Inject as context block | Tool results formatted and appended to system prompt before user question | ✓ |
| Return structured to agent | Let agent decide how to incorporate results | |
| src/Tool/ namespace | New src/Tool/ directory with ToolRegistry.php + tool classes | ✓ |
| Inside src/Agent/ | Keep tool logic in agent namespace | |

**User's choice:** Brave Search, ToolRegistry pattern, context block injection, src/Tool/ namespace
**Notes:** Brave preferred over SerpAPI for simplicity and free tier.

---

## Academic Search Format

| Option | Description | Selected |
|--------|-------------|----------|
| Full detail | Title, authors, abstract (~300 chars), URL, date, DOI | ✓ |
| Minimal | Title + URL only | |
| One AcademicSearch tool | Queries both arXiv and Semantic Scholar, returns merged results | ✓ |
| Separate tools | Each as its own registered tool | |

**User's choice:** Full detail results, one combined AcademicSearch tool
**Notes:** Single tool simpler for agent to call. Merged results avoid duplicates between arXiv and Semantic Scholar.

---

## LlmClient Provider Model

| Option | Description | Selected |
|--------|-------------|----------|
| Config-driven switching | Single LlmClient reads provider + model from config per request | ✓ |
| Separate adapter classes | Each provider gets its own class extending a base | |
| Centralized HttpHelper | Utility all tools + LlmClient use for HTTP calls. Single timeout config | ✓ |
| Per-tool curl config | Each tool sets its own curl opts | |

**User's choice:** Config-driven switching, centralized HttpHelper
**Notes:** HttpHelper prevents hanging APIs being missed when adding new tools in future phases.

---

## Claude's Discretion

- Exact tool result formatting in context block
- AgentManager constructor signature and ResearchAgent instantiation details
- HttpHelper class name and internal curl_multi_exec support
- Error handling specifics per tool

## Deferred Ideas

None — discussion stayed within phase scope.
