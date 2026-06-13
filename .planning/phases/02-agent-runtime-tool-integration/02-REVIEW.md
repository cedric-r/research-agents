---
phase: 02-agent-runtime-tool-integration
reviewed: 2026-06-13T16:30:00Z
depth: standard
files_reviewed: 11
files_reviewed_list:
  - src/Http/HttpHelper.php
  - src/Http/HttpException.php
  - src/Tool/ToolRegistry.php
  - src/Tool/WebSearch.php
  - src/Tool/AcademicSearch.php
  - src/Agent/ResearchAgent.php
  - src/Agent/AgentManager.php
  - src/LlmClient/LlmClient.php
  - src/LlmClient/LlmException.php
  - src/Log/Logger.php
  - src/Config/Loader.php
  - research.php
  - bin/check-config
  - composer.json
  - phpunit.xml.dist
  - src/bootstrap.php
findings:
  critical: 2
  warning: 6
  info: 3
  total: 11
status: issues_found
---

# Phase 02: Code Review Report

**Reviewed:** 2026-06-13T16:30:00Z
**Depth:** Standard
**Files Reviewed:** 16
**Status:** Issues Found

## Summary

Phase 02 introduces HTTP helper, tool abstractions (WebSearch, AcademicSearch), ToolRegistry, LlmClient, AgentManager, and the CLI entry point research.php. The overall architecture is clean and well-structured, with proper separation of concerns. However, two critical defects exist: a crash path in bin/check-config when `glob()` fails, and an incorrect Semantic Scholar paper URL that generates broken links. Several warnings around error handling granularity and edge-case robustness should be addressed before the code ships.

## Critical Issues

### CR-01: ForEach Crash When glob() Returns False

**File:** `/mnt/katsumi/data/research-agents/bin/check-config:24`
**Issue:** If `glob()` returns `false` (e.g., open_basedir restriction, path exhaustion, or filesystem error), the variable `$agentDirs` becomes a boolean. The code on line 20 checks `$agentDirs === false || $agentDirs === []` and prints an info message but does NOT exit or return. Execution falls through to `foreach ($agentDirs as $agentDir)` on line 24, which in PHP 8.x throws a `TypeError` because `foreach` requires an iterable. This crashes the configuration check script with a fatal error instead of reporting a meaningful result.

**Fix:** Add a `return` or `exit` inside the guard block so the script terminates safely when `glob()` fails.

```php
// bin/check-config lines 20-22 (before line 24)
if ($agentDirs === false || $agentDirs === []) {
    echo "[INFO] No agent directories found under {$agentsDir}" . PHP_EOL;
    return;  // or exit(0) — prevent fallthrough to foreach
}
```

### CR-02: Incorrect Semantic Scholar Paper URL Generates Broken Links

**File:** `/mnt/katsumi/data/research-agents/src/Tool/AcademicSearch.php:260`
**Issue:** The paper URL is constructed as `sprintf('https://api.semanticscholar.org/%s', $item['paperId'] ?? '')`. This produces URLs like `https://api.semanticscholar.org/649def34...`, which is not a valid Semantic Scholar endpoint. The `api.semanticscholar.org` subdomain serves the REST API (e.g., `/graph/v1/paper/search`), not human-readable paper pages. The correct URL for a paper on Semantic Scholar is `https://www.semanticscholar.org/paper/{paperId}`. Users will receive broken links in the formatted search output.

**Fix:** Change the URL pattern to use the correct paper page URL format.

```php
// AcademicSearch.php line 260
'url' => sprintf('https://www.semanticscholar.org/paper/%s', $item['paperId'] ?? ''),
```

Alternatively, if the intent was to provide an API link, use the graph API endpoint:
```php
'url' => sprintf('https://api.semanticscholar.org/graph/v1/paper/%s', $item['paperId'] ?? ''),
```

## Warnings

### WR-01: preferences.json Reported as Optional in Check-Config But Required at Runtime

**File:** `/mnt/katsumi/data/research-agents/bin/check-config:58-62`
**Issue:** The check-config script treats a missing `preferences.json` as a warning (optional file) and increments `$successCount` on line 61, passing the validation. However, `ResearchAgent::__construct()` (line 44 in ResearchAgent.php) calls `$configLoader->load($agentDir . '/preferences.json', ...)`, and `Config\Loader::load()` throws `ConfigException` if the file does not exist. This means:

1. `bin/check-config` says preferences.json is optional and passes validation.
2. At runtime, if preferences.json is missing, the entire multi-agent research crashes.

**Fix:** Either (a) make `Loader::load()` return an empty array when the file is missing and preferences.json is truly optional, or (b) remove the "optional" labeling from check-config and treat it as required.

### WR-02: Agent Construction Error Kills All Agents

**File:** `/mnt/katsumi/data/research-agents/src/Agent/AgentManager.php:164-193`
**Issue:** The `research()` method iterates over agents sequentially. If one agent's `ResearchAgent` constructor throws (e.g., missing SOUL.md, invalid config, missing preferences.json), the exception propagates uncaught and halts all remaining agents. There is no per-agent error handling — a single misconfigured agent prevents all others from running.

**Fix:** Wrap the agent construction and research call in a try/catch block within the loop, logging the error and continuing with the next agent.

```php
foreach ($agents as $agentName => $agentInfo) {
    try {
        $agentLogger = new Logger($this->logFile, $agentName, $correlationId);
        $agent = new ResearchAgent($agentInfo['dir'], $this->configLoader, $agentLogger);
        $toolRegistry = $this->configureTools($http, $agentInfo['config'], $agentLogger);
        $agent->setToolRegistry($toolRegistry);
        $result = $agent->research($question);
        $results[$agentName] = $result;
    } catch (\Throwable $e) {
        $errorLogger = $this->logger ?? $agentLogger ?? null;
        if ($errorLogger) {
            $errorLogger->error('AgentManager: agent skipped due to error', [
                'agent' => $agentName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### WR-03: Deduplication Logic Fails to Update Seen Cache on Replacement

**File:** `/mnt/katsumi/data/research-agents/src/Tool/AcademicSearch.php:300-307`
**Issue:** When a duplicate paper is found and the new entry has a better (non-empty) abstract, the code updates `$deduped[$key]` (line 305) but does NOT update `$seen[$key]` (line 300, the seen array used for `isset` checks). If three or more duplicates of the same paper exist, the third duplicate compares against the original inferior entry in `$seen` rather than the improved one in `$deduped`, so the third-best entry could be discarded incorrectly.

**Fix:** Update `$seen[$key]` alongside `$deduped[$key]` when a replacement occurs.

```php
// AcademicSearch.php lines 300-307
if (isset($seen[$key])) {
    $existing = $seen[$key];
    if (!empty($paper['abstract']) && empty($existing['abstract'])) {
        $deduped[$key] = $paper;
        $seen[$key] = $paper;  // <-- add this line
    }
    continue;
}
```

### WR-04: curl_multi_select Return Value Unchecked — Potential Busy-Wait

**File:** `/mnt/katsumi/data/research-agents/src/Http/HttpHelper.php:98`
**Issue:** `curl_multi_select($multiHandle, 5)` on line 98 is called without checking its return value. If `curl_multi_select` returns -1 (indicating an error — e.g., a signal interrupted the select), the do-while loop spins without waiting, consuming CPU until the requests complete or time out. The `5`-second timeout mitigates worst-case behavior, but a tight spin under error conditions could still be resource-intensive.

**Fix:** Check the return value and fall back to a brief `usleep` if select fails.

```php
do {
    $status = curl_multi_exec($multiHandle, $running);
    if ($status === CURLM_CALL_MULTI_PERFORM) {
        continue;
    }
    $select = curl_multi_select($multiHandle, 5);
    if ($select === -1) {
        usleep(10000); // 10ms sleep if select failed
    }
} while ($running > 0);
```

### WR-05: arXiv API Uses HTTP Instead of HTTPS

**File:** `/mnt/katsumi/data/research-agents/src/Tool/AcademicSearch.php:20`
**Issue:** The arXiv base URL is defined as `http://export.arxiv.org/api/query` (plain HTTP). The arXiv API does support HTTPS, and using HTTP exposes the request and response to man-in-the-middle tampering. An attacker on the network path could inject malicious paper metadata (titles, abstracts, DOIs) that become part of the LLM system prompt. The project risk register (STATE.md line 42) acknowledges this risk but has no concrete mitigation plan.

**Fix:** Use the HTTPS endpoint.

```php
private const ARXIV_BASE = 'https://export.arxiv.org/api/query';
```

### WR-06: Potential PHP Warning When getLastResponseInfo Called Before chat()

**File:** `/mnt/katsumi/data/research-agents/src/LlmClient/LlmClient.php:111`
**Issue:** The `getLastResponseInfo()` method accesses `$this->lastResponse['model']` on line 111. The `$this->lastResponse` property is typed as `?array` and initialized to `null`. If `getLastResponseInfo()` is called before any successful `chat()` call (or after a failed one), `null['model']` emits a PHP 8.x Warning "Trying to access array offset on value of type null". The null coalescing operator `??` handles the fallback, but the warning pollutes stderr/logs. In the current code flow, `getLastResponseInfo()` is only called after a successful `chat()`, so this is a defensive issue, but the type system should guarantee safety.

**Fix:** Add a null guard or rewrite to avoid the warning.

```php
public function getLastResponseInfo(): array
{
    $response = $this->lastResponse ?? [];
    $usage = $response['usage'] ?? [];
    $model = $response['model'] ?? $this->model;

    return [
        'model' => $model,
        'usage' => [
            'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens'      => $usage['total_tokens'] ?? 0,
        ],
        'response_time_ms' => $this->lastResponseTimeMs ?? 0,
    ];
}
```

## Info

### IN-01: Unused 'provider' Key Passed to LlmClient Constructor

**File:** `/mnt/katsumi/data/research-agents/src/Agent/ResearchAgent.php:67`
**Issue:** The `LlmClient` constructor array includes `'provider' => $this->config['provider']` on line 67, but `LlmClient::__construct()` only uses three keys from the config: `base_url`, `api_key`, and `model`. The `provider` key is silently ignored. This is dead data in the constructor call.

**Fix:** Remove the `'provider'` key from the array passed to `LlmClient`, or document why it's needed for future use.

### IN-02: strlen vs mb_substr Inconsistency in summarizeParams

**File:** `/mnt/katsumi/data/research-agents/src/Tool/ToolRegistry.php:144`
**Issue:** The `summarizeParams()` method uses `strlen($value) > 100` to detect long strings, but `strlen` counts bytes, not characters. For multi-byte UTF-8 text (e.g., Chinese, Arabic, emoji), a 50-character string could exceed 100 bytes, triggering unnecessary truncation. The truncation itself uses `mb_substr` (character-aware), so the output is correct, but the threshold check is byte-counting while the truncation is character-counting. This is a minor inconsistency.

**Fix:** Switch the check to `mb_strlen` for consistency.

```php
if (is_string($value) && mb_strlen($value) > 100) {
```

### IN-03: Docblock Imprecision in getLastResponseInfo Return Type

**File:** `/mnt/katsumi/data/research-agents/src/LlmClient/LlmClient.php:107`
**Issue:** The `@return` docblock says `@return array{model: string, usage: array, response_time_ms: int}` but the actual return structure is more specific (`usage` has `prompt_tokens`, `completion_tokens`, `total_tokens` keys). This imprecision reduces IDE auto-completion quality and static analysis accuracy.

**Fix:** Expand the return type annotation to document the usage sub-structure.

---

_Reviewed: 2026-06-13T16:30:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
