# Project Research Summary

**Project:** ResearchAgents
**Domain:** Multi-agent research and debate system (Vanilla PHP)
**Researched:** 2026-06-13
**Confidence:** HIGH

## Executive Summary

ResearchAgents is a multi-agent research and debate system that distributes research questions to multiple LLM agents (configured per directory), runs a structured 2-round debate (independent research followed by peer critique), and selects the best answer through a non-participating Arbitrator. The system is designed as a single-user CLI/Web tool with file-based persistence -- no database, no framework, no external runtime beyond PHP 8.3+. The research surveyed 10+ production systems (AutoGen, CrewAI, ChatDev, Magentic-One, Hermes Council) and academic frameworks (ChatEval, D3, Agent4Debate, ARGUS) to validate the architecture and feature set.

The recommended approach is **zero-dependency Vanilla PHP** for v1. All required capabilities -- concurrent HTTP (`curl_multi_exec`), process forking (`pcntl_fork`), CLI REPL (`readline`), web server (`php -S`), and file I/O -- are available in the standard PHP 8.5.4 runtime. The architecture uses a **layered controller pattern** where the Arbitrator owns orchestration timing and the Debate namespace owns protocol structure, with an Observer pattern for streaming progress to CLI, Web, and session storage simultaneously. Agents are config-driven: each agent directory contains `config.json`, `SOUL.md` (personality), and `preferences.json`, with API keys resolved from environment variables.

**Key risks and mitigations:** (1) Over-engineering multi-agent when a single agent suffices -- mitigate by building and measuring a single-agent baseline first (Phase 1). (2) Debate echo chambers amplifying errors -- mitigate by using diverse LLM providers, independent Round 1 before any peer exposure, and limiting debates to 2 rounds. (3) Cascading error propagation between agents -- mitigate by enforcing typed JSON schemas at every agent handoff boundary with output validation. (4) PHP process management pitfalls -- mitigate by using in-process sequential execution (v1) and curl_multi_exec for I/O concurrency, avoiding raw pcntl_fork until proven necessary.

## Key Findings

### Recommended Stack

Vanilla PHP 8.3+ (runtime 8.5.4) with bundled extensions only. The system requires zero external dependencies for v1 -- all HTTP, CLI, process management, and file I/O needs are met by PHP's standard library. Two optional Composer packages are identified for v1.x growth: `utopia-php/agents` (lightweight LLM provider abstraction) and `monolog/monolog` (structured logging). See [STACK.md](STACK.md) for full details.

**Core technologies:**
- **PHP 8.3+ (8.5.4 runtime)**: Application language -- zero framework overhead, built-in web server, fibers, readline, pcntl, curl. No compilation or JVM needed.
- **ext-curl / curl_multi_exec**: HTTP transport for all API calls (LLM, web search, academic APIs) -- concurrent HTTP without external libraries.
- **ext-readline**: CLI REPL input -- `readline()`, `readline_add_history()`, `readline_completion_function()` for rich interactive CLI.
- **ext-pcntl + ext-posix**: Process forking for parallel agent execution -- OS-level parallelism in vanilla PHP when needed.
- **PHP built-in web server (`php -S`)**: Web REPL interface -- zero-dependency serving for single-user interactive use.
- **PHP arrays (config files)**: Structured configuration -- loaded via `include`, zero parsing overhead, type-safe.
- **SimpleXMLElement**: arXiv Atom XML parsing -- no XML parser dependency needed.

**Supporting libraries (optional, v1.x):**
- `utopia-php/agents ^2.1`: LLM API abstraction for multiple providers -- only if managing 3+ providers becomes tedious.
- `monolog/monolog ^3.10`: Structured logging with file rotation and multiple handlers -- only if the custom Logger (70 lines) is insufficient.
- `phpunit/phpunit ^12.0`: Testing framework (dev dependency).

### Expected Features

The system has a clear set of table-stakes features for any multi-agent research tool, plus differentiators that set it apart from generic frameworks like AutoGen or CrewAI. See [FEATURES.md](FEATURES.md) for full matrix.

**Must have (table stakes):**
- Agent configuration (model, provider, API key) -- per-directory config
- Agent personality/role system -- SOUL.md files drive behavior distinctiveness
- Arbitrator distributes question to all agents -- core job distribution
- Per-step timeout enforcement -- agents must not hang indefinitely
- Multi-round debate protocol -- 2-round minimum (independent + critique)
- Result selection by arbitrator with reasoning trace
- Session persistence (file-based markdown)
- Timestamped activity logging with correlation IDs
- Basic web search tool integration
- CLI interface (REPL with history)

**Should have (competitive differentiators):**
- Structured debate with peer critique -- more structured than AutoGen GroupChat
- SOUL.md personality system -- richer than simple system prompts
- Scientific paper search (arXiv, Semantic Scholar) -- unavailable in general-purpose systems
- Reasoned selection with full trace -- arbitrator explains WHY one answer won
- Dual interface (CLI + Web REPL) -- serves both developers and end-users
- File-based per-agent configuration -- more portable than code-based config
- Debate transparency (see all peer answers) -- enables richer critique
- Arbitrator-as-moderator (not participant) -- reduces bias in selection

**Defer (v1.x / v2+):**
- Persistent database (PostgreSQL, SQLite) -- grep across session files is sufficient for v1
- User authentication / multi-user -- single-user system per requirements
- Scheduled/automated research -- orthogonal to core interactive value
- Plugin system for custom agent capabilities -- capabilities defined in config/personality
- N-round configurable debate (beyond 2) -- diminishing returns documented in D3 paper
- Visual workflow designer -- massive frontend project, does not help core research quality
- Custom consensus policies -- arbitrator decides, consensus is implicit

### Architecture Approach

The system follows a **layered controller pattern** with explicit round-based orchestration. The Arbitrator acts as a workflow controller that executes a fixed protocol: Round 1 (distribute question, collect independent answers) -> Round 2 (share all answers, collect peer critiques) -> Evaluate (select best answer with reasoning). This is distinct from pub/sub or freeform conversation models used by AutoGen GroupChat. The Observer pattern decouples the workflow from presentation: the same Arbitrator events feed CLI display, Web SSE streams, and session transcript simultaneously. All agents run in-process sequentially in v1, with a cooperative timeout architecture (agent checks deadline at yield points) backed by hard HTTP socket timeouts. See [ARCHITECTURE.md](ARCHITECTURE.md) for full diagrams and data flow.

**Major components:**
1. **Arbitrator** -- Orchestrates research workflow: discovers agents, distributes questions, enforces timeouts, manages rounds, evaluates and selects best answer. Does not participate as a debater.
2. **Agent** -- Researches question using LLM + optional tools (web search, paper search), produces structured answers, critiques peers in debate rounds. Config-driven from per-directory files.
3. **SessionManager** -- Creates session (UUID + timestamp), coordinates progress streaming via Observer pattern, writes final transcript to markdown file. Wraps the Arbitrator.
4. **CLI REPL** -- `readline()` loop with history, command dispatch, real-time progress display via ANSI cursor control.
5. **Web REPL** -- PHP built-in server with SSE streaming. Background process pattern: POST spawns research, SSE endpoint polls events file.
6. **Debate/RoundController** -- Orchestrates a single debate round. Separated from Arbitrator so debate protocol can change without touching orchestration.
7. **Tools (LlmClient, WebSearch, PaperSearch)** -- HTTP API callers for LLM providers and search APIs. Search abstraction layer enables provider swapping.
8. **Config/Loader** -- Generic JSON + environment variable config loader with `env-var` substitution pattern.
9. **Log/Logger** -- PSR-3-like structured logger with date-rotated file output and per-channel separation.

### Critical Pitfalls

Top 5 pitfalls that must be addressed in the architecture. See [PITFALLS.md](PITFALLS.md) for all 13 documented pitfalls with recovery strategies.

1. **Over-engineering multi-agent when a single agent suffices** -- Teams invest in complex multi-agent orchestration only to find a well-prompted single agent matches or exceeds results. **Mitigation:** Build and benchmark a single-agent baseline in Phase 1. Only add agents when you can measure a clear quality improvement. Decompose by context boundaries, not by work type.

2. **Echo chambers and harmful conformity in debate** -- Multi-agent debate rounds can amplify errors rather than correct them. Research shows 29% of stance flips in debate are conformity, and 57-77% of those are harmful (correct-to-wrong). **Mitigation:** Use diverse LLM providers across agents, conduct independent Round 1 before any peer exposure, limit to 2 rounds, consider a contrarian "devil's advocate" agent.

3. **Timeout gaps (agents hang forever)** -- Agents may receive all tool results but never generate the next message, or an API stream drops mid-response. Documented in production systems with four compounding timeout gaps. **Mitigation:** Implement 4-layer timeout architecture: PHP max execution time, HTTP socket timeout (per API call), stream-idle watchdog (per-chunk), and cooperative agent-step deadline with partial result preservation.

4. **No structured handoffs between agents** -- Unstructured natural language exchange creates a "telephone game" where meaning degrades with each hop. Critical metadata (citations, confidence, sources) is lost in prose. **Mitigation:** Define typed JSON schemas for every agent boundary. Treat handoffs like API contracts: validate, version, break loudly on mismatch. Pass structured metadata alongside content, not embedded in it.

5. **PHP process management (zombies, blocking I/O, memory leaks)** -- pcntl_fork creates zombie processes, memory leaks, and blocking I/O bottlenecks. Forked children inherit corrupted connections. **Mitigation:** Start with in-process sequential execution for v1. Use curl_multi_exec for I/O concurrency (not pcntl_fork). Only add subprocesses if true parallelism or memory isolation is proven necessary.

## Implications for Roadmap

Based on combined research across stack, features, architecture, and pitfalls, the project should be built in 5 phases. Each phase has a clear dependency relationship with the next, and each avoids specific pitfalls identified in research.

### Phase 1: Foundation and Single-Agent Baseline
**Rationale:** Everything depends on config loading and logging. The single-agent baseline empirically establishes whether multi-agent is even necessary -- the most critical decision in the entire project.
**Delivers:** Config loader (JSON + env-var substitution), structured logger, single ResearchAgent that can answer a question via one LLM call, config validation health check (`php bin/check-config`).
**Addresses features:** Agent configuration, SOUL.md personality, LLM research, timestamped logging.
**Avoids pitfalls:** Over-engineering multi-agent (P1), Configuration management sprawl (P13).
**Standard patterns:** Config loading and logging are well-documented PHP patterns. Skip research-phase -- proceed directly to implementation.

### Phase 2: Agent Runtime and Tool Integration
**Rationale:** Agents need tools (web search, paper search) before they can produce quality research. The concurrency model must be chosen before tools are built -- switching from sequential to concurrent later means rewriting every agent.
**Delivers:** LlmClient (LLM API caller with streaming support), WebSearch (provider abstraction with caching), PaperSearch (arXiv + Semantic Scholar), AgentManager (filesystem agent discovery), AgentInterface contract.
**Addresses features:** Agent configuration, web search, paper search, timeout enforcement, tool preferences.
**Avoids pitfalls:** PHP process management (P6), API rate limiting (P8), Web search cost/reliability blind spots (P12).
**Research flag:** Phase 2 needs deeper research on search provider selection (Tavily vs SerpAPI vs DuckDuckGo vs Brave), cost modeling per session, and caching strategy. Recommend `/gsd-plan-phase --research-phase 2` during planning.

### Phase 3: Orchestration Pipeline and Structured Handoffs
**Rationale:** The Arbitrator needs working agents before it can orchestrate them. The handoff protocol and timeout architecture must be designed together -- they are the backbone of the system.
**Delivers:** Arbitrator (Round 1 orchestration), Debate/RoundController (Round 2 critique), Result value objects with typed schemas, 4-layer timeout architecture, output validation at every agent boundary.
**Addresses features:** Full arbitrator lifecycle (distribute, evaluate, facilitate, select), debate participation.
**Avoids pitfalls:** Cascading error propagation (P3), No structured handoffs (P4), Timeout gaps (P5), Agent-to-agent prompt injection (P7).
**Research flag:** Phase 3 needs deeper research on structured handoff schema design and LLM streaming behavior differences across providers. Recommend `/gsd-plan-phase --research-phase 3`.

### Phase 4: Debate System and Echo Chamber Prevention
**Rationale:** The debate protocol must be built on top of the orchestration pipeline. Echo chamber prevention must be designed into the protocol from day one.
**Delivers:** Refined 2-round debate protocol, diverse agent configuration recommendations, Round 0 confidence scoring, context pruning strategy, devil's advocate agent template.
**Addresses features:** Evaluate Round 1, facilitate Round 2, select best answer, reasoning trace.
**Avoids pitfalls:** Echo chambers (P2), Unbounded token consumption (P9).
**Research flag:** Phase 4 requires the deepest research -- debate prompt engineering, conformity detection heuristics, context pruning strategies, and token budget modeling. Recommend `/gsd-plan-phase --research-phase 4` with priority.

### Phase 5: Storage, Sessions, and Presentation Layer
**Rationale:** Storage and presentation depend on data from the workflow. Building the UI before the workflow is complete causes backtracking.
**Delivers:** SessionManager (session lifecycle), Transcript (markdown builder), CLI REPL (readline loop), Web REPL (router, SSE controller), file locking, log rotation.
**Addresses features:** CLI REPL, Web REPL, session persistence, timestamped logging.
**Avoids pitfalls:** Session file corruption (P10), Logging imbalance (P11).
**Standard patterns:** CLI REPL, file I/O, and basic web server patterns are well-documented. Skip research-phase for CLI and file storage; consider research-phase for Web REPL SSE architecture.

### Phase Ordering Rationale

- **Phases 1-2 must come before 3-4:** Agents must exist before the Arbitrator can orchestrate. The single-agent baseline (Phase 1) validates the core value proposition.
- **Phase 3 must come before Phase 4:** The orchestration pipeline (arbitration, timeouts, handoffs) is the foundation for the debate protocol.
- **Phase 5 should come last:** Storage and UI depend on the data shape produced by the workflow. Building transcript format before workflow is complete causes rework.
- **Pitfall prevention is front-loaded:** The most damaging pitfalls (over-engineering, process management, timeouts, handoffs) are addressed in Phases 1-3.
- **Research depth increases with phases:** Phases 1-2 use well-established patterns. Phases 3-4 require deeper research.

### Research Flags

Phases likely needing deeper research during planning:
- **Phase 2:** Search provider selection and cost modeling -- provider landscape is volatile (Google CSE shutting down 2027, SerpAPI legal issues).
- **Phase 3:** Structured handoff schema design -- specific JSON schema for agent outputs. LLM streaming behavior across providers for stream-idle watchdog timing.
- **Phase 4:** Debate prompt engineering, conformity detection heuristics, context pruning strategies, token budget modeling -- most research-intensive phase.

Phases with standard patterns (skip research-phase):
- **Phase 1:** Config loading, logging, basic PHP file organization.
- **Phase 5:** CLI REPL with readline, file I/O with flock. Web REPL SSE may benefit from light research.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All technologies verified on runtime (PHP 8.5.4). Extensions confirmed. Primary sources: PHP manual, Packagist. |
| Features | HIGH | Verified against 10+ production systems and academic papers. Feature matrix cross-referenced. |
| Architecture | HIGH | Based on published multi-agent framework architectures and PHP patterns documentation. |
| Pitfalls | HIGH | Research-backed with specific citations. Cross-verified across industry and academic sources. |

**Overall confidence:** HIGH

### Gaps to Address

- **Phase 2 search provider selection:** Current cost data and availability guarantees needed for initial provider decision. Abstraction layer mitigates lock-in risk.
- **Phase 3 handoff schema design:** Exact schema fields for agent outputs must be designed during implementation based on what information agents produce.
- **Phase 4 conformity detection:** No turnkey detection algorithm available. Needs heuristic development (inter-agent agreement ratios, confidence divergence tracking).
- **Debate prompt structure:** Exact prompt templates need empirical tuning for this specific system. Not a research gap but an implementation concern.

## Sources

See individual research files for full source lists:

- [STACK.md](STACK.md) -- Packagist, PHP Manual, arXiv API docs, Semantic Scholar API docs
- [FEATURES.md](FEATURES.md) -- AutoGen v0.4, CrewAI comparison, ChatDev (ACL 2024), Magentic-One (arXiv 2411.04468), MALLM (ACL 2025), ChatEval (arXiv 2308.07201), D3 (EACL 2026)
- [ARCHITECTURE.md](ARCHITECTURE.md) -- Hermes Council, AutoGen debate pattern, Agent4Debate (ICASSP 2026), AgentScope, ARGUS, Soul Agent Framework
- [PITFALLS.md](PITFALLS.md) -- UC Berkeley arXiv 2503.13657, Anthropic blog (2025), GitHub Engineering (2025), Kim & Torr arXiv 2512.23518, Lee & Tiwari arXiv 2410.07283, GitHub Issue #4173, PHP Bug #51314

---
*Research completed: 2026-06-13*
*Ready for roadmap: yes*
