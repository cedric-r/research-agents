---
phase: 01
slug: foundation-single-agent-baseline
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-13
---

# Phase 01 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | phpunit 12.x |
| **Config file** | phpunit.xml (Wave 0 installs) |
| **Quick run command** | `vendor/bin/phpunit --testsuite phase-01` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit --testsuite phase-01`
- **After every plan wave:** Run `vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-01-01 | 01 | 1 | CONF-01 | — | N/A | unit | vendor/bin/phpunit --filter testConfigDir | ❌ W0 | ⬜ pending |
| 01-01-02 | 01 | 1 | CONF-06 | — | N/A | unit | vendor/bin/phpunit --filter testConfigLoad | ❌ W0 | ⬜ pending |
| 01-01-03 | 01 | 1 | CONF-07 | — | N/A | unit | vendor/bin/phpunit --filter testConfigValidation | ❌ W0 | ⬜ pending |
| 01-02-01 | 01 | 1 | TOOL-01 | — | N/A | unit | vendor/bin/phpunit --filter testLlmClient | ❌ W0 | ⬜ pending |
| 01-03-01 | 01 | 1 | LOG-01 | — | N/A | unit | vendor/bin/phpunit --filter testLogger | ❌ W0 | ⬜ pending |
| 01-03-02 | 01 | 1 | LOG-02 | — | N/A | unit | vendor/bin/phpunit --filter testLogChannels | ❌ W0 | ⬜ pending |

---

## Wave 0 Requirements

- [ ] `composer require --dev phpunit/phpunit ^12.0` — install test framework
- [ ] `tests/Config/LoaderTest.php` — config loading tests
- [ ] `tests/LlmClientTest.php` — LLM client tests
- [ ] `tests/Log/LoggerTest.php` — logger tests

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| CLI output formatting | TOOL-01 | Visual output style | Run `php research.php "test"`, verify formatted output with metadata |
| Config file permissions | CONF-01 | Security-sensitive | Verify config files have 600 permissions |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
