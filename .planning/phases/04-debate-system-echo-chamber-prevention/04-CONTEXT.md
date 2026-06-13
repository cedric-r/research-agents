# Phase 4: Debate System & Echo Chamber Prevention - Context

**Gathered:** 2026-06-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Deliver the full 2-round debate protocol: evaluate Round 1 independent answers using LLM-based quality scoring, distribute all answers with scores to agents for structured peer critique in Round 2, collect structured critiques, select the best final answer with diversity-weighted scoring preventing echo chamber convergence, and present the winner with both score breakdown and narrative reasoning trace.

Builds on Phase 3's Arbitrator (Round 1 parallel execution, pcntl_fork, temp file IPC, per-step timeout) and ResearchAgent (single-agent LLM call with deadline checking).

</domain>

<decisions>
## Implementation Decisions

### Round 1 Quality Evaluation (ORCH-05)
- **D-01:** **LLM-based scoring rubric** — Arbitrator calls its own configured LLM with a structured rubric to score each Round 1 answer on relevance, completeness, citation quality, clarity, and confidence. Rubric produces a numerical score per dimension plus a composite quality score. The scoring rubric is defined in Arbitrator config (reusable, editable without code changes).

### Round 2 Critique Format (ORCH-06, ORCH-07)
- **D-02:** **Structured critique template** — Each agent produces a peer critique with these fields:
  - Strengths (2-3 per peer answer)
  - Weaknesses / Errors (2-3 per peer answer)
  - Factual Accuracy Check (flag specific claims that are incorrect or unsupported)
  - Gaps & Missing Information (what the peer answer missed)
  - Overall Score (numerical score per peer answer for Arbitrator's selection formula)
- Template fields are defined in a shared config file or section — agents get them injected into their critique system prompt alongside SOUL.md.

### Echo Chamber Prevention (Success Criterion 4)
- **D-03:** **Diversity-weighted scoring** — Arbitrator computes n-gram overlap (2-4 word phrases) between all Round 1 answers. Answers that are semantically similar to the group consensus receive a score penalty. Unique insight contributions receive a bonus. The diversity bonus is a configurable factor in the Arbitrator scoring formula. No extra LLM calls required — similarity computation is text-level.

### Answer Selection (ORCH-08)
- **D-04:** **Two-stage selection** — Stage 1: weighted scoring formula (quality score + critique score + diversity bonus) narrows to top 2-3 candidates. Stage 2: LLM judge (Arbitrator's LLM with all data as context) picks the winner with written reasoning.

### Reasoning Trace (ORCH-09)
- **D-05:** **Both score breakdown table AND narrative explanation** — Final output includes a machine-readable score table per agent (quality score, critique scores, diversity bonus, weighted total) followed by the LLM judge's narrative explanation of why this answer won. Full transparency: the data AND the reasoning.

### Claude's Discretion
- Exact scoring formula weights (quality vs critique vs diversity) — planner determines proportions
- Critique template integration mechanism (SOUL.md section vs separate debate config file)
- ResearchAgent::critique() method signature and prompt construction details
- Round 2 execution model (fork batch vs sequential within existing child process)
- Arbitrator config schema for LLM evaluation model (separate model or re-use arbitrator default)
- N-gram overlap implementation specifics (n-gram size, normalization, threshold for "similar")
- Sorting method for Stage 1 candidate narrowing

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Context
- `.planning/PROJECT.md` — Project overview, core value, constraints
- `.planning/REQUIREMENTS.md` — Full v1 requirements with traceability
- `.planning/ROADMAP.md` — Phase structure, dependencies, success criteria
- `.planning/STATE.md` — Current project state, active risk register

### Phase 4 Requirements
- `.planning/REQUIREMENTS.md` § Orchestration — ORCH-05 through ORCH-09 (debate protocol)

### Prior Phase Decisions (inherited)
- `.planning/phases/03-orchestration-pipeline/03-CONTEXT.md` — D-01..D-16 (Arbitrator design, pcntl_fork, temp file IPC, timeout architecture)
- `.planning/phases/02-agent-runtime-tool-integration/02-CONTEXT.md` — D-01..D-12 (AgentManager discovery, tool registry, HttpHelper timeouts)
- `.planning/phases/01-foundation-single-agent-baseline/01-CONTEXT.md` — D-01..D-15 (config format, LlmClient, SOUL.md, logging)

### Existing Implementation
- `src/Arbitrator/Arbitrator.php` — Round 1 parallel execution. Extended for Round 2 orchestration, quality evaluation, final selection
- `src/Agent/ResearchAgent.php` — Single-agent LLM call with deadline checking. Needs a `critique()` method for Round 2
- `src/Agent/AgentManager.php` — Agent discovery via `getAgentConfigs()`. Used by Arbitrator for Round 2 agent configuration
- `research.php` — CLI entry point. Needs final answer selection output format (score table + narrative)
- `config/agents/*/config.json` — Agent configs. May need debate-related config fields
- `config/agents/*/SOUL.md` — Agent personality. Critique behavior guidance could be added here or in separate debate config

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Arbitrator::$llm** — Arbitrator already has its own config and could instantiate an LLM client for scoring (currently not instantiated — Phase 3 didn't need it). Planner decides evaluation LLM config location.
- **ResearchAgent::research()** — Template for the `critique()` method: same pattern (build system prompt with soul + tool context, check deadline, call LLM, return structured result).
- **Temp file IPC** — Phase 3's child-to-parent result mechanism (write to sys_get_temp_dir(), parent reads). Round 2 critiques use the same pattern, potentially extended result schema.
- **Arbitrator::executeBatchParallel()** — Fork pattern reusable for Round 2 agent execution.

### Established Patterns
- Vanilla PHP, no external dependencies — text-level n-gram comparison without libraries
- Config per directory under `config/` — Arbitrator config for scoring weights, LLM judge model
- Process isolation via pcntl_fork — Round 2 critiques also run in forked children
- Structured result arrays — Round 2 result shape extends the Phase 3 schema

### Integration Points
- `Arbitrator::research()` → after Round 1 results collected → call `evaluateAnswers()` → share answers + scores → fork Round 2 agents with `critique()` → collect critiques → `selectFinalAnswer()` → return with trace
- `ResearchAgent::critique(string $question, array $peerAnswers, ?float $deadline)` — new method alongside `research()`
- `research.php` — after `$arbitrator->research()` returns, display final selection + score table + narrative (instead of today's per-agent dump)
- Arbitrator config → new fields: LLM judge model, scoring formula weights, n-gram threshold, diversity bonus factor

</code_context>

<specifics>
## Specific Ideas

- Scoring rubric dimensions: relevance (0-10), completeness (0-10), citation quality (0-10), clarity (0-10), confidence (0-10). Composite = weighted average.
- Diversity bonus formula: `final_score = quality_score + critique_score + (1 - max_similarity_penalty) * diversity_factor`. Configurable diversity_factor in Arbitrator config.
- Stage 1 cutoff: top 50% or top 2, whichever is larger (minimum 2 candidates for Stage 2).
- LLM judge prompt: provide all Round 1 answers with their scores and critiques, ask "which answer is best and why?" with instruction to write a narrative paragraph.
- Score table format: align with existing `---` separator and `printf` formatting pattern in research.php.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope.

</deferred>

---

*Phase: 4-Debate System & Echo Chamber Prevention*
*Context gathered: 2026-06-13*
