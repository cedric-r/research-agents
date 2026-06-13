# Phase 4: Debate System & Echo Chamber Prevention - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-13
**Phase:** 4-Debate System & Echo Chamber Prevention
**Areas discussed:** Quality Criteria for Round 1 Eval, Round 2 Critique Format, Echo Chamber Prevention, Answer Selection & Reasoning Trace

---

## Quality Criteria for Round 1 Evaluation

| Option | Description | Selected |
|--------|-------------|----------|
| LLM-based scoring rubric | Arbitrator calls an LLM with a structured rubric — scores each answer on relevance, completeness, citations, clarity. Most accurate, costs N LLM calls per round. | ✓ |
| Rule-based heuristics | Score by metadata: answer length, token count, response time, citation presence, formatting. Zero extra API cost, misses semantic quality. | |
| Hybrid — filter then score | Quick heuristics filter obviously weak answers first, then LLM rubric scores the remaining. Balances cost and evaluation depth. | |

**User's choice:** LLM-based scoring rubric
**Notes:** Rubric defined in Arbitrator config (reusable, editable without code changes).

---

## Round 2 Critique Format

| Option | Description | Selected |
|--------|-------------|----------|
| Structured critique template | Agents fill a template: strengths (2-3), weaknesses/errors (2-3), missing info, overall assessment. Consistent and comparable across agents. | ✓ |
| Free-form peer review | Each agent critiques in their own style, guided by SOUL.md. Natural per personality, harder to compare across agents. | |
| Adversarial debate | Agents must specifically find factual errors, logical flaws, and gaps in each peer answer. More rigorous, risk of negative-only bent. | |

**User's choice:** Structured critique template
**Follow-up — Template fields (multiSelect):** Strengths & Weaknesses (2-3 each), Factual Accuracy Check, Gaps & Missing Info, Overall Score (numerical)

---

## Echo Chamber Prevention

| Option | Description | Selected |
|--------|-------------|----------|
| Diversity-weighted scoring | Arbitrator computes semantic similarity between answers. Highly similar = score penalty. Unique insights = bonus. No extra LLM calls. | ✓ |
| Mandatory distinct criticism | Critique prompt requires each agent to find critiques no other agent identified. Enforces unique contribution. | |
| Explicit stance diversity | Critique prompt includes 'consider the contrarian view'. Agents must state where they disagree with emerging consensus. | |
| Combined | Both diversity-weighted scoring AND mandatory distinct criticism in prompts. Two layers reinforcing each other. | |

**User's choice:** Diversity-weighted scoring
**Follow-up — Similarity metric:** N-gram overlap (2-4 word phrases)

---

## Answer Selection & Reasoning Trace

| Option | Description | Selected |
|--------|-------------|----------|
| Weighted scoring formula | Combine quality score + critique scores + diversity bonus. Highest wins. Deterministic, explicit formula you can inspect. Reasoning = score breakdown. | |
| LLM judge final call | Send all answers + critiques + scores to an LLM for final judgment. Less deterministic, but leverages natural language reasoning for nuanced comparison. | |
| Two-stage — filter then judge | Weighted scoring narrows to top 2-3 candidates, then LLM judge picks the winner with written reasoning. Balances formula objectivity with LLM nuance. | ✓ |

**User's choice:** Two-stage — filter then judge
**Follow-up — Reasoning trace format:** Both score breakdown table AND narrative explanation

---

## Claude's Discretion

- Exact scoring formula weights (quality vs critique vs diversity)
- Critique template integration mechanism (SOUL.md section vs separate debate config file)
- ResearchAgent::critique() method signature and prompt construction details
- Round 2 execution model (fork batch vs sequential within existing child process)
- Arbitrator config schema for LLM evaluation model
- N-gram overlap implementation specifics (n-gram size, normalization, threshold)
- Sorting method for Stage 1 candidate narrowing

## Deferred Ideas

None — discussion stayed within phase scope.
