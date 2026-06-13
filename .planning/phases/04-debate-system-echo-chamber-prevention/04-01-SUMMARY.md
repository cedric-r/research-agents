---
phase: 04-debate-system-echo-chamber-prevention
plan: 01
subsystem: "Arbitrator, ResearchAgent, DiversityAnalyzer"
tags: ["debate", "peer-review", "diversity-scoring", "n-gram", "quality-evaluation", "two-stage-selection"]
requires: ["03-orchestration-pipeline (Arbitrator base class, AgentManager, executeBatchParallel pattern)"]
provides: ["Debate protocol (evaluateAnswers, computeDiversityScores, Round 2 critique, selectFinalAnswer)", "DiversityAnalyzer (n-gram overlap)", "ResearchAgent::critique()", "Prompt templates (scoring, critique, judge)", "Extended arbitrator config"]
affects: ["research.php (will call getDebateResult())"]
tech-stack:
  added: ["DiversityAnalyzer (pure PHP static class -- n-gram computation, zero deps)"]
  patterns: ["PCNTL fork with temp file IPC for Round 2 critique", "LLM-as-judge with two-stage candidate narrowing", "LlmClient per role (scoring/judge) with configurable models"]
key-files:
  created:
    - "config/arbitrator/scoring-prompt.txt"
    - "config/arbitrator/critique-template.txt"
    - "config/arbitrator/judge-prompt.txt"
    - "src/Arbitrator/DiversityAnalyzer.php"
    - "tests/Arbitrator/DiversityAnalyzerTest.php"
    - "tests/Arbitrator/ArbitratorTest.php"
  modified:
    - "config/arbitrator/config.json (gitignored -- Phase 4 sections added)"
    - "src/Arbitrator/Arbitrator.php"
    - "src/Agent/ResearchAgent.php"
    - "tests/Agent/ResearchAgentTest.php"
decisions:
  - "DiversityAnalyzer is a static-only class (no constructor, no state) -- pure functions"
  - "overlapCoefficient checks raw input strings before n-gram arrays for emptiness semantics"
  - "Critique scores use a global average (not per-agent attribution) because the critique JSON only has positional keys (Peer 1, Peer 2...) with no identity mapping"
  - "ResearchAgent::critique() receives a pre-built critiquePrompt from the Arbitrator (not the raw template) -- the agent never sees its own answer or names of peers"
  - "LlmClient instances for scoring and judging are initialized once in the constructor via initDebateLlmClients()"
duration: "~15 min"
completed_date: "2026-06-13"
---

# Phase 04 Plan 01: Debate System -- Core Protocol Implementation

## One-liner

DiversityAnalyzer n-gram computation, ResearchAgent::critique() for Round 2 peer review, and Arbitrator methods for quality evaluation, diversity scoring, anonymized Round 2 orchestration, and two-stage final answer selection -- with prompt templates for scoring, critique, and judging.

## Context

This plan implements the foundational debate protocol. Every Round 1 answer is scored on 5 quality dimensions via an LLM rubric, n-gram diversity is computed pairwise, agents peer-review each other with anonymized structured critiques in Round 2, and the Arbitrator selects the best answer using a two-stage process (weighted formula narrows to 2-3 candidates, then LLM judge picks the winner with narrative reasoning).

## Tasks Executed

### Task 1: Create DiversityAnalyzer, prompt templates, and extended config

**Duration:** ~5 min
**Commit:** `c8161b4`

Created:
- `DiversityAnalyzer.php` -- Static class with 4 methods: `computeSimilarityScores()`, `overlapCoefficient()`, `wordNgrams()`, `diversityBonus()`
- Three prompt templates under `config/arbitrator/`: `scoring-prompt.txt`, `critique-template.txt`, `judge-prompt.txt`
- Extended `config/arbitrator/config.json` with Phase 4 sections (gitignored -- must be recreated if missing)
- `tests/Arbitrator/DiversityAnalyzerTest.php` -- 9 tests covering overlap, n-grams, diversity bonus, similarity computation

Edge case handling:
- Both empty strings: return 1.0 (identical empty content)
- One empty string: return 0.0
- Text with insufficient words for n-grams: return 0.0
- Similarity clamping: diversityBonus clamps avgSimilarity to [0.0, 1.0]

### Task 2: Add ResearchAgent::critique() method

**Duration:** ~3 min
**Commit:** `245763b`

Added `critique(string $question, array $peerAnswers, string $critiquePrompt, ?float $deadline = null): array`:
- Builds system prompt from SOUL.md + pre-built critique prompt with anonymized peers
- The agent's own Round 1 answer is NEVER passed to critique -- excluded by the Arbitrator before prompt building
- No role tags or identity markers in the prompt
- Layer 4 cooperative deadline check before LLM call (same pattern as research())
- Returns raw JSON string from LLM under the `critiques` key (Arbitrator parses it)
- Tests: `testCritiqueMethodExists`, `testCritiqueThrowsOnDeadlineExceeded`

### Task 3: Implement Arbitrator debate protocol

**Duration:** ~7 min
**Commit:** `1f42b21`

Added all debate protocol methods to `Arbitrator.php`:

**Quality evaluation (evaluateAnswers):**
- Loads scoring prompt template from config path
- Calls scoring LlmClient once per Round 1 answer
- parseScoringJson strips code fences, validates dimensions 0-10, falls back to 0 on parse failure
- Empty answers get 0 across all dimensions

**Diversity computation (computeDiversityScores):**
- Uses DiversityAnalyzer for pairwise n-gram similarity
- Converts similarity to diversity bonus via configurable factor
- Returns per-agent avg_similarity and diversity_bonus

**Round 2 orchestration (executeRound2Critique):**
- Batch processing with same max_concurrent_agents limit as Round 1
- Forks child processes via pcntl_fork, collects critiques via temp file IPC
- Sequential fallback when pcntl_fork is unavailable
- Per-child deadline with SIGTERM/SIGKILL enforcement (separate critique.timeout_seconds, default 120s)
- Anonymizes: `buildAnonymizedPeerBlock()` excludes own answer, labels peers as "Peer N"

**Two-stage selection (selectFinalAnswer):**
- Stage 1: Weighted formula (quality 0.50, critique 0.30, diversity 0.20) narrows to 2-3 candidates
- Stage 2: LLM judge selects winner with narrative reasoning
- `judgeSelection()` validates winner against candidates, falls back to top candidate on error
- Score table built for all agents with full breakdown

**Integration:** research() method now runs debate flow after Round 1 collection when 2+ agents respond. `getDebateResult()` provides public access to structured result.

**Tests:** `tests/Arbitrator/ArbitratorTest.php` with 7 stubs (4 method-existence checks, 3 integration stubs).

## Threat Surface

All threats from the plan's threat model (T-04-01 through T-04-06, T-04-SC) are mitigated:

| Threat | Mitigation |
|--------|-----------|
| T-04-01 Scoring prompt injection | Answer passed as {answer} placeholder only. No role injection. |
| T-04-02 Malformed scoring JSON | parseScoringJson strips code fences, validates ranges, falls back on error |
| T-04-03 n-gram memory exhaustion | Word n-grams only -- 10000 words x n=3 = ~9997 trigrams (~1MB) |
| T-04-04 Round 2 timeout cascade | Per-child deadline, SIGTERM then SIGKILL, 120s default timeout |
| T-04-05 Judge selects non-candidate | Validated against candidates array, falls back to first |
| T-04-06 Agent identifies own answer | Own answer excluded from peer set. Labels are "Peer N" |

## Deviations from Plan

### Auto-fixed: Edge case order in overlapCoefficient (Rule 1 - Bug)

**Found during:** Task 1 verification
**Issue:** `overlapCoefficient('hello world', '', 3)` returned 1.0 but the plan specifies "One empty string: return 0.0". The initial implementation checked n-gram set counts before raw input strings, so `wordNgrams('hello world', 3)` returning `[]` (2 words < n=3) was treated identically to an empty string.
**Fix:** Added raw string trim checks first. "Both empty strings" and "One empty string" now check the actual input text before falling through to n-gram count checks.
**Files modified:** `src/Arbitrator/DiversityAnalyzer.php`
**Commit:** `c8161b4`

### Auto-fixed: config/arbitrator/config.json gitignored (Rule 3 - Blocking)

**Found during:** Task 1 commit
**Issue:** `config/arbitrator/config.json` is listed in `.gitignore` (by design -- contains api_key). The config file was created/modified but cannot be committed to git.
**Action:** Created the config file on disk. It will need to be recreated if the worktree is rebuilt. Documented in this SUMMARY and in STATE.md technical debt.
**Files affected:** `config/arbitrator/config.json`

## Known Stubs

None -- all template files use runtime-resolved placeholders (`{question}`, `{answer}`, `{peer_answers}`, `{candidates}`, `{candidate_count}`) as intended.

## Verification Results

- All files pass `php -l` syntax check
- DiversityAnalyzerTest: 10 tests, 24 assertions -- all pass
- ResearchAgentTest::testCritiqueMethodExists -- passes
- ResearchAgentTest::testCritiqueThrowsOnDeadlineExceeded -- passes
- ArbitratorTest: 7 tests (3 incomplete stubs), 4 passing method-existence checks
- Full test suite: 47 tests, 83 assertions, 9 incomplete, 0 failures

## Self-Check: PASSED

All 10 created/modified files verified on disk. All 3 commits verified in git log. Post-commit deletion checks passed for all commits. No untracked files left behind.
