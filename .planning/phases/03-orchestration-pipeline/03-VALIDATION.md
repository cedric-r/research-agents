---
phase: 3
slug: orchestration-pipeline
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-06-13
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit ^12.0 (dev only) |
| **Config file** | `phpunit.xml.dist` |
| **Quick run command** | `php vendor/bin/phpunit --testsuite phase-3` |
| **Full suite command** | `php vendor/bin/phpunit` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php vendor/bin/phpunit --testsuite phase-3 --filter {task_id}`
- **After every plan wave:** Run `php vendor/bin/phpunit --testsuite phase-3`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 03-01-01 | 01 | 1 | ORCH-01 | — / — | N/A | unit | `php vendor/bin/phpunit --filter testArbitratorDiscoversAgents` | ❌ W0 | ⬜ pending |
| 03-01-02 | 01 | 1 | ORCH-02 | — / — | N/A | integration | `php vendor/bin/phpunit --filter testDistributesQuestionToAllAgents` | ❌ W0 | ⬜ pending |
| 03-01-03 | 01 | 1 | ORCH-04 | — / — | N/A | integration | `php vendor/bin/phpunit --filter testCollectsRound1Answers` | ❌ W0 | ⬜ pending |
| 03-02-01 | 02 | 1 | ORCH-03 | — / — | N/A | integration | `php vendor/bin/phpunit --filter testEnforcesPerStepTimeout` | ❌ W0 | ⬜ pending |
| 03-02-02 | 02 | 1 | ORCH-10 | — / — | N/A | unit | `php vendor/bin/phpunit --filter test4LayerTimeoutArchitecture` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Phase3/ArbitratorTest.php` — stubs for ORCH-01 through ORCH-04, ORCH-10
- [ ] `tests/Phase3/bootstrap.php` — shared fixtures (temp agent configs, mock LLM responses)
- [ ] `phpunit.xml.dist` — suite configuration (already exists from Phase 2)

*If none: "Existing infrastructure covers all phase requirements."*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| SIGTERM handler writes partial answer | ORCH-03 | Signal handling can't be tested in process-memory PHPUnit | Run `php research.php "test"` with a slow agent, verify temp file contains PARTIAL_ANSWER after timeout |
| IPC temp file cleanup | ORCH-04 | Side-effect on filesystem | Run session, verify `sys_get_temp_dir()` has no leftover agent temp files |
| 4-layer timeout cascade | ORCH-10 | Timeout layers depend on system-level timing | Run with intentionally slow mock, verify each layer catches in correct order |

*If none: "All phase behaviors have automated verification."*

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
