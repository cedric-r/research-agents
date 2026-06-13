# Stack Research

**Domain:** Multi-agent research and debate system (Vanilla PHP)
**Researched:** 2026-06-13
**Confidence:** HIGH

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

```bash
# No Composer required. Just PHP with these extensions:
#   curl, readline, json, mbstring, pcntl, posix, sockets
# All are available on the runtime system (PHP 8.5.4).
```

### With Composer Dependencies

```bash
# Core LLM abstraction
composer require utopia-php/agents:^2.1

# Structured logging
composer require monolog/monolog:^3.10

# Optional: YAML config support
composer require dallgoot/yaml:^1.0

# Dev dependencies
composer require --dev phpunit/phpunit:^12.0
```

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

This project supports **two viable approaches** depending on the tradeoff preference:

### Approach A: Zero Native Dependencies (v1 default)

```
PHP built-ins only:
├── ext-curl        → All HTTP API calls (LLM, search, papers)
├── ext-readline    → CLI REPL
├── ext-pcntl+posix → Process forking for parallel agents
├── php -S          → Web REPL
├── SimpleXMLElement→ arXiv XML parsing
└── PHP arrays      → Configuration
```

**Pros:** Zero install friction. Clone and run. No `composer install` step. Full control.
**Cons:** More code to write for HTTP handling, config loading, logging.

### Approach B: Minimal Composer Dependencies

```
Composer packages (2 runtime deps):
├── utopia-php/agents v2.1.0
│   └── utopia-php/fetch (curl wrapper, no further deps)
├── monolog/monolog v3.10.0
│   └── psr/log ^3.0
└── (dev) phpunit/phpunit ^12.0
```

**Pros:** Less boilerplate, structured logging, clean LLM provider API.
**Cons:** `composer install` required, autoloader overhead, version management.

### Recommendation

Start with **Approach A** (zero deps). Write a thin `HttpClient` class wrapping `curl_init()`/`curl_multi_exec()`, a `Logger` class wrapping `fwrite()`, and use PHP arrays for config. The total code added is under 200 lines. This keeps the project maximally portable and dependency-free.

If LLM provider complexity grows (more than 3 providers, streaming formats differ significantly), add **utopia-php/agents** as the first Composer dependency. It has a minimal dependency chain and provides genuine value.

Add **Monolog** only when log file rotation, log levels above DEBUG/INFO/WARN/ERROR, or structured JSON logging becomes necessary.

## Detailed Technology Rationale

### 1. LLM API Calls: ext-curl / curl_multi_exec

**Why not utopia-php/agents from day one?**
The LLM API calls in this system are straightforward: POST JSON to an endpoint, receive JSON back. OpenAI, Deepseek, and OpenRouter all use the same OpenAI-compatible chat completions API format. A single `curl_post_json()` function (20 lines) handles all three providers. The differences are:
- Base URL (different per provider)
- Auth header format (Bearer token for all)
- Model name string

These are configuration concerns, not abstraction concerns. A single PHP class with provider-specific URL and model config covers it.

**Concurrent HTTP with curl_multi_exec:**
```php
$mh = curl_multi_init();
foreach ($agents as $id => $agentConfig) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $agentConfig['endpoint'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $agentConfig['key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$id] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh); // Block until activity
} while ($running);
// Collect results from each handle
```

This pattern fires all agent API calls simultaneously and waits for all to complete. No Composer required.

### 2. Parallel Agent Execution: pcntl_fork

For the debate system, agents could benefit from true parallel execution (e.g., each agent does web search + paper lookup + LLM reasoning simultaneously).

**Pattern:**
```php
$pids = [];
foreach ($agents as $id => $config) {
    $pid = pcntl_fork();
    if ($pid === -1) { die('fork failed'); }
    if ($pid === 0) {
        // Child process: agent works independently
        $result = $agent->research($question);
        file_put_contents("/tmp/agent-$id-result.json", json_encode($result));
        exit(0);
    }
    $pids[$id] = $pid;
}
// Parent: wait for all agents
foreach ($pids as $id => $pid) {
    pcntl_waitpid($pid, $status);
    $results[$id] = json_decode(
        file_get_contents("/tmp/agent-$id-result.json"), true
    );
}
```

IPC via temp files is simple and debuggable (files persist after crash). For v1, this pattern is sufficient and requires zero dependencies.

### 3. Web Search API Integration

Web search APIs (Google Custom Search, SerpAPI, Brave Search, Bing) are all REST endpoints. Pattern is identical across providers:
- GET request with query params and API key
- Parse JSON response
- Extract organic results (title, url, snippet)

Build a `SearchProvider` interface:
```php
interface SearchProvider {
    public function search(string $query, int $limit = 5): array;
}
```

Concrete implementations for each provider are 30-40 lines each. The project config specifies which provider to use — no library needed.

### 4. Scientific Paper APIs: Direct REST Calls

**arXiv API** (`http://export.arxiv.org/api/query`):
- GET request with query parameters
- Returns Atom XML
- Parse with `SimpleXMLElement`
- Example: `http://export.arxiv.org/api/query?search_query=all:transformer&start=0&max_results=5`

**Semantic Scholar API** (`https://api.semanticscholar.org/graph/v1/paper/search`):
- GET request with query + API key (optional for low rate limits)
- Returns JSON
- Example: `https://api.semanticscholar.org/graph/v1/paper/search?query=attention mechanism&limit=5`

Both are simple REST APIs. The `scholarly/providers` package is well-designed but adds significant dependency weight (PSR-18, PSR-17, retry logic) for what amounts to two HTTP GET calls.

### 5. CLI REPL: readline()

```php
while (true) {
    $input = readline('research> ');
    if ($input === false) break;
    $input = trim($input);
    if ($input === '') continue;
    if ($input === 'exit' || $input === 'quit') break;
    readline_add_history($input);
    $this->handleCommand($input);
}
```

Available extensions on this system:
- Tab completion: `readline_completion_function()`
- History across sessions: `readline_write_history()`, `readline_read_history()`
- Custom prompt with ANSI colors

### 6. Web REPL: PHP Built-in Server + SSE

```
php -S localhost:8080 public/router.php
```

The router script serves:
- `GET /` → Static HTML page with a textarea and submit button
- `POST /api/ask` → Triggers research, returns SSE stream with token-by-token results

SSE for streaming LLM output to browser:
```php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

foreach ($llmStream as $chunk) {
    echo "data: " . json_encode($chunk) . "\n\n";
    ob_flush();
    flush();
}
echo "data: [DONE]\n\n";
```

### 7. Logging: To Monolog or Not

**Zero-dependency Logger (70 lines):**
```php
class Logger {
    private string $file;
    private array $levels = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
    public function __construct(string $file) { $this->file = $file; }
    public function log(string $level, string $message, array $context = []): void {
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s.u'), $level, $message,
            $context ? json_encode($context) : ''
        );
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
    public function info(string $msg, array $ctx = []): void { $this->log('INFO', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('ERROR', $msg, $ctx); }
    // ...
}
```

**Monolog** becomes valuable when:
- Log rotation is needed (separate files per day/size)
- Multiple log destinations (console + file + error file)
- JSON structured logging for machine parsing
- Different formats per handler

For v1's file-per-session logging, the zero-dependency Logger above is sufficient. Add Monolog if log management becomes a concern.

### 8. Configuration: PHP Arrays First

The project already specifies per-agent directories. Using PHP arrays:
```php
// agents/gpt4-researcher/config.php
return [
    'provider' => 'openai',
    'model' => 'gpt-4o',
    'api_key' => getenv('OPENAI_API_KEY') ?: '',
    'temperature' => 0.7,
    'max_tokens' => 4096,
    'search_provider' => 'brave',
    'search_api_key' => getenv('BRAVE_API_KEY') ?: '',
];
```

To load: `$config = include 'agents/gpt4-researcher/config.php';`

This is the most PHP-idiomatic approach. If portability to non-PHP tools is needed, use JSON (`json_decode(file_get_contents(...))`). Add YAML only if stakeholders demand it.

### 9. SOUL.md Personality Files

`file_get_contents('agents/gpt4-researcher/SOUL.md')` returns the markdown as a string. Include in LLM system prompt. No parsing needed — it's natural language instructions.

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

**If keeping zero composer dependencies:**
- Use `ext-curl` directly for all HTTP calls
- Use `curl_multi_exec` for concurrent API calls
- Use `pcntl_fork` for parallel agent execution
- Use bare `readline()` for CLI REPL
- Use `php -S` for web REPL
- Use a custom Logger class (~70 lines)
- Use PHP arrays for config files
- Total: zero external dependencies, ~200 lines of utility code

**If adding minimal Composer dependencies:**
- Add `utopia-php/agents` for LLM provider abstraction
- Add `monolog/monolog` for structured file logging
- Keep everything else (curl, pcntl, readline, php -S, PHP arrays) as native

**If the project grows significantly:**
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

---
*Stack research for: Multi-agent research/debate system in Vanilla PHP*
*Researched: 2026-06-13*
