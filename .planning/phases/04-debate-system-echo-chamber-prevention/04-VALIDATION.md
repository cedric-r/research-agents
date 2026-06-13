# Phase 4: Debate System & Echo Chamber Prevention - Validation

**Defined:** 2026-06-13

## Test Architecture

### Tooling
- PHPUnit ^12.0 via Composer (dev dependency only)
- No mocking framework — hand-mocked LLM responses via JSON fixtures

### Test Categories

| Category | Scope | Location | Count |
|----------|-------|----------|-------|
| Unit | DiversityAnalyzer n-gram computation | `tests/Arbitrator/DiversityAnalyzerTest.php` | 9 tests |
| Unit | ResearchAgent::critique() prompt construction | `tests/Agent/ResearchAgentTest.php` | 2 tests |
| Unit | Arbitrator scoring JSON parser | `tests/Arbitrator/ArbitratorTest.php` | 3 tests |
| Unit | Arbitrator diversity score computation | `tests/Arbitrator/ArbitratorTest.php` | 2 tests |
| Unit | Arbitrator two-stage selection | `tests/Arbitrator/ArbitratorTest.php` | 2 tests |
| Unit | Arbitrator final selection output format | `tests/Arbitrator/ArbitratorTest.php` | 2 tests |

### DiversityAnalyzerTest (9 tests)

1. `testWordNgramsBasic` — Word-level n-gram extraction from normal text
2. `testWordNgramsEmpty` — Empty string returns empty array
3. `testWordNgramsShortText` — Text shorter than n-gram size returns empty array
4. `testOverlapCoefficientIdentical` — Identical texts return 1.0
5. `testOverlapCoefficientDisjoint` — Completely different texts return 0.0
6. `testOverlapCoefficientPartial` — Partially overlapping texts return correct coefficient
7. `testOverlapCoefficientNormalized` — Result clamped to [0.0, 1.0]
8. `testComputeDiversityBonus` — Bonus scaled correctly by factor (0 to max_factor)
9. `testDefaultNgramSize` — Default n=3 used when n not specified

### ResearchAgentTest: critique() (2 tests)

1. `testCritiquePromptStructure` — Prompt contains question, anonymized peer answers, critique fields
2. `testCritiqueTimeout` — Returns partial critique when deadline exceeded

### ArbitratorTest (9 stubs, expandable)

Focus on scoring, diversity, selection, output:

1. `testEvaluateAnswersReturnsScores` — evaluateAnswers() returns per-agent score array
2. `testScoringJsonParsingValid` — Parse well-formed scoring JSON
3. `testScoringJsonParsingMalformed` — Returns null/throws on malformed JSON
4. `testComputeDiversityScores` — computeDiversityScores() returns per-agent similarity
5. `testDiversityScoreZeroForSingle` — Single answer gets 0 diversity penalty
6. `testStage1WeightedFormula` — Weighted formula produces correct ranking (quality * 0.50 + critique * 0.30 + diversity * 0.20)
7. `testStage2JudgeSelection` — LLM judge returns winner with narrative
8. `testDebateResultStructure` — getDebateResult() contains winner, score_table, narrative keys
9. `testScoreTableFormat` — Score table includes quality, critique_avg, diversity_bonus, weighted_total per agent

### Integration / E2E

Not covered by unit tests. Run manually after plan execution:

- `php research.php "question"` with multiple agents configured → verify Round 1 scores displayed
- Verify Round 2 critique prompts fire (check logs for critique prompt submission)
- Verify final answer selected and score table rendered
- Verify diversity bonus visible in score table

### Sampling Rate

- **Per task commit:** `php vendor/bin/phpunit tests/Arbitrator/ --no-coverage`
- **Pre-merge:** `php vendor/bin/phpunit` (full suite)
- **CI:** Full suite on push

## Requirement-to-Test Map

| Req | Test(s) | Verification |
|-----|---------|--------------|
| ORCH-05 | testEvaluateAnswersScores, testScoringJsonParsingValid/Malformed | Arbitrator evaluates Round 1 answers |
| ORCH-06 | Integration: verify log shows answers shared with agents | Arbitrator shares answers |
| ORCH-07 | testCritiquePromptStructure, testCritiqueTimeout | Agents produce structured critique |
| ORCH-08 | testStage1WeightedFormula, testStage2JudgeSelection | Best answer selected |
| ORCH-09 | testDebateResultStructure, testScoreTableFormat | Score table + narrative in output |
| D-03 | All DiversityAnalyzerTest (9 tests), testComputeDiversityScores | Diversity-weighted scoring |

## Coverage Targets

| Metric | Target |
|--------|--------|
| DiversityAnalyzer | 100% line coverage (pure logic, no I/O) |
| ResearchAgent::critique() | 90%+ (LLM call unmocked, prompt construction tested) |
| Arbitrator (scoring, selection, output) | 80%+ (LLM-dependent paths tested with fixtures) |
| Overall Phase 4 | 85%+ |

---
*Phase: 04-Debate System & Echo Chamber Prevention*
