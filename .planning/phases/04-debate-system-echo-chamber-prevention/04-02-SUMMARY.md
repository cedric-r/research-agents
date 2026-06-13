---
phase: 04-debate-system-echo-chamber-prevention
plan: 02
subsystem: arbitrator, output
tags: [debate, scoring, edge-cases, output, validation]
requires:
  - phase: "04-01"
    provides: "Debate core protocol (evaluateAnswers, computeDiversityScores, Round 2 critique, selectFinalAnswer)"
provides:
  - Finalized arbitrator config schema with all Phase 4 sections
  - Edge case hardening for empty answers, malformed LLM output, and critique validation
  - N-gram threshold enforcement with configurable similarity threshold
  - Round 2 timeout fallback chain (critique.timeout_seconds -> agent_timeout -> 120)
  - Debate-aware output formatting with score table, winner narrative, and error summary
affects: []
tech-stack:
  added: []
  patterns:
    - "JSON brace-matching extraction via strpos/strrpos for malformed LLM output"
    - "Early validation in child process before writing temp file results"
    - "Threshold-gated diversity penalty (no penalty below similarity threshold)"
key-files:
  created:
    - "config/arbitrator/config.json"
  modified:
    - "src/Arbitrator/Arbitrator.php"
    - "src/Agent/ResearchAgent.php"
    - "research.php"
key-decisions: []
requirements-completed:
  - ORCH-08
  - ORCH-09
  - D-05
metrics:
  duration: null
  completed: 2026-06-13
---

# Phase 4 Plan 2: Polished debate protocol with edge case hardening, config finalization, and transparent output formatting

**Adds score breakdown table with quality/critique/diversity weighting, winner answer with model metadata, and judge narrative reasoning to the CLI output. Hardens all Phase 4 methods against empty answers, malformed LLM JSON, missing critique fields, and out-of-range scores. Finalizes config schema with nested Phase 4 sections and section-level type validation.**

## Performance

**Duration:** Completed all 3 tasks
**Started:** 2026-06-13
**Completed:** 2026-06-13
**Tasks:** 3/3
**Files created:** 1
**Files modified:** 3

## Accomplishments

### Task 1: Finalize arbitrator config schema and verify prompt template paths

Created `config/arbitrator/config.json` with the complete Phase 4 schema: scoring (model, temperature, rubric_prompt), judge (model, temperature, max_candidates), weights (quality 0.50, critique 0.30, diversity 0.20), diversity (enabled, n_gram_size, factor, threshold 0.70), critique (template_path, judge_prompt_path, timeout_seconds 120). Added config section type validation in `loadConfig()` that warns and falls back to empty array if any Phase 4 section is not an object (prevents type errors from misconfigured JSON). Verified all prompt template paths (scoring-prompt.txt, critique-template.txt, judge-prompt.txt) exist at referenced locations.

Files: `config/arbitrator/config.json`, `src/Arbitrator/Arbitrator.php`
Commit: `bf5c592`

### Task 2: Edge case hardening -- empty answers, malformed LLM output, critique validation, Round 2 timeout

Seven hardening changes across Arbitrator and ResearchAgent:

- **Part A**: computeDiversityScores() logs when empty answers are skipped from diversity scoring. computeAverageCritiqueScores() returns 0.5 neutral default when Round 2 produces no results.
- **Part B**: parseScoringJson() extracts JSON via brace matching (first `{` to last `}`) before decoding, handling LLM extra text wrapping. evaluateAnswers() warns if prompt template lacks expected `{question}`/`{answer}` placeholders.
- **Part C**: ResearchAgent::critique() validates LLM response JSON structure before returning: checks for array, `score` field presence, numeric type, and 0-10 range. Invalid critiques produce RuntimeExceptions caught by Arbitrator child handlers.
- **Part D**: Round 2 timeout falls back from critique.timeout_seconds to agent_timeout to 120, with info-level logging.
- **Part E**: Score distribution guidance confirmed present in scoring-prompt.txt.
- **Part F**: computeDiversityScores() applies configurable similarity threshold (default 0.70) before computing diversity penalty -- answers below threshold receive zero penalty.
- **Part G**: research() flow fills default neutral critiques when Round 2 produces no results.

Files: `src/Arbitrator/Arbitrator.php`, `src/Agent/ResearchAgent.php`
Commit: `385da52`

### Task 3: Output formatting in research.php -- score table + winner + narrative

Replaced the per-agent answer dump with debate-aware output. When `$debateResult` is non-null (2+ agents responded): displays a score breakdown table with Quality, Critique Average, Diversity Bonus, and Weighted Total per agent; marks candidates with `*` and winner with `<< WINNER`; shows full winner answer with model/time/token metadata; displays judge narrative wordwrapped at 72 chars with empty-narrative fallback; shows error summary for agents with issues. Falls back to existing per-agent output format when debate is skipped (< 2 agents).

Files: `research.php`
Commit: `64d2fd3`

## Summary of Changes

### Modified Files

#### `config/arbitrator/config.json` (CREATED -- 40 lines)
Phase 4 config schema with scoring, judge, weights, diversity, and critique sections. References existing prompt template paths. API key left empty (user-supplied at deployment).

#### `src/Arbitrator/Arbitrator.php` (MODIFIED -- +56 lines)
- `loadConfig()`: Phase 4 section type validation (array check with fallback)
- `evaluateAnswers()`: prompt template placeholder warning
- `parseScoringJson()`: brace-matching extraction before json_decode
- `computeDiversityScores()`: empty-answer skip logging + n-gram threshold check
- `computeAverageCritiqueScores()`: neutral 0.5 default for empty Round 2
- `executeRound2Critique()`: timeout fallback chain with info logging
- `research()`: Round 2 empty-result fallback with default neutral critiques

#### `src/Agent/ResearchAgent.php` (MODIFIED -- +26 lines)
- `critique()`: full JSON structure validation after LLM response (array, score field, numeric type, 0-10 range)

#### `research.php` (MODIFIED -- +115/-14 lines)
- Debate-aware output: score table (quality, critique avg, diversity bonus, weighted total)
- Winner section with full answer, model, timing, token usage
- Judge narrative with wordwrap and empty fallback
- Error summary for failed agents
- Fallback per-agent format when debate skipped

## Deviations from Plan

None -- plan executed exactly as written.

## Threat Flags

None -- no new security-relevant surface introduced. All modified paths are within the existing CLI output and internal validation flows. Mitigations covered: T-04-07 (score clamping), T-04-08 (narrative fallback), T-04-09 (critique JSON validation), T-04-11 (timeout fallback chain).

## Known Stubs

None -- all wire paths are connected. The config.json has empty `api_key` (required by design -- user provides at deploy time).

## Self-Check: PASSED

- [x] All files pass `php -l` syntax check
- [x] config/arbitrator/config.json is valid JSON with all Phase 4 sections
- [x] scoring-prompt.txt, critique-template.txt, judge-prompt.txt exist at paths referenced in config
- [x] ResearchAgent::critique() validates JSON structure before returning
- [x] parseScoringJson() handles braces, code fences, and range checking
- [x] computeDiversityScores() applies n-gram threshold before penalty
- [x] computeAverageCritiqueScores() returns neutral default when Round 2 empty
- [x] research.php displays score table when $debateResult non-null
- [x] research.php falls back to per-agent output when $debateResult null
- [x] Round 2 timeout config falls back to agent_timeout successfully
- [x] Empty narrative fallback message displayed
- [x] Error summary section in output works correctly
- [x] All 17 PHPUnit tests pass (28 assertions, 3 incomplete stubs)
- [x] 3 commits created (one per task)
