---
phase: 05
slug: storage-presentation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-13
---

# Phase 05 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | phpunit ^12.0 (Wave 0 installs) |
| **Config file** | phpunit.xml (Wave 0 creates) |
| **Quick run command** | `php vendor/bin/phpunit --testsuite unit` |
| **Full suite command** | `php vendor/bin/phpunit` |
| **Estimated runtime** | ~30s |

---

## Sampling Rate

- **After every task commit:** Run `php vendor/bin/phpunit --testsuite unit --filter <task>`
- **After every plan wave:** Run `php vendor/bin/phpunit`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 05-01-01 | 01 | 1 | PERS-01 | — | N/A | unit | `phpunit tests/SessionTest.php` | ❌ W0 | ⬜ pending |
| 05-01-02 | 01 | 1 | PERS-02 | — | N/A | unit | `phpunit tests/SessionTest.php` | ❌ W0 | ⬜ pending |
| 05-01-03 | 01 | 1 | PERS-03 | T-05-01 | Session files readable only by owner | unit | `phpunit tests/SessionTest.php` | ❌ W0 | ⬜ pending |
| 05-01-04 | 01 | 1 | PERS-04 | — | N/A | unit | `phpunit tests/SessionTest.php` | ❌ W0 | ⬜ pending |
| 05-01-05 | 01 | 1 | LOG-03 | — | N/A | unit | `phpunit tests/SessionTest.php` | ❌ W0 | ⬜ pending |
| 05-02-01 | 02 | 1 | CLI-01 | — | N/A | manual | — | — | ⬜ pending |
| 05-02-02 | 02 | 2 | CLI-02 | — | N/A | manual | — | — | ⬜ pending |
| 05-02-03 | 02 | 2 | CLI-03 | — | N/A | manual | — | — | ⬜ pending |
| 05-02-04 | 02 | 2 | CLI-04 | — | N/A | manual | — | — | ⬜ pending |
| 05-02-05 | 02 | 2 | CLI-05 | — | N/A | manual | — | — | ⬜ pending |
| 05-03-01 | 03 | 2 | WEB-01 | — | N/A | manual | — | — | ⬜ pending |
| 05-03-02 | 03 | 2 | WEB-02 | — | N/A | manual | — | — | ⬜ pending |
| 05-03-03 | 03 | 3 | WEB-03 | — | N/A | manual | — | — | ⬜ pending |
| 05-03-04 | 03 | 3 | WEB-04 | — | N/A | manual | — | — | ⬜ pending |
| 05-03-05 | 03 | 3 | WEB-05 | — | N/A | manual | — | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `phpunit.xml` — PHPUnit configuration at project root
- [ ] `tests/SessionTest.php` — stubs for PERS-01..04, LOG-03 session infrastructure tests
- [ ] `tests/SlugTest.php` — unit tests for slug derivation from question text
- [ ] `composer require --dev phpunit/phpunit ^12.0` — install PHPUnit

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| CLI REPL /replay shows ANSI-formatted transcript | CLI-04, CLI-05 | Requires interactive terminal with ANSI support | Run `php repl.php`, submit question, wait for results, type `/replay <slug>` — verify colored output |
| CLI REPL /config shows agent configuration | CLI-02 | Requires terminal output inspection | Type `/config` — verify agent names, models, status shown |
| Web REPL serves form at localhost:8080 | WEB-01 | Requires browser | Run `php -S localhost:8080 -t public/`, open browser, verify form loads |
| SSE streams progress to browser | WEB-04 | Requires EventSource API in browser | Submit question via web form, verify progress events appear in real-time |
| Session replay works in browser | WEB-02 | Requires browser | Navigate to /session/{id}, verify full transcript renders |
| Session list shows past sessions | WEB-03 | Requires browser | Navigate to /sessions, verify table with date, question, winner |
| CLI REPL /exit and SIGINT handling | CLI-02 | Requires interactive terminal | Type `/exit` — verify clean exit. Press Ctrl+C — verify graceful shutdown |

---

## Validation Sign-Off

- [ ] All tasks have automated verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
