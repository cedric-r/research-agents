<!-- GSD:project-start source:PROJECT.md -->

## Project

**ResearchAgents**

A multi-agent research system where an arbitrator distributes research questions to several AI agents, each with different models and personalities. Agents produce independent answers, then debate each other's findings, and the arbitrator selects the best result. Runs as an interactive REPL (CLI + web) with detailed logging and file-per-session storage.

**Core Value:** Get the best possible research answer by having multiple AI agents with diverse models and sources work in parallel, debate their findings, and converge on the optimal result — with full traceability of how they got there.

### Constraints

- **Language**: PHP (Vanilla — no Laravel/Symfony)
- **Storage**: File-per-session markdown, no database
- **Interface**: Interactive REPL — both CLI and web
- **Config**: File-based, each agent/arbitrator has own directory
- **Logging**: Detailed timestamped activity logs
- **API Keys**: Stored in config files per agent

<!-- GSD:project-end -->

<!-- GSD:stack-start source:research/STACK.md -->

## Technology Stack

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP | 8.3+ (runtime: 8.5.4) | Application language | Zero framework overhead. Built-in web server, fibers, readline, pcntl, curl, SimpleXMLElement all available out of the box. No compilation or JVM needed. |
| ext-curl | Bundle with PHP | HTTP transport for all API calls (LLM, web search, academic APIs) | Available on this system. Single HTTP transport for all external communication — OpenAI, Deepseek, OpenRouter, SerpAPI, arXiv, Semantic Scholar all speak HTTP. |
| ext-readline | Bundle with PHP | CLI REPL input | Available on this system. Provides `readline()`, `readline_add_history()`, `readline_completion_function()` for rich interactive CLI. The standard PHP REPL primitive. |
| ext-pcntl + ext-posix | Bundle with PHP | Process forking for parallel agent execution | Available on this system. Only way to get true OS-level parallelism in vanilla PHP without Composer. Each agent runs as a forked child process with independent execution. |
| PHP built-in web server | Built into PHP binary | Web REPL interface | Zero-dependency web serving. `php -S localhost:8080 router.php` serves the web REPL. Single-threaded is fine for single-user interactive use. |
| PHP arrays (config files) | N/A | Structured configuration | Zero-dependency, type-safe, no parsing overhead. Config files are `return ['key' => 'value'];` — loaded with `include`. Supports every PHP type natively. |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| utopia-php/agents | ^2.1.0 | LLM API abstraction (OpenAI, Deepseek, OpenRouter) | When managing multiple LLM providers with different auth headers/streaming formats becomes tedious. Provides clean provider abstraction with minimal deps (only `utopia-php/fetch` which wraps curl). |
| monolog/monolog | ^3.10.0 | Structured logging with file and console handlers | When raw `fwrite()` logging lacks timestamp formats, log levels, or rotation. One-dependency library (PSR-3 interface). Industry standard. |
| dallgoot/yaml | ^1.0.0 | YAML config file parser | If YAML config files are preferred over PHP arrays for portability. No Symfony deps. Pure PHP 8.1+ implementation. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| Composer | Dependency autoloading | Only needed if using any supporting library. Not required for zero-dependency mode. |
| PHPUnit ^12.0 | Testing | Standard PHP testing framework. Works without any framework. |

## Installation

### Zero-Dependency Mode (recommended for v1)

# No Composer required. Just PHP with these extensions:

#   curl, readline, json, mbstring, pcntl, posix, sockets

# All are available on the runtime system (PHP 8.5.4).

### With Composer Dependencies

# Core LLM abstraction

# Structured logging

# Optional: YAML config support

# Dev dependencies

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Vanilla PHP (no framework) | Laravel / Symfony | If the project outgrows its scope into full web app with auth, database, and multi-user support. Not recommended for this project's scope. |
| ext-curl (direct) | Guzzle 7.10 | If the project expands significantly and needs middleware, retry middleware, connection pooling at HTTP level. Guzzle adds PSR-7/PSR-18 chain (~5 packages). Only worth it if composing complex HTTP workflows. |
| readline() CLI | ReactPHP stdio-react | If CLI needs to handle concurrent async output while accepting input (e.g., streaming LLM output while typing next question). Overkill for a sequential REPL. |
| pcntl_fork (process-based) | amphp/parallel v2 | If the project needs thread-style parallelism with shared memory patterns. AMPHP provides higher-level abstractions over processes. Adds ~5 packages. Worth adding if fork management becomes complex. |
| ext-curl (direct) | utopia-php/fetch | If writing raw curl for every API call feels too verbose. utopia-php/fetch is a clean curl wrapper — same curl underneath, nicer API. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| Laravel AI SDK (laravel/ai) | Tightly coupled to Laravel service container, Eloquent, and artisan commands. Cannot use standalone. | utopia-php/agents for provider abstraction. Raw curl for HTTP. |
| symfony/yaml | Pulls in full Symfony dependency chain (contracts, etc.) for a simple parser. | dallgoot/yaml (zero Symfony deps) or JSON (built-in). |
| ReactPHP / Amp for everything | Full event-loop frameworks solve problems this project doesn't have. The project is sequential with concurrent HTTP, not a real-time server. | curl_multi_exec for concurrent HTTP (zero deps). pcntl_fork for parallel agents (zero deps). Add amphp/parallel only if fork IPC becomes unwieldy. |
| Swoole / FrankenPHP | Thread-based PHP is overkill. Requires runtime changes (Swoole extension, FrankenPHP binary). Not standard PHP. | pcntl_fork or amphp/parallel for parallelism. Standard PHP only. |
| Doctrine / Eloquent | No database in scope. File-per-session markdown storage. | file_put_contents / file_get_contents with markdown formatting. |
| Symfony Console | Full console framework for a single CLI command. Adds ~10 packages. | readline() loop (native, zero deps). |

## Stack Architecture Decision: Dependency Strategy

### Approach A: Zero Native Dependencies (v1 default)

### Approach B: Minimal Composer Dependencies

### Recommendation

## Detailed Technology Rationale

### 1. LLM API Calls: ext-curl / curl_multi_exec

- Base URL (different per provider)
- Auth header format (Bearer token for all)
- Model name string

### 2. Parallel Agent Execution: pcntl_fork

### 3. Web Search API Integration

- GET request with query params and API key
- Parse JSON response
- Extract organic results (title, url, snippet)

### 4. Scientific Paper APIs: Direct REST Calls

- GET request with query parameters
- Returns Atom XML
- Parse with `SimpleXMLElement`
- Example: `http://export.arxiv.org/api/query?search_query=all:transformer&start=0&max_results=5`
- GET request with query + API key (optional for low rate limits)
- Returns JSON
- Example: `https://api.semanticscholar.org/graph/v1/paper/search?query=attention mechanism&limit=5`

### 5. CLI REPL: readline()

- Tab completion: `readline_completion_function()`
- History across sessions: `readline_write_history()`, `readline_read_history()`
- Custom prompt with ANSI colors

### 6. Web REPL: PHP Built-in Server + SSE

- `GET /` → Static HTML page with a textarea and submit button
- `POST /api/ask` → Triggers research, returns SSE stream with token-by-token results

### 7. Logging: To Monolog or Not

- Log rotation is needed (separate files per day/size)
- Multiple log destinations (console + file + error file)
- JSON structured logging for machine parsing
- Different formats per handler

### 8. Configuration: PHP Arrays First

### 9. SOUL.md Personality Files

## Version Compatibility

| Package | PHP Version | Notes |
|---------|-------------|-------|
| PHP (runtime) | 8.5.4 | Already installed on system |
| utopia-php/agents ^2.1 | >= 8.3 | Fully compatible |
| monolog/monolog ^3.10 | >= 8.1 | Fully compatible |
| dallgoot/yaml ^1.0 | >= 8.1 | Zero Symfony deps |
| guzzlehttp/guzzle ^7.10 | >= 7.2 | Avoid unless needed (heavy dep chain) |
| scholarly/providers ^1.0 | >= 8.3 | Heavier dep chain (PSR-18, PSR-17). Defer. |

## Stack Patterns by Variant

- Use `ext-curl` directly for all HTTP calls
- Use `curl_multi_exec` for concurrent API calls
- Use `pcntl_fork` for parallel agent execution
- Use bare `readline()` for CLI REPL
- Use `php -S` for web REPL
- Use a custom Logger class (~70 lines)
- Use PHP arrays for config files
- Total: zero external dependencies, ~200 lines of utility code
- Add `utopia-php/agents` for LLM provider abstraction
- Add `monolog/monolog` for structured file logging
- Keep everything else (curl, pcntl, readline, php -S, PHP arrays) as native
- Add `amphp/parallel` for more robust parallel agent execution with timeout management
- Add `guzzlehttp/guzzle` if HTTP middleware (retry, circuit breaker) becomes necessary
- Add `dallgoot/yaml` if external contributors prefer YAML config
- Still avoid: frameworks (Laravel/Symfony), databases (Doctrine), full event loops (ReactPHP/Amp for everything)

## Sources

- [Packagist: utopia-php/agents v2.1.0](https://packagist.org/packages/utopia-php/agents) — Verified: standalone, deps only utopia-php/fetch, PHP 8.3+, supports OpenAI/Deepseek/OpenRouter. **HIGH confidence.**
- [Packagist: utopia-php/fetch](https://packagist.org/packages/utopia-php/fetch) — Verified: pure curl wrapper, zero dependencies beyond PHP. **HIGH confidence.**
- [Packagist: monolog/monolog v3.10.0](https://packagist.org/packages/monolog/monolog) — Verified: requires PHP 8.1+, deps only psr/log. **HIGH confidence.**
- [PHP Manual: curl_multi_exec](https://www.php.net/manual/en/function.curl-multi-exec.php) — Verified: standard pattern for concurrent HTTP. **HIGH confidence.**
- [PHP Manual: readline](https://www.php.net/manual/en/function.readline.php) — Verified: native CLI input with history and completion. **HIGH confidence.**
- [PHP Manual: pcntl_fork](https://www.php.net/manual/en/function.pcntl-fork.php) — Verified: process forking for parallel execution. **HIGH confidence.**
- [Packagist: dallgoot/yaml v1.0.0](https://packagist.org/packages/dallgoot/yaml) — Verified: pure PHP YAML 1.2, no Symfony deps. **MEDIUM confidence** (less popular than symfony/yaml but lower dependency overhead).
- [Packagist: scholarly/providers v1.0.1](https://packagist.org/packages/scholarly/providers) — Verified: unified scholarly API client. **MEDIUM confidence** for using it (heavier deps than needed for this project).
- [arXiv API documentation](https://info.arxiv.org/help/api/index.html) — Verified: simple REST + Atom XML. **HIGH confidence.**
- [Semantic Scholar API documentation](https://api.semanticscholar.org/) — Verified: simple REST + JSON. **HIGH confidence.**

<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->

## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->

## Architecture

Architecture not yet mapped. Follow existing patterns found in the codebase.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->

## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->

## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:

- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->

## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
