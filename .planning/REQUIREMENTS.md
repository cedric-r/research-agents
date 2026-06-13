# Requirements: ResearchAgents

**Defined:** 2026-06-13
**Core Value:** Get the best possible research answer by having multiple AI agents with diverse models and sources work in parallel, debate their findings, and converge on the optimal result — with full traceability of how they got there.

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Agent Configuration

- [ ] **CONF-01**: Each agent has own directory under `config/agents/{name}/`
- [ ] **CONF-02**: Arbitrator has own config directory with its configuration
- [ ] **CONF-03**: `config.json` contains model provider, model name, API key per agent
- [ ] **CONF-04**: `SOUL.md` defines agent personality, identity, values, and behavior instructions
- [ ] **CONF-05**: `preferences.json` defines tool access and agent-specific settings
- [ ] **CONF-06**: Config loader supports PHP array config files for zero parsing overhead
- [ ] **CONF-07**: Config validation reports missing fields and invalid values at startup
- [ ] **CONF-08**: AgentManager discovers agents by scanning config directories at runtime

### Research Tools

- [ ] **TOOL-01**: Agent can research question using its LLM model knowledge via API call
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

- [ ] **LOG-01**: System logs all activities with timestamps and correlation IDs
- [ ] **LOG-02**: Log output separates by channel (arbitrator, agent, system)
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
| N-round configurable debate (beyond 2) | Diminishing returns documented in debate research (D3 paper). Cap at 2 for v1 |
| Visual workflow designer | Massive frontend project, does not help research quality |
| Custom consensus policies | Arbitrator selects best answer. Consensus is implicit |
| Agent-to-agent direct messaging | Structured rounds only. All communication through arbitrator |
| Real-time token-by-token streaming | Per-round results are sufficient. SSE shows per-agent completion status |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CONF-01 | Pending | Pending |
| CONF-02 | Pending | Pending |
| CONF-03 | Pending | Pending |
| CONF-04 | Pending | Pending |
| CONF-05 | Pending | Pending |
| CONF-06 | Pending | Pending |
| CONF-07 | Pending | Pending |
| CONF-08 | Pending | Pending |
| TOOL-01 | Pending | Pending |
| TOOL-02 | Pending | Pending |
| TOOL-03 | Pending | Pending |
| TOOL-04 | Pending | Pending |
| TOOL-05 | Pending | Pending |
| TOOL-06 | Pending | Pending |
| TOOL-07 | Pending | Pending |
| TOOL-08 | Pending | Pending |
| ORCH-01 | Pending | Pending |
| ORCH-02 | Pending | Pending |
| ORCH-03 | Pending | Pending |
| ORCH-04 | Pending | Pending |
| ORCH-05 | Pending | Pending |
| ORCH-06 | Pending | Pending |
| ORCH-07 | Pending | Pending |
| ORCH-08 | Pending | Pending |
| ORCH-09 | Pending | Pending |
| ORCH-10 | Pending | Pending |
| PERS-01 | Pending | Pending |
| PERS-02 | Pending | Pending |
| PERS-03 | Pending | Pending |
| PERS-04 | Pending | Pending |
| LOG-01 | Pending | Pending |
| LOG-02 | Pending | Pending |
| LOG-03 | Pending | Pending |
| CLI-01 | Pending | Pending |
| CLI-02 | Pending | Pending |
| CLI-03 | Pending | Pending |
| CLI-04 | Pending | Pending |
| CLI-05 | Pending | Pending |
| WEB-01 | Pending | Pending |
| WEB-02 | Pending | Pending |
| WEB-03 | Pending | Pending |
| WEB-04 | Pending | Pending |
| WEB-05 | Pending | Pending |

**Coverage:**
- v1 requirements: 39 total
- Mapped to phases: 0
- Unmapped: 39 ⚠️

---
*Requirements defined: 2026-06-13*
*Last updated: 2026-06-13 after initial definition*
