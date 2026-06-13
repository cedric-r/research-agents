# Phase 1: Foundation & Single-Agent Baseline - Research

**Researched:** 2026-06-13
**Domain:** PHP CLI application foundation (config loading, logging, LLM API calling)
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **Config files**: JSON format (config.json decoded via json_decode with strict validation)
- **Per-agent dirs**: config/agents/{name}/config.json, SOUL.md, preferences.json
- **Arbitrator config**: Separate config directory with its own configuration
- **LlmClient**: Abstracted with provider adapters, uses PHP curl directly (no Guzzle/HTTP library)
- **CLI**: Flat script `php research.php "question"` at project root for v1
- **Phase 1 CLI**: Direct question argument only (interactive REPL deferred to Phase 5)
- **SOUL.md format**: Structured sections: ### Identity, ### Values, ### Instructions, ### Constraints
- **SOUL.md usage**: Read and concatenated as system prompt for LLM calls
- **Logging format**: Human-readable formatted text with channel prefix (agent, system)
- **Phase 1 logging**: Single log file with channel prefix (per-session log files deferred to Phase 5)

### Claude's Discretion

- Directory structure beyond config layout
- Error handling specifics (retry, error display approach)
- Exact config schema fields beyond provider/model/key

### Deferred Ideas (OUT OF SCOPE)

None.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CONF-01 | Agent directory under `config/agents/{name}/` | Verified: standard pattern for per-agent file-based config. `opendir()`/`scandir()` for scanning (Phase 2+), hardcoded path for Phase 1 single agent. |
| CONF-02 | Arbitrator has own config directory | Out of Phase 1 scope — no arbitrator yet. Directory structure should accommodate it (e.g., `config/arbitrator/`). |
| CONF-03 | `config.json` contains provider, model, API key | JSON format confirmed. `json_decode()` + `JSON_THROW_ON_ERROR` + required field validation. Provider values: `deepseek` (api.deepseek.com), `openrouter` (openrouter.ai/api/v1). API key via `$config['api_key']` — sourced from config file or env var. |
| CONF-04 | `SOUL.md` defines agent personality | Markdown with `### Identity`, `### Values`, `### Instructions`, `### Constraints` sections. Loaded via `file_get_contents()`. No parsing needed — sent as-is in system message. |
| CONF-05 | `preferences.json` defines tool access | JSON format, same loader as config.json. Tool access flags (tool name -> enabled boolean). Phase 1: LLM-only, no tools yet. |
| CONF-06 | Config loader supports PHP array config files | **CONFLICT WITH USER DECISION**: User chose JSON (D-01), but CONF-06 states "PHP array config files for zero parsing overhead." Planner must resolve. Recommendation: implement JSON loader per D-01; defer PHP array support unless user confirms both. |
| CONF-07 | Config validation at startup | Aggregate validation pattern: collect ALL errors (missing fields, invalid types, empty values) before reporting. Never fail on first error. Use `json_decode($raw, true, 32, JSON_THROW_ON_ERROR)` for parse errors. |
| TOOL-01 | Agent research via LLM model knowledge | Single curl POST to provider chat completions endpoint. System message = SOUL.md content. User message = research question. Response parsed from `choices[0].message.content`. |
| LOG-01 | Timestamps and correlation IDs | `[2026-06-13 14:30:00.123]` format with microsecond precision. Correlation ID: process-unique ID (e.g., `substr(bin2hex(random_bytes(4)), 0, 8)`) generated at session start. |
| LOG-02 | Channel-separated output | Log prefix: `[SYSTEM]`, `[AGENT]`, `[ARBITRATOR]`. Phase 1 only has SYSTEM and AGENT channels. Single file with prefix filtering. |
</phase_requirements>

## Summary

Phase 1 establishes the foundation for the entire ResearchAgents system: configuration loading, structured logging, and a single agent that can answer a research question by calling an LLM API. The phase validates the core value proposition (LLM-powered research answer) before any multi-agent complexity is introduced.

**Primary recommendation:** Zero-dependency Vanilla PHP approach. All required capabilities -- JSON parsing (`json_decode`), HTTP transport (`ext-curl`), file I/O, CLI argument parsing (`$argv`) -- are built into PHP 8.5.4. No Composer, no external packages. The entire Phase 1 stack is the PHP runtime itself, confirmed running at version 8.5.4 with all required extensions (curl, json, mbstring, readline, pcntl, posix, sockets) available.

**Key constraint to resolve:** CONF-06 requires "PHP array config files for zero parsing overhead" but user decision D-01 specifies JSON format. The planner must address this discrepancy -- likely by implementing JSON per D-01 and noting CONF-06 as superseded by user choice.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Config file loading | Infrastructure | -- | File I/O and JSON parsing are pure infrastructure concerns. No business logic. |
| Config validation | Infrastructure | -- | Schema checks run at startup before any agent logic. Separate from config loading for separation of concerns. |
| LLM API calling (HTTP) | Infrastructure | -- | HTTP transport via curl is plumbing. Provider differences (URL, auth) are config values, not business logic. |
| Agent research pipeline | Application | -- | Orchestrates: load config -> read SOUL.md -> build messages -> call LlmClient -> return answer. The single "business logic" layer. |
| Logging | Infrastructure | -- | File I/O with timestamps and prefixes. Observer pattern target for all higher layers. |
| CLI entry point | Presentation | -- | Parses argv, invokes agent, outputs result to stdout. Minimal -- no REPL yet. |

## Standard Stack

### Core (zero external dependencies)

| Technology | Version | Purpose | Why Standard |
|------------|---------|---------|--------------|
| PHP | 8.3+ (runtime 8.5.4) | Application language | Runtimes confirmed. All needed extensions available: curl, json, mbstring, readline, pcntl, posix, sockets. |
| ext-json (built-in) | Bundled | JSON config file parsing | `json_decode()`, `json_validate()`, `json_last_error_msg()`, `JSON_THROW_ON_ERROR` all available at PHP 8.5.4. |
| ext-curl (built-in) | Bundled | HTTP transport for LLM API calls | OpenAPI-compatible chat completions format. `curl_init()` + `curl_setopt_array()` + `curl_exec()` pattern. |
| Standard PHP Library | Built-in | File I/O, CLI argument parsing | `file_get_contents()`, `file_put_contents()` for config/SOUL.md/logs. `$argv` for CLI args. |
| ext-mbstring (built-in) | Bundled | Multibyte string safety | `mb_strlen()`, `mb_substr()` for safe string handling of LLM responses. |

### Installation

```bash
# No installation needed. PHP 8.3+ with these extensions:
# curl, json, mbstring, readline, pcntl, posix, sockets
# All confirmed available on PHP 8.5.4 runtime.

php -v  # Should show PHP 8.5.4
php -m  # Should list curl, json, mbstring, readline, pcntl, posix, sockets
```

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Direct json_decode | PHP array config files (include) | D-01 chose JSON for universality. PHP arrays have zero parse overhead but less portable. |
| Direct curl | Guzzle 7.10 | Guzzle adds PSR-7/PSR-18 chain (~5 packages) for middleware the project doesn't need yet. |
| Custom Logger (~70 lines) | Monolog 3.10 | Monolog adds rotation, multiple handlers, structured JSON output. Overhead for v1 single-file logging. |
| LlmClient (custom) | utopia-php/agents 2.1 | Clean abstraction but adds Composer dep. Worth revisiting at 3+ providers. |

## Package Legitimacy Audit

> Not applicable. Phase 1 uses zero external packages. No Composer, no npm, no pip dependencies. All capabilities come from the PHP 8.5.4 built-in extensions.

**No packages to audit.** The planner should confirm this remains true at implementation time -- do not add external dependencies to Phase 1 unless explicitly directed.

## Architecture Patterns

### System Architecture Diagram (Phase 1)

```
User input
    |
    v
research.php (CLI entry point)
    |
    v
ConfigLoader ----------------> config/agents/{name}/config.json
    |                                config/agents/{name}/SOUL.md
    v                                config/agents/{name}/preferences.json
ResearchAgent
    |
    v
LlmClient ---> (provider adapter)
    |               |
    |               v
    |           curl POST https://{provider-base}/chat/completions
    |               |
    |               v
    |           JSON response: choices[0].message.content
    |
    v
Answer (stdout)
    |
    v
Logger -----------------------> logs/research.log
    [timestamp] [CHANNEL] message
```

Data flows left-to-right. The CLI entry point loads config, constructs an agent, sends the question to the LLM via LlmClient, outputs the answer, and logs all steps.

### Recommended Project Structure

```
research-agents/
├── research.php               # CLI entry point: php research.php "question"
├── config/
│   ├── agents/
│   │   └── researcher/        # Single agent for Phase 1
│   │       ├── config.json    # Provider, model, API key
│   │       ├── SOUL.md        # Identity, Values, Instructions, Constraints
│   │       └── preferences.json # Tool access flags
│   └── arbitrator/            # Directory structure only — no arbitrator in Phase 1
│       └── config.json
├── src/
│   ├── Config/
│   │   └── Loader.php         # loadConfig(string $path): array — validates JSON, required fields
│   ├── Agent/
│   │   └── ResearchAgent.php  # Loads config, builds messages, calls LlmClient, returns answer
│   ├── LlmClient/
│   │   ├── LlmClient.php      # Interface/abstract: send(string $model, array $messages): string
│   │   └── ProviderAdapter.php # curl-based implementation for OpenAI-compatible APIs
│   └── Log/
│       └── Logger.php         # log(level, channel, message), info(), error(), warn()
└── logs/
    └── research.log           # Single log file (Phase 1)
```

### Pattern 1: Config Loading with Aggregate Validation

**What:** Load and validate a JSON config file, reporting all errors at once rather than failing on the first error found.

**When to use:** All config loading in Phase 1 -- config.json, preferences.json, and SOUL.md (file existence only for SOUL.md).

**Key design decisions:**
- Use `JSON_THROW_ON_ERROR` for parse error handling (PHP 7.3+, available)
- Collect ALL missing/invalid fields before throwing -- never fail on first error
- Validate types after decode: `is_string()`, `is_numeric()`, `!empty()` for non-empty strings
- File-not-found is a separate error from JSON parse errors

**Example flow:**
```php
function loadConfig(string $path, array $schema): array
{
    // 1. File exists?
    if (!file_exists($path)) {
        throw new ConfigException("Config file not found: {$path}");
    }

    // 2. Readable?
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new ConfigException("Cannot read config file: {$path}");
    }

    // 3. Valid JSON?
    try {
        $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ConfigException(
            "Invalid JSON in {$path}: " . $e->getMessage()
        );
    }

    // 4. Aggregate missing fields
    $errors = [];
    foreach ($schema['required'] ?? [] as $field) {
        if (!array_key_exists($field, $config)) {
            $errors[] = "Missing required field: '{$field}'";
        } elseif ($schema['types'][$field] ?? null === 'string' && empty(trim((string)$config[$field]))) {
            $errors[] = "Field '{$field}' must be a non-empty string";
        }
    }

    if (!empty($errors)) {
        throw new ConfigException(
            "Config validation failed for {$path}:\n" . implode("\n", $errors)
        );
    }

    return $config;
}
```

### Pattern 2: LlmClient Provider Adapter

**What:** A single `LlmClient` class that selects provider-specific behavior based on config.

**When to use:** Any LLM API call. Phase 1 uses it for the single agent's research question.

**Provider differences handled via config:**
- Base URL: `https://api.deepseek.com` vs `https://openrouter.ai/api/v1`
- Model name: `deepseek-v4-pro` vs `openai/gpt-4o`
- Both use: `POST /chat/completions`, Bearer token auth, OpenAI-compatible message format

```php
class LlmClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(array $providerConfig)
    {
        $this->baseUrl = $providerConfig['base_url'];
        $this->apiKey  = $providerConfig['api_key'];
        $this->model   = $providerConfig['model'];
    }

    public function chat(array $messages, array $options = []): string
    {
        $payload = array_merge([
            'model'    => $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
        ], $options);

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new LlmException("HTTP request failed: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new LlmException(
                "API returned HTTP {$httpCode}: " . mb_substr($response, 0, 500)
            );
        }

        $result = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        return $result['choices'][0]['message']['content'] ?? '';
    }
}
```

### Pattern 3: Channel-Prefix Logger

**What:** A file logger that writes human-readable lines with timestamp, channel prefix, level, and message.

**When to use:** All system and agent logging in Phase 1.

```
[2026-06-13 14:30:00.123] [SYSTEM] [INFO] Config loaded for agent 'researcher'
[2026-06-13 14:30:00.456] [AGENT]  [INFO] Sending question to LLM: "What is the capital of France?"
[2026-06-13 14:30:03.789] [AGENT]  [INFO] Received response from LLM (342 tokens)
[2026-06-13 14:30:03.790] [SYSTEM] [INFO] Answer delivered to user
```

```php
class Logger
{
    private string $file;
    private string $channel;
    private string $correlationId;

    private const LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR'];

    public function __construct(string $file, string $channel = 'SYSTEM', ?string $correlationId = null)
    {
        $this->file = $file;
        $this->channel = strtoupper($channel);
        $this->correlationId = $correlationId ?? substr(bin2hex(random_bytes(4)), 0, 8);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $line = sprintf(
            "[%s] [%s] [%s] [%s] %s%s\n",
            $timestamp,
            $this->channel,
            $level,
            $this->correlationId,
            $message,
            $context ? ' ' . json_encode($context) : ''
        );

        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function info(string $msg, array $ctx = []): void { $this->log('INFO', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('ERROR', $msg, $ctx); }
    public function warn(string $msg, array $ctx = []): void { $this->log('WARN', $msg, $ctx); }
    public function debug(string $msg, array $ctx = []): void { $this->log('DEBUG', $msg, $ctx); }
}
```

### Anti-Patterns to Avoid

- **Fail-on-first-error validation:** Config validation that throws on the first missing field means users fix one error, re-run, see the next error, repeat. Always validate all required fields and report every error in one message.
- **Silent json_decode null return:** Without `JSON_THROW_ON_ERROR`, `json_decode()` returns `null` for invalid JSON, indistinguishable from a decoded `{"key": null}`. Always use `JSON_THROW_ON_ERROR` in config loading.
- **API key in error output:** When catching exceptions, never include the full config array or HTTP response in error messages -- API keys could leak. Redact sensitive fields.
- **Blocking on network indefinitely:** Always set `CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT` on curl handles. A misconfigured provider URL should not hang the CLI forever.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| JSON config parsing | Custom JSON tokenizer | `json_decode()` + `JSON_THROW_ON_ERROR` | PHP's JSON parser is battle-tested, handles UTF-8, large files, edge cases. Hand-rolled parsers introduce security and correctness bugs. |
| HTTP transport | Raw socket fsockopen | `ext-curl` | curl handles SSL/TLS, connection pooling, redirects, timeouts, proxy support. Raw sockets would need to reimplement all of these. |
| Log file contention | Advisory file locking | `LOCK_EX` on `file_put_contents` | PHP processes can write concurrently. Without exclusive locking, log lines interleave. `FILE_APPEND | LOCK_EX` is the standard pattern. |

**Key insight:** Phase 1 builds several custom components (config loader, logger, LLM client) because the zero-dependency approach is a deliberate architectural choice. The "Don't Hand-Roll" items above are about not reimplementing what the PHP runtime already provides -- not about reaching for external packages.

## Common Pitfalls

### Pitfall 1: json_decode Returns null Without Warning

**What goes wrong:** `json_decode($invalidJson)` returns `null` instead of throwing an error. If the code doesn't check `json_last_error()`, it silently proceeds with null data, causing confusing downstream errors ("Trying to access array key on null").

**Why it happens:** `json_decode()` is silent by default for backward compatibility. `null` is the return value for both "valid JSON decoding to null" and "invalid JSON input."

**How to avoid:** Always use `JSON_THROW_ON_ERROR` flag:
```php
$config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
```
Or check `json_last_error()` immediately after decoding if PHP < 7.3 compatibility is needed.

**Warning signs:** "Call to a member function on null" errors that disappear when you var_dump the JSON source.

### Pitfall 2: API Key Leaked in Error Messages

**What goes wrong:** An HTTP 401 from the LLM provider throws an exception that includes the full request or response body. The error message propagates to the user, exposing the API key.

**Why it happens:** Eager exception construction that stringifies the entire curl request/response context.

**How to avoid:** Redact sensitive fields from error messages:
```php
// Bad: throws entire config
throw new LlmException("API call failed: " . json_encode($providerConfig));

// Good: throws specific message without secrets
throw new LlmException("API returned HTTP {$httpCode} for model '{$providerConfig['model']}'");
```

**Warning signs:** Exception messages containing `sk-` or long alphanumeric strings during development.

### Pitfall 3: Curl_exec Returns False, Not Empty String

**What goes wrong:** A network timeout or DNS failure causes `curl_exec()` to return `false`, not an empty string or HTTP response. Code that immediately tries to `json_decode($response)` gets `json_decode(false)` which is `null` -- masking the real network error.

**Why it happens:** `curl_exec()` returns `false` on failure, `string` on success. The return type is `string|false`, not just `string`.

**How to avoid:** Always check both `curl_error()` and the return value before processing:
```php
$response = curl_exec($ch);
if ($response === false) {
    throw new LlmException('curl error: ' . curl_error($ch));
}
// Now safe to process $response
```

**Warning signs:** "json_decode(): Argument #1 must be of type string, bool given" errors.

### Pitfall 4: CLI Script Pollution from Global State

**What goes wrong:** As the project grows, `research.php` accumulates global variables, inline logic, and import statements. It becomes a monolith that's hard to test or extend.

**Why it happens:** "Just one more check" mentality. A flat script at root is convenient for v1 but attracts scope creep.

**How to avoid:** Keep `research.php` as a thin entry point that delegates to classes:
```php
<?php
// research.php — keep thin
require_once __DIR__ . '/src/bootstrap.php';

$question = $argv[1] ?? null;
if (!$question) {
    echo "Usage: php research.php \"your question\"\n";
    exit(1);
}

$app = new Application();
$app->run($question);
```

**Warning signs:** `research.php` exceeds 50 lines or contains class definitions.

### Pitfall 5: PHP CLI Error Display Settings

**What goes wrong:** In a CLI context, PHP's `display_errors` default differs from web mode. Error messages may be suppressed or displayed raw depending on `php.ini` settings, confusing debugging.

**Why it happens:** CLI `php.ini` often suppresses errors that web mode shows (or vice versa). The project can't control the user's `php.ini`.

**How to avoid:** Set explicit error handling at the entry point:
```php
// At top of research.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
```

## Code Examples

### Config Loading with Aggregate Error Reporting

**Source:** [PHP Manual: json_decode](https://www.php.net/manual/en/function.json-decode.php), [PHP Manual: JSON_THROW_ON_ERROR](https://www.php.net/manual/en/json.constants.php#constant.json-throw-on-error)

```php
<?php
// src/Config/Loader.php
namespace App\Config;

class Loader
{
    /**
     * @param string $path       Path to JSON config file
     * @param array  $required   List of required field names
     * @param array  $types      Field name => PHP type string (e.g., 'provider' => 'string')
     * @return array
     * @throws ConfigException   On file, parse, or validation errors (aggregated)
     */
    public function load(string $path, array $required = [], array $types = []): array
    {
        if (!file_exists($path)) {
            throw new ConfigException("Config file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ConfigException("Cannot read config file: {$path}");
        }

        try {
            $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(
                "Invalid JSON in {$path}: " . $e->getMessage()
            );
        }

        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $config)) {
                $errors[] = "Missing required field: '{$field}'";
            } elseif (isset($types[$field])) {
                $type = $types[$field];
                $actual = gettype($config[$field]);
                if ($actual !== $type) {
                    $errors[] = "Field '{$field}' must be {$type}, got {$actual}";
                }
                // Non-empty string check
                if ($type === 'string' && trim((string)$config[$field]) === '') {
                    $errors[] = "Field '{$field}' must be a non-empty string";
                }
            }
        }

        if (!empty($errors)) {
            throw new ConfigException(
                "Config validation failed for {$path}:\n" . implode("\n", $errors)
            );
        }

        return $config;
    }
}
```

### Single-Agent Research Pipeline

**Source:** [DeepSeek API Docs](https://api-docs.deepseek.com/api/create-chat-completion), [OpenRouter API Reference](https://openrouter.ai/docs/api/api-reference/chat/send-chat-completion-request)

```php
<?php
// src/Agent/ResearchAgent.php
namespace App\Agent;

class ResearchAgent
{
    private array $config;
    private string $soul;
    private \App\LlmClient\LlmClient $llm;
    private \App\Log\Logger $logger;

    public function __construct(
        string $agentDir,
        \App\Config\Loader $configLoader,
        \App\Log\Logger $logger
    ) {
        $this->config = $configLoader->load(
            $agentDir . '/config.json',
            required: ['provider', 'model', 'api_key'],
            types: ['provider' => 'string', 'model' => 'string', 'api_key' => 'string']
        );

        $preferences = $configLoader->load(
            $agentDir . '/preferences.json',
            required: [],
            types: []
        );

        // SOUL.md: file must exist but no parsing needed
        $soulPath = $agentDir . '/SOUL.md';
        if (!file_exists($soulPath)) {
            throw new \RuntimeException("SOUL.md not found: {$soulPath}");
        }
        $this->soul = file_get_contents($soulPath);

        $this->llm = new \App\LlmClient\LlmClient([
            'base_url' => $this->getBaseUrl($this->config['provider']),
            'api_key'  => $this->config['api_key'],
            'model'    => $this->config['model'],
        ]);

        $this->logger = $logger;
    }

    public function research(string $question): string
    {
        $this->logger->info("Starting research for question: " . mb_substr($question, 0, 100));

        $messages = [
            ['role' => 'system', 'content' => $this->soul],
            ['role' => 'user',   'content' => $question],
        ];

        $this->logger->info("Sending request to LLM (model: {$this->config['model']})");
        $answer = $this->llm->chat($messages);

        $this->logger->info(
            "Received LLM response",
            ['length' => mb_strlen($answer), 'model' => $this->config['model']]
        );

        return $answer;
    }

    private function getBaseUrl(string $provider): string
    {
        return match ($provider) {
            'deepseek'  => 'https://api.deepseek.com',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default     => throw new \InvalidArgumentException("Unknown provider: {$provider}"),
        };
    }
}
```

### CLI Entry Point

**Source:** [PHP Manual: $argv](https://www.php.net/manual/en/reserved.variables.argv.php)

```php
<?php
// research.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

require_once __DIR__ . '/src/bootstrap.php';

// Simple argument parsing for Phase 1
$question = $argv[1] ?? null;

if (!$question) {
    echo "Usage: php research.php \"your research question\"\n";
    echo "\n";
    echo "Example: php research.php \"What are the latest advances in transformer architectures?\"\n";
    exit(0);
}

$logger = new \App\Log\Logger(
    __DIR__ . '/logs/research.log',
    channel: 'SYSTEM'
);

try {
    $logger->info("Phase 1 ResearchAgent started", ['question' => mb_substr($question, 0, 200)]);

    $configLoader = new \App\Config\Loader(__DIR__ . '/config');
    $agent = new \App\Agent\ResearchAgent(
        __DIR__ . '/config/agents/researcher',
        $configLoader,
        new \App\Log\Logger(__DIR__ . '/logs/research.log', channel: 'AGENT')
    );

    $answer = $agent->research($question);

    echo "\n=== Research Answer ===\n\n";
    echo $answer . "\n";
    echo "\n======================\n";

    $logger->info("Research completed successfully");
} catch (\Throwable $e) {
    $logger->error("Research failed: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
```

### LlmClient with PHP curl

**Source:** [PHP Manual: curl_setopt_array](https://www.php.net/manual/en/function.curl-setopt-array.php), [DeepSeek API Docs](https://api-docs.deepseek.com/api/create-chat-completion)

```php
<?php
// src/LlmClient/LlmClient.php
namespace App\LlmClient;

class LlmClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->apiKey  = $config['api_key'];
        $this->model   = $config['model'];
    }

    public function chat(array $messages, array $options = []): string
    {
        $payload = array_merge([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens'  => $options['max_tokens'] ?? 4096,
            'stream'      => false,
        ], $options);

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ResearchAgents/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new LlmException("HTTP request failed: {$curlError}");
        }

        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);

        if ($httpCode !== 200) {
            $errorMsg = $decoded['error']['message'] ?? $response;
            throw new LlmException(
                "API returned HTTP {$httpCode}: " . mb_substr($errorMsg, 0, 500)
            );
        }

        return $decoded['choices'][0]['message']['content'] ?? '';
    }
}
```

### Logger Implementation

**Source:** [PHP Manual: file_put_contents](https://www.php.net/manual/en/function.file-put-contents.php), [PHP Manual: DateTimeImmutable](https://www.php.net/manual/en/class.datetimeimmutable.php)

```php
<?php
// src/Log/Logger.php
namespace App\Log;

class Logger
{
    private string $file;
    private string $channel;
    private string $correlationId;

    private const LEVELS = ['DEBUG', 'INFO', 'WARN', 'ERROR'];

    public function __construct(
        string $file,
        string $channel = 'SYSTEM',
        ?string $correlationId = null
    ) {
        $this->file = $file;
        $this->channel = strtoupper(mb_substr($channel, 0, 20));
        $this->correlationId = $correlationId ?? self::generateCorrelationId();
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);

        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $line = sprintf(
            "[%s] [%-11s] [%-5s] [%s] %s%s\n",
            $timestamp,
            $this->channel,
            $level,
            $this->correlationId,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );

        $written = @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log("Logger: Cannot write to {$this->file}");
        }
    }

    public function info(string $msg, array $ctx = []): void { $this->log('INFO', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('ERROR', $msg, $ctx); }
    public function warn(string  $msg, array $ctx = []): void { $this->log('WARN', $msg, $ctx); }
    public function debug(string $msg, array $ctx = []): void { $this->log('DEBUG', $msg, $ctx); }

    public static function generateCorrelationId(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
```

## Don't Hand-Roll (additional)

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CLI argument parsing | Full argument parser | `$argv` + simple checks | Phase 1 has one arg (question string). Adding getopt or a parser library is over-engineering for `php research.php "question"`. |
| Date/time formatting | Custom timestamp format | `(new DateTimeImmutable())->format(...)` | Handles timezone, microsecond precision, locale issues. Built-in. |
| UUID generation | Custom UUID algorithm | `bin2hex(random_bytes(16))` | Simple unique IDs for correlation IDs. For v1, 8-char hex is sufficient. Formal UUIDs can come in Phase 5 if needed. |

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| json_decode without flags | `JSON_THROW_ON_ERROR` | PHP 7.3 (2018) | Backward-incompatible error handling. Now the standard pattern for config loading. |
| Guzzle 6.x for HTTP | Direct curl or utopia-php/fetch | Ecosystem shift (2023+) | Lighter alternatives with fewer deps. Guzzle's middleware chain is overkill for simple REST calls. |
| PHP 8.1 (min requirement) | PHP 8.3+ (runtime 8.5.4) | 2025+ | All modern PHP features available: readonly classes, enums, fibers, array unpacking, match expressions. |
| Deepseek legacy models | deepseek-v4-pro, deepseek-v4-flash | July 2026 | `deepseek-chat` and `deepseek-reasoner` deprecated Jul 24, 2026. Use new model IDs with `thinking` + `reasoning_effort` instead of separate model endpoints. |

**Deprecated/outdated:**
- `json_decode()` without `JSON_THROW_ON_ERROR`: Old pattern that silently returns null on invalid input. Always use the flag.
- `curl_setopt()` called per option: Use `curl_setopt_array()` for cleaner code (PHP 5.1.3+, well-established).
- `file_get_contents()` without `LOCK_EX` for concurrent log writes: Race condition risk on multi-process writes.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | CONF-06 requirement ("PHP array config files for zero parsing overhead") is superseded by user decision D-01 (JSON format). | Phase Requirements | Planner may treat CONF-06 as binding and require PHP array support alongside JSON. User must confirm JSON-only is acceptable. |
| A2 | Phase 1 uses a single agent with hardcoded path `config/agents/researcher/` rather than dynamic agent discovery. | Architecture Patterns | If user expects AgentManager discovery in Phase 1, the architecture needs adjustment. REQUIREMENTS.md maps CONF-08 (discovery) to Phase 2. |
| A3 | `ext-pcntl` and `ext-posix` are available but unused in Phase 1 (in-process sequential execution). | Standard Stack | Correct per Phase 1 scope and SUMMARY.md guidance. Will be used in Phase 3+ for parallel agent execution. |
| A4 | SOUL.md sections are concatenated as-is into the system message without custom formatting or prompt engineering. | Code Examples | Some providers may have system message length limits or format expectations. LLM may respond better with wrapping text. Low risk -- can adjust per-provider in LlmClient. |
| A5 | `logs/` directory must exist before Logger writes to it. | Architecture Patterns | Logger should auto-create the logs directory or the entry point should ensure it exists. If neither, first log write fails silently (handled by `@file_put_contents` and `error_log` fallback). |

## Open Questions (RESOLVED)

1. **CONF-06 vs D-01 conflict -- resolve before planning.** -- RESOLVED: Both formats supported. D-01 updated to specify "JSON and PHP array formats. Loader detects format by file extension." CONF-06 updated to match. Planner implements dual-format ConfigLoader.
2. **API key sourcing -- config file vs. environment variable.** -- RESOLVED: Direct in config.json. Users must add config/ to .gitignore. Documented in README and CLAUDE.md.
3. **Output format for the answer (raw vs. structured).** -- RESOLVED: Formatted output with metadata (model, response time, token count). D-15 added to CONTEXT.md.
4. **Error display granularity.** -- RESOLVED: Raw provider error for 401 (user fixes key). Friendly message for 429 with retry hint. Wrapped error for 5xx. Defined in Plan 01-03 actions.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | All Phase 1 code | yes | 8.5.4 | -- |
| ext-json | Config loading | yes | bundled | -- |
| ext-curl | LLM API calls | yes | bundled | -- |
| ext-mbstring | String handling | yes | bundled | -- |
| ext-readline | CLI (future REPL) | yes | bundled | Not needed in Phase 1 |
| ext-pcntl | Process management (future) | yes | bundled | Not needed in Phase 1 |
| ext-posix | Process management (future) | yes | bundled | Not needed in Phase 1 |
| ext-sockets | Socket-level I/O (future) | yes | bundled | Not needed in Phase 1 |
| ext-xdebug | Debugging | yes | 3.5.0 | -- |
| ext-OPcache | Opcode caching | yes | bundled | -- |

**Missing dependencies with no fallback:** None -- all dependencies confirmed available.

**Missing dependencies with fallback:** None.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit (suggested: ^12.0 as dev dependency, or manual test scripts for zero-dep mode) |
| Config file | None in Phase 1 unless PHPUnit added |
| Quick run command | `php research.php "Who was Ada Lovelace?"` (manual smoke test) |
| Full suite command | N/A -- no test suite in Phase 1 base |

### Phase Requirements Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CONF-01 | Agent directory exists | manual | `ls config/agents/researcher/` | Wave 0 |
| CONF-03 | config.json loads and validates | manual | `php -r "json_decode(file_get_contents('config/agents/researcher/config.json'), ...)"` | Wave 0 |
| CONF-04 | SOUL.md readable and non-empty | manual | `wc -c config/agents/researcher/SOUL.md` | Wave 0 |
| CONF-07 | Config validation reports all errors | manual | Provide invalid config, run research.php, observe error output | Wave 0 |
| TOOL-01 | LLM API call returns coherent answer | manual | `php research.php "What is 2+2?"` -- expect "4" | Wave 0 |
| LOG-01 | Log file created with timestamps | manual | `cat logs/research.log` after running | Wave 0 |
| LOG-02 | Channel prefix present in log | manual | `grep '\[SYSTEM\]' logs/research.log && grep '\[AGENT\]' logs/research.log` | Wave 0 |

### Sampling Rate

- **Per task commit:** Manual run of `php research.php "Who was Ada Lovelace?"` (smoke test)
- **Per wave merge:** Full manual verification against the test map above
- **Phase gate:** All 7 requirements testable with manual commands

### Wave 0 Gaps

- [ ] `config/agents/researcher/config.json` -- sample config with real provider values
- [ ] `config/agents/researcher/SOUL.md` -- structured sections for identity, values, instructions, constraints
- [ ] `config/agents/researcher/preferences.json` -- tool access flags (empty for Phase 1)
- [ ] `config/arbitrator/config.json` -- placeholder for Phase 3
- [ ] `logs/research.log` -- must be created by first log write (empty file or auto-create)
- [ ] Decision note: CONF-06 vs D-01 conflict resolved (planning decision)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | API key stored in config file. File permissions must restrict access (chmod 600). No user authentication needed -- single-user system. |
| V5 Input Validation | yes | Research question length capped before sending to LLM. User input never eval'd or exec'd. |
| V6 Cryptography | no | No encryption needed in Phase 1. API keys are stored in plaintext per user decision. |
| V8 Data Protection | partial | API key must not appear in log files or error messages. Logged question text truncated to 200 chars. |
| V14 Configuration | yes | Config validation at startup (CONF-07). Sensitive config values (API keys) should not be dumped in error output. |

### Known Threat Patterns for Vanilla PHP CLI

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| API key exposure in error messages | Information Disclosure | Redact sensitive fields from exception messages. Never include full request/response in errors. |
| Config file leakage via .gitignore | Information Disclosure | Add `config/agents/*/config.json` to `.gitignore`. Warn in README. |
| LLM prompt injection via user question | Tampering | Cap question length (e.g., 8000 chars max). Do not execute tool calls from LLM output in Phase 1. |
| Log injection (forged log entries via crafted input) | Tampering | Strip control characters from logged strings: `preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message)`. |

## Sources

### Primary (HIGH confidence)

- [PHP Manual: json_decode](https://www.php.net/manual/en/function.json-decode.php) -- JSON_THROW_ON_ERROR, flags, depth parameter. **HIGH confidence.**
- [PHP Manual: curl_setopt_array](https://www.php.net/manual/en/function.curl-setopt-array.php) -- HTTP transport options, timeout constants. **HIGH confidence.**
- [PHP Manual: file_put_contents](https://www.php.net/manual/en/function.file-put-contents.php) -- FILE_APPEND, LOCK_EX flags. **HIGH confidence.**
- [PHP Manual: error_reporting](https://www.php.net/manual/en/function.error-reporting.php) -- CLI error display settings. **HIGH confidence.**
- [DeepSeek API Docs](https://api-docs.deepseek.com/api/create-chat-completion) -- Chat completions format, model names (deepseek-v4-pro, deepseek-v4-flash), deprecation Jul 24, 2026. **HIGH confidence.**
- [OpenRouter API Reference](https://openrouter.ai/docs/api/api-reference/chat/send-chat-completion-request) -- Endpoint, auth, message format, provider extensions. **HIGH confidence.**

### Secondary (MEDIUM confidence)

- [Packagist: utopia-php/agents v2.1.0](https://packagist.org/packages/utopia-php/agents) -- Verified: standalone, deps only utopia-php/fetch, PHP 8.3+. Not used in Phase 1 (zero-dep approach). **MEDIUM confidence.**
- [PHP json_validate()](https://www.php.net/manual/en/function.json-validate.php) -- Available in PHP 8.3+ for lightweight syntax check before decode. Phase 1 doesn't need it (JSON_THROW_ON_ERROR is sufficient). **MEDIUM confidence.**

### Tertiary (LOW confidence)

- None -- all Phase 1 claims are verified against PHP runtime or official API documentation.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- All components verified on PHP 8.5.4 runtime. All extensions confirmed available.
- Architecture: HIGH -- Patterns validated against CONTEXT.md user decisions and PHP manual documentation.
- Pitfalls: HIGH -- Common PHP json_decode, curl, and CLI pitfalls verified against PHP manual behavior and community knowledge.

**Research date:** 2026-06-13
**Valid until:** 2026-08-13 (relatively stable foundation; LLM API model names may change)
