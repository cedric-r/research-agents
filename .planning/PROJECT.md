# ResearchAgents

## What This Is

A multi-agent research system where an arbitrator distributes research questions to several AI agents, each with different models and personalities. Agents produce independent answers, then debate each other's findings, and the arbitrator selects the best result. Runs as an interactive REPL (CLI + web) with detailed logging and file-per-session storage.

## Core Value

Get the best possible research answer by having multiple AI agents with diverse models and sources work in parallel, debate their findings, and converge on the optimal result — with full traceability of how they got there.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] **ARB-01**: Arbitrator reads configuration from its own folder (config file, personality)
- [ ] **ARB-02**: Arbitrator distributes research question to all configured agents
- [ ] **ARB-03**: Arbitrator enforces per-step timeout, instructs agents to stop and provide best answer
- [ ] **ARB-04**: Arbitrator evaluates Round 1 answers from all agents
- [ ] **ARB-05**: Arbitrator facilitates Round 2 debate — agents see each other's answers and critique
- [ ] **ARB-06**: Arbitrator selects the best final answer after debate
- [ ] **ARB-07**: Arbitrator presents final answer to user with reasoning trace
- [ ] **AGENT-01**: Agent reads configuration from its own folder (model provider, model, API key, SOUL.md personality, preferences)
- [ ] **AGENT-02**: Agent researches question using its LLM model knowledge
- [ ] **AGENT-03**: Agent can perform web searches via configurable search API provider
- [ ] **AGENT-04**: Agent can search scientific paper sources (arxiv, semantic scholar, etc.)
- [ ] **AGENT-05**: Agent respects timeout signal and returns best partial answer
- [ ] **AGENT-06**: Agent can participate in debate round — read peer answers, provide critique
- [ ] **SHELL-01**: User starts interactive CLI REPL — types question, gets answer
- [ ] **SHELL-02**: User starts interactive web REPL — submits question via browser, sees results
- [ ] **CONFIG-01**: Each agent/arbitrator has own directory with config files
- [ ] **CONFIG-02**: Config files specify model provider, model name, API key
- [ ] **CONFIG-03**: SOUL.md defines agent personality and behavior instructions
- [ ] **CONFIG-04**: Preferences file defines tool access (search API provider, etc.)
- [ ] **LOG-01**: System logs all activities with timestamps — job distribution, responses, debate rounds, decisions
- [ ] **STORE-01**: Each research session saved as markdown file with full transcript

### Out of Scope

- Persistent database storage — file-based sessions sufficient for v1
- User authentication / multi-user support — single-user system
- Scheduled/automated research — manual trigger only
- Plugin system for custom agent capabilities — capabilities defined in config/personality

## Context

Built in Vanilla PHP — no framework. The system is a set of PHP scripts orchestrated by the arbitrator, each agent running as a subprocess or managed process. Web interface uses a built-in PHP web server for the interactive REPL.

The codebase is fresh — no existing PHP code. `.serena/` directory contains IDE metadata only.

The user drives this project and has deep software engineering expertise.

## Constraints

- **Language**: PHP (Vanilla — no Laravel/Symfony)
- **Storage**: File-per-session markdown, no database
- **Interface**: Interactive REPL — both CLI and web
- **Config**: File-based, each agent/arbitrator has own directory
- **Logging**: Detailed timestamped activity logs
- **API Keys**: Stored in config files per agent

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Vanilla PHP (no framework) | Maximum control, minimal deps for agent orchestration | ✅ Implemented — zero Composer deps for core, 18 source files |
| File-per-session storage | Simple, traceable, human-readable | ✅ Implemented — full markdown transcripts with frontmatter |
| Interactive REPL (CLI + Web) | Flexibility — dev in terminal, use in browser | ✅ Implemented — repl.php + public/index.php with SSE |
| Two-round debate (independent → critique) | Balances independent thought with collaborative refinement | ✅ Implemented — Phase 4 critique protocol |
| Config + SOUL.md per agent | Clear separation of mechanics from personality | ✅ Implemented — 4 agents configured |
| All three source types in v1 | Full capability from launch — model, web, papers | ✅ Implemented — LLM, Brave web search, arXiv/Semantic Scholar |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-06-13 after initialization*
