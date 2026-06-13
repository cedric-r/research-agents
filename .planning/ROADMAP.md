# Roadmap: ResearchAgents

## Overview

Build a multi-agent research and debate system in Vanilla PHP, starting with a single-agent baseline to validate value, then adding tools, orchestration, debate, and presentation layers. Evolves from a single LLM answer in Phase 1 to a full multi-agent debate with CLI and Web REPLs in Phase 5.

## Phases

### Phase 1: Foundation & Single-Agent Baseline

**Goal**: Config loading, logging, and one agent answering an LLM query via CLI.
**Mode**: standard
**Depends on**: None
**Requirements**: CONF-01, CONF-02, CONF-03, CONF-04, CONF-05, CONF-06, CONF-07, TOOL-01, LOG-01, LOG-02
**Success Criteria** (what must be TRUE):

  1. User can configure one agent with its own directory containing config.json, SOUL.md, and preferences.json
  2. User can run a research query with `php research.php "question"` and get an LLM-generated answer
  3. Config loader validates required fields and reports errors at startup
  4. Logger records system activities with timestamps and correlation IDs
  5. User can run a health check command (`php bin/check-config`) verifies all configs valid

**Plans**: 3 plans

Plans:

- [x] `01-01-PLAN.md` — Walking Skeleton Foundation: project structure, SKELETON.md, agent config files (CONF-01..05)
- [x] `01-02-PLAN.md` — Config Loader and Logger: autoloader, config loading with aggregate validation, channel-prefixed logger (CONF-06, CONF-07, LOG-01, LOG-02)
- [x] `01-03-PLAN.md` — LLM Integration and CLI: LlmClient via curl, ResearchAgent, research.php CLI, bin/check-config (TOOL-01)

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
  5. HTTP socket-level timeouts are enforced on all external API calls -- a hanging API does not freeze the system

**Plans**: 4 plans
Plans:

- [ ] `02-01-PLAN.md` — Infrastructure: HttpHelper, ToolRegistry, LlmClient refactor to centralized HTTP (TOOL-05, TOOL-08)
- [ ] `02-02-PLAN.md` — Search Tools: WebSearch (Brave), AcademicSearch (arXiv+Semantic Scholar), ResearchAgent tool wiring (TOOL-02, TOOL-03, TOOL-04, TOOL-06, TOOL-07)
- [ ] `02-03-PLAN.md` — AgentManager: multi-agent discovery, lifecycle, multi-agent CLI wiring (CONF-08)
- [ ] `02-04-PLAN.md` — Gap Closure: per-agent error isolation in AgentManager, 6 code review fixes (CONF-08, TOOL-05, TOOL-07, TOOL-08)

### Phase 3: Orchestration Pipeline

**Goal**: Arbitrator discovers all agents, distributes research questions for independent Round 1 answers, enforces per-step timeouts, and collects structured outputs with the 4-layer timeout architecture.
**Mode**: mvp
**Depends on**: Phase 2
**Requirements**: ORCH-01, ORCH-02, ORCH-03, ORCH-04, ORCH-10

**Success Criteria** (what must be TRUE):

  1. Arbitrator discovers all configured agents via AgentManager and distributes a research question to every agent
  2. Arbitrator enforces per-step timeout -- any agent that exceeds the deadline returns its best partial answer
  3. Arbitrator collects Round 1 independent answers from all responding agents and presents them together
  4. The 4-layer timeout architecture (PHP max execution, HTTP socket, stream-idle watchdog, cooperative agent-step) prevents any single hung operation from blocking the entire session
  5. User can run a multi-agent research session and see all agents' independent answers collected and returned

**Plans**: TBD

### Phase 4: Debate System & Echo Chamber Prevention

**Goal**: Full 2-round debate protocol with quality evaluation of Round 1 answers, structured peer critique in Round 2, reasoned answer selection by the arbitrator, and diversity encouragement to prevent echo chamber convergence.
**Mode**: mvp
**Depends on**: Phase 3
**Requirements**: ORCH-05, ORCH-06, ORCH-07, ORCH-08, ORCH-09

**Success Criteria** (what must be TRUE):

  1. Arbitrator evaluates all Round 1 answers using defined quality criteria and shares them with agents
  2. Agents peer-review each other's answers and produce structured critiques in Round 2
  3. Arbitrator selects best final answer after Round 2 with written reasoning
  4. Echo chamber prevention: diversity-weighted selection penalizes agents that agree without adding value

**Plans**: TBD

### Phase 5: Storage & Presentation

**Goal**: Sessions are persisted as markdown transcripts for full traceability, CLI REPL provides interactive question input, and web REPL provides browser-based real-time progress via SSE.
**Mode**: mvp
**Depends on**: Phase 4
**Requirements**: PERS-01, PERS-02, PERS-03, PERS-04, LOG-03, CLI-01, CLI-02, CLI-03, CLI-04, CLI-05, WEB-01, WEB-02, WEB-03, WEB-04, WEB-05

**Success Criteria** (what must be TRUE):

  1. Each research session creates a new session with UUID, timestamp, and full transcript
  2. CLI REPL shows real-time progress display indicating which phase is active and which agents are responding, with ANSI formatting
  3. User starts the web REPL via `php -S`, submits a question through a browser form, and sees results streamed via SSE with per-agent completion status
  4. Each research session is saved as a markdown file in `sessions/` with UUID, timestamp, and full transcript (question, answers, debate content, and final selection with reasoning)
  5. User can view past sessions from both CLI (replay command) and web (session list page)

**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation & Single-Agent Baseline | 3/3 | Complete | 2026-06-13 |
| 2. Agent Runtime & Tool Integration | 3/4 | Executing | - |
| 3. Orchestration Pipeline | TBD | Not started | - |
| 4. Debate System & Echo Chamber Prevention | TBD | Not started | - |
| 5. Storage & Presentation | TBD | Not started | - |
