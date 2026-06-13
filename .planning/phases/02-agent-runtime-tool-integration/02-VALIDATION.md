---
phase: 02
phase_name: agent-runtime-tool-integration
status: planned
created: 2026-06-13
source: RESEARCH.md Validation Architecture
framework: PHPUnit (to be installed as dev dependency)
---

# Phase 02: Agent Runtime & Tool Integration — Validation Strategy

## Test Infrastructure

- **Framework:** PHPUnit ^12.0 (Composer dev dependency, not yet installed)
- **Config file:** `phpunit.xml.dist` (bootstrap: `tests/bootstrap.php`, test dir: `tests/`)
- **Existing tests:** None — greenfield test infrastructure

## Requirements → Test Map

| Req ID | Behavior | Test Type | Test File |
|--------|----------|-----------|-----------|
| CONF-08 | AgentManager discovers agents by scanning config dirs | Unit | tests/Agent/AgentManagerTest.php |
| TOOL-02 | WebSearch calls Brave API and returns formatted results | Integration (mock HTTP) | tests/Tool/WebSearchTest.php |
| TOOL-03 | AcademicSearch queries arXiv and parses Atom XML | Integration (mock/real) | tests/Tool/AcademicSearchTest.php |
| TOOL-04 | AcademicSearch queries Semantic Scholar and parses JSON | Integration (mock/real) | tests/Tool/AcademicSearchTest.php |
| TOOL-05 | LlmClient reads provider config per request | Unit | tests/LlmClient/LlmClientTest.php |
| TOOL-06 | WebSearch config-driven provider selection | Unit | tests/Tool/WebSearchTest.php |
| TOOL-07 | AcademicSearch merges and deduplicates results | Unit | tests/Tool/AcademicSearchMergeTest.php |
| TOOL-08 | HttpHelper enforces CURLOPT_TIMEOUT and CONNECTTIMEOUT | Unit | tests/Http/HttpHelperTest.php |

## Sampling Rate

- **Per task commit:** Relevant test file(s) for the task
- **Per wave merge:** `phpunit tests/` (all tests)
- **Phase gate:** Full suite green before `/gsd-verify-work`

## Wave 0 Gaps

- [ ] `tests/Http/HttpHelperTest.php` — covers TOOL-08
- [ ] `tests/Tool/WebSearchTest.php` — covers TOOL-02, TOOL-06
- [ ] `tests/Tool/AcademicSearchTest.php` — covers TOOL-03, TOOL-04
- [ ] `tests/Tool/AcademicSearchMergeTest.php` — covers TOOL-07
- [ ] `tests/Agent/AgentManagerTest.php` — covers CONF-08
- [ ] `tests/LlmClient/LlmClientTest.php` — covers TOOL-05
- [ ] `tests/bootstrap.php` — test autoloader
- [ ] `phpunit.xml.dist` — PHPUnit configuration
- [ ] `composer.json` — dev dependency: `phpunit/phpunit ^12.0`
