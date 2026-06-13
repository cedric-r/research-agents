# ResearchAgents -- Walking Skeleton

**Phase:** 1
**Generated:** 2026-06-13

## Capability Statement

A user can run `php research.php "question"` from the command line and receive a research answer produced by an LLM agent configured with a provider model, API key, and personality.

## Architectural Decisions

| # | Decision | Rationale | Outcome |
|---|----------|-----------|---------|
| 1 | Framework: Vanilla PHP (no Laravel/Symfony) | Zero framework overhead. All needed capabilities (curl, json, readline, pcntl, posix, sockets) built into PHP 8.5.4. No compilation or JVM needed. | `src/` organized by domain. Flat project structure. |
| 2 | Dependency strategy: Zero external dependencies for Phase 1 | No Composer. PHP extensions only. Avoids dependency chain complexity until justified. | curl, json, mbstring, readline, pcntl, posix, sockets confirmed available on PHP 8.5.4 runtime. |
| 3 | Config format: JSON default (config.json, preferences.json). PHP arrays also accepted. | JSON is universally readable and toolable. PHP arrays provide zero-parse-overhead alternative. Loader detects format by file extension (.json vs .php) per D-01. | `config/agents/{name}/config.json`, `preferences.json`. |
| 4 | Autoloading: PSR-4-like via spl_autoload_register. `App\` namespace maps to `src/`. | Zero-dependency autoloading avoids require_once chains. PHP built-in spl_autoload_register is reliable and well-documented. | One-time setup in entry point. No Composer autoloader needed. |
| 5 | Logging: Custom Logger class (~70 lines). Human-readable formatted text with channel prefix. Single application log for Phase 1. | Bundled into zero-dependency approach. Monolog adds value at scale (rotation, structured output, multiple handlers) but is premature for Phase 1's single-file, single-process use case. | `logs/research.log`. Format: `[timestamp] [CHANNEL] [LEVEL] [correlation_id] message {context}`. |
| 6 | LLM access: Single LlmClient with provider adapter pattern. Provider differences handled via config values, not separate classes. | Avoids class explosion (DeepseekClient, OpenRouterClient, etc.) when adding providers. All OpenAI-compatible providers use POST /chat/completions with Bearer auth -- only base URL and model name differ. | One class, config-driven. Provider config specifies `provider`, `model`, `api_key` per D-06. |
| 7 | CLI entry point: Flat script `research.php` at project root. Direct question argument only. | Simplest possible user-facing interface. Interactive REPL deferred to Phase 5 per D-08. Keeping it flat prevents over-engineering the entry point. | `php research.php "your question"`. |
| 8 | SOUL.md format: Structured markdown sections per D-09. | Sections: `### Identity`, `### Values`, `### Instructions`, `### Constraints`. Read as-is and concatenated into LLM system prompt per D-10. | Clear separation of agent personality from code. No parsing needed. |
| 9 | Logging format with microsecond precision and correlation ID. | `[YYYY-MM-DD HH:MM:SS.uuuuuu] [CHANNEL] [LEVEL] [correlation_id] message {context}`. Channels (SYSTEM, AGENT) enable log filtering without separate files. Correlation ID enables session tracing. | Single log file for Phase 1 per D-13. |
| 10 | Directory layout: `src/` organized by domain. | `src/Config/`, `src/Log/`, `src/LlmClient/`, `src/Agent/`. `config/` with per-agent directories. `logs/`, `bin/` at project root. | Domain-driven layout prevents circular dependencies. Flat `config/agents/` enables agent scanning in Phase 2. |

## Directory Structure

```
research-agents/
  research.php           -- CLI entry point
  SKELETON.md            -- This file
  src/
    Config/
    Log/
    LlmClient/
    Agent/
  config/
    agents/
      researcher/        -- Agent config: config.json, SOUL.md, preferences.json
    arbitrator/          -- Arbitrator config: config.json (Phase 3+)
  logs/                  -- Application log
  bin/                   -- Utility scripts
```

## Out of Scope (Phase 1)

| Item | Deferred To | Rationale |
|------|-------------|-----------|
| Interactive CLI REPL | Phase 5 | Phase 1 uses direct argument per D-08. |
| Web REPL | Phase 5 | Phase 1 is CLI-only. |
| Multi-agent orchestration / Arbitrator | Phase 3 | No need for orchestration until multiple agents exist. |
| Web search tool | Phase 2 | Proves agent can research before adding tool access. |
| Paper search (arXiv, Semantic Scholar) | Phase 2 | Search provider abstraction deferred until first search integration. |
| Debate rounds | Phase 4 | Proven single-agent quality comes before debate mechanics. |
| Session persistence (markdown transcripts) | Phase 5 | File-per-session storage adds value only when sessions have content worth persisting. |
| PHPUnit / Composer | Phase 2+ | Zero-dependency Phase 1 proves the concept first. |

## Key Links

| Artifact | Consumers | Resolution |
|----------|-----------|------------|
| `config/agents/researcher/config.json` | `src/Config/Loader.php` | Loaded by ConfigLoader during ResearchAgent construction. |
| `config/agents/researcher/SOUL.md` | `src/Agent/ResearchAgent.php` | Read and used as system prompt for LLM calls per D-10. |
| `config/agents/researcher/preferences.json` | `src/Agent/ResearchAgent.php` | Tool access flags read during agent initialization. |
| `config/arbitrator/config.json` | `src/Config/Loader.php` | Placeholder config loaded in Phase 3+ for arbitrator. |

## Configuration Security

- Config files containing API keys (`config.json`) must be gitignored.
- File permissions should be restricted: `chmod 600 config/agents/*/config.json`.
- API keys must not appear in log output or error messages per D-14.

## Threat Model

| Threat | Category | Disposition | Mitigation |
|--------|----------|-------------|------------|
| API key leakage via git | Information Disclosure | Mitigate | `config/*/config.json` in `.gitignore`. Document file permission requirement in README. |
| Config file tampering | Tampering | Accept | Local-only files. No remote write access. Content loaded via `json_decode` from known paths only. |
