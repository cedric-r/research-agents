---
phase: 02-agent-runtime-tool-integration
plan: 01
subsystem: agent-runtime
tags: [http-client, curl, tools, registry, phpunit]

requires:
  - phase: 01-foundation-single-agent-baseline
    provides: LlmClient, bootstrap, Config/Loader, Log/Logger, ResearchAgent
provides:
  - HttpHelper centralized HTTP utility with timeout enforcement
  - ToolRegistry pattern for tool registration and dispatch
  - Refactored LlmClient using HttpHelper instead of direct curl
  - PHPUnit test infrastructure (composer.json, phpunit.xml.dist, bootstrap)
affects:
  - Plan 02-02: WebSearch and AcademicSearch tools (will use HttpHelper and ToolRegistry)
  - Plan 02-03: AgentManager and integration
  - All future plans that make HTTP calls

tech-stack:
  added:
    - phpunit/phpunit ^12.0 (dev dependency)
    - PHPUnit test framework with local test HTTP server pattern
  patterns:
    - Centralized HTTP with timeout enforcement via HttpHelper
    - ToolRegistry: callable-based registration and dispatch by name
    - Test HTTP server using PHP built-in server for integration tests

key-files:
  created:
    - src/Http/HttpHelper.php (120 lines)
    - src/Http/HttpException.php (9 lines)
    - src/Tool/ToolRegistry.php (152 lines)
    - composer.json
    - phpunit.xml.dist
    - tests/bootstrap.php
    - tests/Http/HttpHelperTest.php
    - tests/Http/server.php (test HTTP server)
    - tests/LlmClient/LlmClientTest.php
  modified:
    - src/LlmClient/LlmClient.php
    - .gitignore

decisions:
  - HttpHelper constructor accepts timeout(60) and connectTimeout(10) — hardcoded minima per threat model T-02-02
  - curl_close() omitted intentionally — deprecated in PHP 8.5 (handles auto-close)
  - ToolRegistry stores callable handlers by name — no class/tool type enforcement beyond is_callable()
  - LlmClient receives optional HttpHelper parameter for DI, defaults to new instance for backward compat
  - Test server uses PHP built-in server started in setUpBeforeClass() — ports 8899/8898 for isolation

metrics:
  duration: 599s
  completed_date: 2026-06-13
  tasks: 3/3
  files_created: 9
  files_modified: 2
  tests_added: 13
  test_assertions: 37
---

# Phase 2 Plan 1: Shared Infrastructure Layer — Summary

Created the shared infrastructure layer for all Phase 2 tools: centralized HTTP with timeout enforcement (HttpHelper), tool registration/dispatch (ToolRegistry), and refactored LlmClient to use HttpHelper.

## Tasks

| Task | Name | Type | TDD | Commit | Status |
|------|------|------|-----|--------|--------|
| 1 | Create HttpHelper with timeout enforcement and test infrastructure | auto | yes | `4f9c9d6` (RED), `1b52cf1` (GREEN) | Complete |
| 2 | Create ToolRegistry with register/run dispatch pattern | auto | no | `d777a37` | Complete |
| 3 | Refactor LlmClient to use HttpHelper | auto | yes | `aeeeb59` (RED), `2869c44` (GREEN) | Complete |

## Task Details

### Task 1: HttpHelper + Test Infrastructure

- **composer.json** with phpunit/phpunit ^12.0 dev dependency, PSR-4 autoload
- **phpunit.xml.dist** with test suite configuration
- **tests/bootstrap.php** loading Composer and project autoloaders
- **tests/Http/server.php** — local PHP built-in HTTP test server with GET, POST, JSON, slow, and echo endpoints
- **src/Http/HttpException.php** — typed exception extending `\RuntimeException`
- **src/Http/HttpHelper.php** with:
  - `get(string $url, array $headers): array` — returns `['body', 'http_code']`
  - `post(string $url, array $data, array $headers): array` — JSON-encodes data
  - `getMulti(array $urls): array` — uses `curl_multi_exec` for parallel requests
  - Enforced `CURLOPT_TIMEOUT=60`, `CURLOPT_CONNECTTIMEOUT=10`
  - Security: URLs sanitized in error messages (query params stripped), error messages truncated to 200 chars
  - `curl_close()` omitted — deprecated in PHP 8.5 (handles auto-close)
- **tests/Http/HttpHelperTest.php** — 8 tests, 25 assertions (success, JSON, POST, connection failure, timeout, custom headers, getMulti, exception type)

### Task 2: ToolRegistry

- **src/Tool/ToolRegistry.php**:
  - `register(string $name, array $definition): void` — validates handler is callable, schema is array, enforces unique names
  - `run(string $name, array $params): string` — dispatches via `call_user_func`, wraps handler exceptions
  - `getSchemas(): array` — returns all registered tool schemas
  - `has(string $name): bool` — exists check
  - Optional `Logger` integration for audit trail
  - Params summarized for logging (long strings truncated)

### Task 3: LlmClient Refactor

- Replaced direct `curl_init`/`curl_setopt_array`/`curl_exec` with `$this->http->post()`
- Optional `HttpHelper` constructor parameter (defaults to new instance)
- `HttpException` caught and re-thrown as `LlmException`
- All public API preserved:
  - Constructor still accepts `['base_url', 'api_key', 'model']`
  - `chat()` returns `string`
  - `getLastResponseInfo()` returns same structure
- `curl_close()` deprecation eliminated (was at line 71)
- **tests/LlmClient/LlmClientTest.php** — 5 tests, 12 assertions

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Deprecation] Removed curl_close() calls**
- **Found during:** Task 1 test run
- **Issue:** `curl_close()` is deprecated since PHP 8.5 as curl handles auto-close since PHP 8.0
- **Fix:** Removed `curl_close($ch)` calls from `HttpHelper::request()` and `HttpHelper::getMulti()`. Removed from `LlmClient.php` during refactoring.
- **Files modified:** `src/Http/HttpHelper.php`, `src/LlmClient/LlmClient.php`
- **Commit:** `1b52cf1`

## TDD Gate Compliance

| Gate | Commit | Status |
|------|--------|--------|
| RED (task 1) | `4f9c9d6` — test(02-01): add failing HttpHelper tests | Confirmed (8 class-not-found failures) |
| GREEN (task 1) | `1b52cf1` — feat(02-01): implement HttpHelper | 8 tests pass |
| RED (task 3) | `aeeeb59` — test(02-01): add LlmClient contract tests | Confirmed (5 contract tests) |
| GREEN (task 3) | `2869c44` — feat(02-01): refactor LlmClient | All 13 tests pass |

## Threat Surface Scan

No new security-relevant surface was introduced beyond the threat register in PLAN.md. HttpHelper error sanitization (URL stripping, message truncation) implements T-02-01/T-02-03 mitigations. ToolRegistry handler dispatch (T-02-04) is callable-only, never user-provided.

## Known Stubs

None identified.

## Self-Check

- [x] All created files exist
- [x] All commits exist in git history
- [x] All 13 PHPUnit tests pass with 37 assertions
- [x] Syntax check passes on all modified files: `src/Http/HttpHelper.php`, `src/Http/HttpException.php`, `src/Tool/ToolRegistry.php`, `src/LlmClient/LlmClient.php`, `research.php`, `bin/check-config`
- [x] LlmClient uses HttpHelper internally (verified: `$this->http->post()` in source)
- [x] composer.json has phpunit/phpunit dev dependency

**Self-Check: PASSED**
