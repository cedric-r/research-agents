# Roadmap: ResearchAgents

## Overview

Build a multi-agent research and debate system in Vanilla PHP, starting with a single-agent baseline to validate the core value, then adding tools, orchestration, debate, and presentation layers. Each phase delivers a complete, user-verifiable increment — from a single LLM answer in Phase 1 to a full multi-agent debate with CLI and Web REPLs in Phase 5.

## Phases

- [ ] **Phase 1: Foundation & Single-Agent Baseline** - Config loading, logging, and one agent answering via LLM to validate core value before multi-agent complexity
- [ ] **Phase 2: Agent Runtime & Tool Integration** - LlmClient, web search, paper search (arXiv + Semantic Scholar), AgentManager with timeout architecture
- [ ] **Phase 3: Orchestration Pipeline** - Arbitrator distributes questions, enforces per-step timeouts, collects Round 1 answers with structured handoffs
- [ ] **Phase 4: Debate System & Echo Chamber Prevention** - Refined 2-round debate, quality evaluation, peer critique, reasoned answer selection with diversity mechanisms
- [ ] **Phase 5: Storage & Presentation** - Session persistence (markdown transcripts), CLI REPL with readline, Web REPL with SSE streaming

## Phase Details

### Phase 1: Foundation & Single-Agent Baseline
**Goal**: Config loading works, logging works, and a single agent can answer a research question via LLM call. Validates the core value proposition before multi-agent complexity.
**Mode**: mvp
**Depends on**: Nothing (first phase)
**Requirements**: CONF-01, CONF-02, CONF-03, CONF-04, CONF-05, CONF-06, CONF-07, TOOL-01, LOG-01, LOG-02
**Success Criteria** (what must be TRUE):
  1. User can create an agent config directory with config.json (provider, model, API key), SOUL.md (personality), and preferences.json (tool access) — system validates all files at startup
  2. User can run a single-agent research command that sends a question to an LLM and returns a coherent answer
  3. System logs all operations with timestamps and correlation IDs, separated by channel (agent, system, arbitrator)
  4. Config validation reports missing fields or invalid values with clear, actionable error messages at startup
  5. User can run a health check command (`php bin/check-config`) that verifies all configs are valid
**Plans**: TBD

### Phase 2: Agent Runtime & Tool Integration
**Goal**: Agents gain web search and scientific paper search capabilities through provider abstractions, managed by LlmClient and AgentManager with HTTP-level timeout enforcement.
**Mode**: mvp
**Depends on**: Phase 1
**Requirements**: CONF-08, TOOL-02, TOOL-03, TOOL-04, TOOL-05, TOOL-06, TOOL-07, TOOL-08
**Success Criteria** (what must be TRUE):
  1. AgentManager discovers all configured agents by scanning `config/agents/` directories at runtime
  2. Agent can perform web searches via a configurable search API provider and use results in its answer
  3. Agent can search arXiv and Semantic Scholar for scientific papers and citations, returning structured results
  4. LlmClient handles LLM API calls with model/provider selection, supporting at minimum the provider configured in Phase 1
  5. HTTP socket-level timeouts are enforced on all external API calls — a hanging API does not freeze the system
**Plans**: TBD

### Phase 3: Orchestration Pipeline
**Goal**: Arbitrator discovers all agents, distributes research questions for independent Round 1 answers, enforces per-step timeouts, and collects structured outputs with the 4-layer timeout architecture.
**Mode**: mvp
**Depends on**: Phase 2
**Requirements**: ORCH-01, ORCH-02, ORCH-03, ORCH-04, ORCH-10
**Success Criteria** (what must be TRUE):
  1. Arbitrator discovers all configured agents via AgentManager and distributes a research question to every agent
  2. Arbitrator enforces per-step timeout — any agent that exceeds the deadline returns its best partial answer
  3. Arbitrator collects Round 1 independent answers from all responding agents and presents them together
  4. The 4-layer timeout architecture (PHP max execution, HTTP socket, stream-idle watchdog, cooperative agent-step) prevents any single hung operation from blocking the entire session
  5. User can run a multi-agent research session and see all agents' independent answers collected and returned
**Plans**: TBD

### Phase 4: Debate System & Echo Chamber Prevention
**Goal**: Full 2-round debate protocol with quality evaluation of Round 1 answers, structured peer critique in Round 2, reasoned answer selection by the arbitrator, and enforced diversity mechanisms.
**Mode**: mvp
**Depends on**: Phase 3
**Requirements**: ORCH-05, ORCH-06, ORCH-07, ORCH-08, ORCH-09
**Success Criteria** (what must be TRUE):
  1. Arbitrator evaluates all Round 1 answers using defined quality criteria and shares them with all agents for Round 2 critique
  2. All agents read peer answers and produce structured critiques in Round 2 — typed schemas enforced at every handoff boundary
  3. Arbitrator selects the best final answer after Round 2 with explicit written reasoning explaining why this answer won
  4. User receives the final answer with a full reasoning trace showing the evaluation, debate exchanges, and selection rationale
  5. Echo chamber prevention is enforced: independent Round 1 before any peer exposure, diverse providers across agents, debate capped at 2 rounds
**Plans**: TBD

### Phase 5: Storage & Presentation
**Goal**: Sessions are persisted as markdown transcripts with full traceability, and users interact via CLI REPL and Web REPL with real-time progress streaming.
**Mode**: mvp
**Depends on**: Phase 4
**Requirements**: PERS-01, PERS-02, PERS-03, PERS-04, LOG-03, CLI-01, CLI-02, CLI-03, CLI-04, CLI-05, WEB-01, WEB-02, WEB-03, WEB-04, WEB-05
**Success Criteria** (what must be TRUE):
  1. User starts the interactive CLI REPL, types a research question, and receives the final answer with full reasoning trace
  2. CLI REPL shows real-time progress display indicating which phase is active and which agents are responding, with ANSI formatting
  3. User starts the web REPL via `php -S`, submits a question through a browser form, and sees results streamed via SSE with per-agent completion status
  4. Each research session is saved as a markdown file in `sessions/` with UUID, timestamp, and full transcript (question, all answers, debate content, final selection)
  5. User can view past sessions from both CLI (replay command) and web (session list page)
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Single-Agent Baseline | TBD | Not started | - |
| 2. Agent Runtime & Tool Integration | TBD | Not started | - |
| 3. Orchestration Pipeline | TBD | Not started | - |
| 4. Debate System & Echo Chamber Prevention | TBD | Not started | - |
| 5. Storage & Presentation | TBD | Not started | - |
