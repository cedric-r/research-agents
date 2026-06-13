# Requirements: ResearchAgents

**Defined:** 2026-06-13
**Core Value:** Get the best possible research answer by having multiple AI agents with diverse models and sources work in parallel, debate their findings, and converge on the optimal result — with full traceability of how they got there.

## v1 Requirements

Requirements for initial release (43 total). Each maps to roadmap phases.

### Agent Configuration

- [x] **CONF-01**: Each agent has own directory under `config/agents/{name}/`
- [x] **CONF-02**: Arbitrator has own config directory with its configuration
- [x] **CONF-03**: `config.json` contains model provider, model name, API key per agent
- [x] **CONF-04**: `SOUL.md` defines agent personality, identity, values, and behavior instructions
- [x] **CONF-05**: `preferences.json` defines tool access and agent-specific settings
- [x] **CONF-06**: Config loader supports JSON and PHP array config files — detects format by file extension
- [x] **CONF-07**: Config validation reports missing fields and invalid values at startup
- [ ] **CONF-08**: AgentManager discovers agents by scanning config directories at runtime

### Research Tools

- [x] **TOOL-01**: Agent can research question using its LLM model knowledge via API call
- [ ] **TOOL-02**: Agent can perform web searches via configurable search API provider
- [ ] **TOOL-03**: Agent can search arXiv API for scientific papers
- [ ] **TOOL-04**: Agent can search Semantic Scholar API for scientific papers and citations
- [ ] **TOOL-05**: LlmClient abstracts LLM API calls with model/provider selection
- [ ] **TOOL-06**: WebSearch tool has provider abstraction for swapping search APIs
- [ ] **TOOL-07**: PaperSearch tool wraps arXiv and Semantic Scholar endpoints
- [ ] **TOOL-08**: API response timeouts enforced at HTTP socket level

### Orchestration

- [ ] **ORCH-01**: Arbitrator discovers all configured agents via AgentManager
- [ ] **ORCH-02**: Arbitrator distributes research question to all agents for Round 1
- [ ] **ORCH-03**: Arbitrator enforces per-step timeout, instructs agents to stop and provide best partial answer
- [ ] **ORCH-04**: Arbitrator collects Round 1 independent answers from all agents
- [ ] **ORCH-05**: Arbitrator evaluates Round 1 answers using defined quality criteria
- [ ] **ORCH-06**: Arbitrator shares all Round 1 answers with agents for Round 2 peer critique
- [ ] **ORCH-07**: Agents read peer answers and produce structured critique in Round 2
- [ ] **ORCH-08**: Arbitrator selects best final answer after Round 2 with written reasoning
- [ ] **ORCH-09**: Arbitrator presents final answer with full reasoning trace (why this answer won)
- [ ] **ORCH-10**: 4-layer timeout architecture: PHP max execution, HTTP socket, stream-idle watchdog, cooperative agent-step deadline

### Persistence

- [ ] **PERS-01**: Each research session creates a new session with UUID and timestamp
- [ ] **PERS-02**: Full session transcript saved as markdown file
- [ ] **PERS-03**: Transcript includes question, all agent answers, debate content, and final selection with reasoning
- [ ] **PERS-04**: Session files stored under `sessions/` directory

### Logging

- [x] **LOG-01**: System logs all activities with timestamps and correlation IDs
- [x] **LOG-02**: Log output separates by channel (arbitrator, agent, system)
- [ ] **LOG-03**: Per-session log files alongside session transcripts

### CLI REPL

- [ ] **CLI-01**: Interactive REPL using PHP readline with command history
- [ ] **CLI-02**: User types research question, system returns final answer with trace
- [ ] **CLI-03**: Real-time progress display showing which phase is active
- [ ] **CLI-04**: Commands for config check, session replay, help
- [ ] **CLI-05**: ANSI-formatted output for readability

### Web REPL

- [ ] **WEB-01**: PHP built-in web server serves the web REPL interface
- [ ] **WEB-02**: Web form to submit research question
- [ ] **WEB-03**: Server-sent events (SSE) stream research progress to browser
- [ ] **WEB-04**: Background process pattern: POST returns session ID, SSE endpoint streams events
- [ ] **WEB-05**: View past sessions from browser

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Enhanced Features

- **CONF-09**: SOUL.md gains structured sections for debate persona (how to critique, what to look for)
- **TOOL-09**: Retry with exponential backoff on API failures (429/5xx)
- **ORCH-11**: Configurable timeout per agent and per round
- **ORCH-12**: Selection criteria configuration (prefer answers with citations, etc.)
- **PERS-05**: Session replay — re-run a session with different config
- **PERS-06**: Export to PDF/HTML beyond markdown
- **WEB-06**: Multi-session comparison view
- **LOG-04**: Performance analytics — per-agent success rate, average response time

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Persistent database (SQLite/MySQL) | File-based sessions sufficient for v1. Add only at 1000+ sessions |
| User authentication / multi-user | Single-user system per requirements |
| Scheduled/automated research | Manual trigger only — scheduling is orthogonal to interactive value |
| Plugin system for custom agent capabilities | Capabilities defined in config/personality. Plugin API is massive scope expansion |
| N-round configurable debate (beyond 2) | Diminishing returns documented in D3 paper. Cap at 2 for v1 |
| Visual workflow designer | Massive frontend project, does not help research quality |
| Custom consensus policies | Arbitrator selects best answer. Consensus is implicit |
| Agent-to-agent direct messaging | Structured rounds only. All communication through arbitrator |
| Real-time token-by-token streaming | Per-round results are sufficient. SSE shows per-agent completion status |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CONF-01 | Phase 1 | Completed |
| CONF-02 | Phase 1 | Completed |
| CONF-03 | Phase 1 | Completed |
| CONF-04 | Phase 1 | Completed |
| CONF-05 | Phase 1 | Completed |
| CONF-06 | Phase 1 | Completed |
| CONF-07 | Phase 1 | Completed |
| CONF-08 | Phase 2 | Pending |
| TOOL-01 | Phase 1 | Completed |
| TOOL-02 | Phase 2 | Pending |
| TOOL-03 | Phase 2 | Pending |
| TOOL-04 | Phase 2 | Pending |
| TOOL-05 | Phase 2 | Pending |
| TOOL-06 | Phase 2 | Pending |
| TOOL-07 | Phase 2 | Pending |
| TOOL-08 | Phase 2 | Pending |
| ORCH-01 | Phase 3 | Pending |
| ORCH-02 | Phase 3 | Pending |
| ORCH-03 | Phase 3 | Pending |
| ORCH-04 | Phase 3 | Pending |
| ORCH-05 | Phase 4 | Pending |
| ORCH-06 | Phase 4 | Pending |
| ORCH-07 | Phase 4 | Pending |
| ORCH-08 | Phase 4 | Pending |
| ORCH-09 | Phase 4 | Pending |
| ORCH-10 | Phase 3 | Pending |
| PERS-01 | Phase 5 | Pending |
| PERS-02 | Phase 5 | Pending |
| PERS-03 | Phase 5 | Pending |
| PERS-04 | Phase 5 | Pending |
| LOG-01 | Phase 1 | Completed |
| LOG-02 | Phase 1 | Completed |
| LOG-03 | Phase 5 | Pending |
| CLI-01 | Phase 5 | Pending |
| CLI-02 | Phase 5 | Pending |
| CLI-03 | Phase 5 | Pending |
| CLI-04 | Phase 5 | Pending |
| CLI-05 | Phase 5 | Pending |
| WEB-01 | Phase 5 | Pending |
| WEB-02 | Phase 5 | Pending |
| WEB-03 | Phase 5 | Pending |
| WEB-04 | Phase 5 | Pending |
| WEB-05 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 43 total
- Mapped to phases: 43
- Unmapped: 0 ✓

**Phase breakdown:**
- Phase 1: 10 requirements (CONF-01..07, TOOL-01, LOG-01..02)
- Phase 2: 8 requirements (CONF-08, TOOL-02..08)
- Phase 3: 5 requirements (ORCH-01..04, ORCH-10)
- Phase 4: 5 requirements (ORCH-05..09)
- Phase 5: 15 requirements (PERS-01..04, LOG-03, CLI-01..05, WEB-01..05)

---
*Requirements defined: 2026-06-13*
*Last updated: 2026-06-13 after roadmap creation*
