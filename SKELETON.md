# Walking Skeleton — ResearchAgents

**Phase:** 1
**Generated:** 2026-06-13

## Capability Proven End-to-End

> A user can run `php research.php "question"` from the command line and receive a research answer produced by an LLM agent configured with a provider model, API key, and personality. The answer includes metadata (model name, response time, token usage) and all operations are logged with timestamps and correlation IDs.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Vanilla PHP (no Laravel/Symfony) | All needed capabilities (curl, JSON, file I/O, argv) are built into PHP 8.5.4. A framework adds 1000s of files for zero benefit in a CLI-first, single-user research tool. |
| Dependency strategy | Zero external dependencies for Phase 1. No Composer. | PHP 8.5.4 ships with all extensions needed: curl, json, mbstring, readline, pcntl, posix, sockets. Adding packages before Layer 1 is validated adds risk without proven need. Packages deferred per known pain points. |
| Config format | JSON default (config.json, preferences.json). PHP arrays also accepted (detected by .php extension). | JSON is universal, readable, and toolable. PHP arrays provide zero-parse-overhead alternative for users who prefer them. Loader detects format by file extension per D-01. |
| Autoloading | PSR-4-like via spl_autoload_register. App\ namespace maps to src/. | Zero-dependency autoloading. Every modern PHP project needs autoloading to avoid require_once chains. PHP built-in spl_autoload_register is reliable and well-documented. |
| Logging | Custom Logger class (~70 lines). Human-readable formatted text with channel prefix. Single application log for Phase 1. | Bundled into the zero-dependency approach. Monolog adds value at scale (rotation, structured output, multiple handlers) but is premature for Phase 1's single-file, single-process use case. |
| LLM access | Single LlmClient class with provider adapter pattern. Provider differences (base URL, auth, model ID) handled via config values, not separate classes. | Avoids class explosion (DeepseekClient, OpenRouterClient, etc.) when adding providers. All OpenAI-compatible providers use POST /chat/completions with Bearer auth — only base URL and model name differ. |
| CLI entry point | Flat script research.php at project root. Direct question argument only. | Simplest possible user-facing interface. Interactive REPL deferred to Phase 5 per D-08. Keeping it flat prevents over-engineering the entry point. |
| SOUL.md format | Structured markdown sections: ### Identity, ### Values, ### Instructions, ### Constraints. Concatenated as system prompt. | Structured sections give the personality prompt clear semantics without needing a parser. Sections are concatenated as-is into the LLM system message per D-10. |
| Log format | `[2026-06-13 14:30:00.123456] [CHANNEL] [LEVEL] [correlation_id] message {context}` | Simple, parsable, grep-friendly. Channels (SYSTEM, AGENT) enable log filtering without separate files. Microsecond precision for debugging timing issues. |
| Directory layout | src/ organized by domain (Config/, Log/, LlmClient/, Agent/). Config/ with per-agent directories. Bin/ for utility scripts. | Domain-driven package layout prevents circular dependencies and makes it clear where new code belongs. Flat config/agents/ enables agent scanning in Phase 2. |

## Stack Touched in Phase 1

- [x] Project scaffold — directory structure, SKELETON.md, .gitignore
- [x] Routing — research.php CLI entry point (`php research.php "question"`)
- [x] Data layer — JSON/PHP array config files loaded by ConfigLoader
- [x] LLM — Single provider call via curl (DeepSeek or OpenRouter)
- [x] UI — CLI output with answer + metadata
- [x] Deployment — Documented local run command: `php research.php "question"`
- [x] Logging — Channel-prefixed file logger to logs/research.log
- [x] Config validation — bin/check-config health check script

## Out of Scope (Deferred to Later Slices)

| Feature | Deferred To | Reason |
|---------|-------------|--------|
| AgentManager / agent discovery | Phase 2 | Phase 1 uses hardcoded path to single agent. Dynamic directory scanning comes when multiple agents exist. |
| Web search tool | Phase 2 | Proves agent can research before adding tool access. |
| Paper search (arXiv + Semantic Scholar) | Phase 2 | Search provider abstraction deferred until first search integration. |
| Multi-agent orchestration / Arbitrator | Phase 3 | No need for orchestration until multiple agents exist. |
| Debate rounds | Phase 4 | Proven single-agent quality comes before debate mechanics. |
| Session persistence (markdown transcripts) | Phase 5 | File-per-session storage adds value only when sessions have content worth persisting. |
| CLI REPL (interactive mode) | Phase 5 | Per D-08. Phase 1 single-shot CLI validates core value before building interactive UX. |
| Web REPL | Phase 5 | Phase 1 CLI-only. Web interface adds presentation on top of proven pipeline. |
| PHPUnit/Composer | Phase 2+ | Zero-dependency Phase 1 proves the concept. Composer + PHPUnit added when complexity justifies automated testing. |

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural decisions:

- **Phase 2**: Agent Runtime & Tool Integration — AgentManager discovers agents, LlmClient gains provider abstraction, web search and paper search tools, HTTP timeout architecture.
- **Phase 3**: Orchestration Pipeline — Arbitrator distributes questions to multiple agents, enforces per-step timeouts, collects Round 1 answers with structural handoffs.
- **Phase 4**: Debate System & Echo Chamber Prevention — 2-round debate with quality evaluation, peer critique, reasoned answer selection, diversity mechanisms.
- **Phase 5**: Storage & Presentation — Session persistence as markdown transcripts, CLI REPL with readline, web REPL with SSE streaming.
