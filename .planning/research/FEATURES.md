# Feature Research

**Domain:** Multi-Agent Research and Debate Systems
**Researched:** 2026-06-13
**Confidence:** HIGH (features verified across 10+ production systems and frameworks)

## Feature Landscape

### Table Stakes (Users Expect These)

Features users assume exist. Missing these = system feels incomplete or unusable.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Agent configuration (model, provider, API key) | Every multi-agent system requires per-agent model identity. Without it, all agents are identical. | LOW | File-based per agent. PROJECT.md already specifies this. AutoGen, CrewAI, ChatDev all require model config per agent. |
| Agent personality/role system | Agents need distinct personas (researcher, critic, skeptic). ChatDev uses "inception prompting"; AutoGen uses system messages. | MEDIUM | SOUL.md files are the approach here. More opinionated than simple system prompts — drives behavior distinctiveness. |
| Arbitrator distributes question to all agents | Core requirement. All frameworks (AutoGen GroupChat, Magentic-One Orchestrator, ChatDev Chat Chain) distribute work to agents. | LOW | Single question broadcast to N agents. Straightforward — the arbitrator holds the queue. |
| Per-step timeout enforcement | Agents must not hang indefinitely. Magentic-One's inner loop checks progress; AutoGen v0.4 added async timeout. | MEDIUM | Must instruct agent to stop and return best partial answer. ARB-03 requirement. |
| Multi-round debate protocol | The defining feature of debate systems. ChatDev uses iterative critique; AlphaInsight does 3-5 rounds; MALLM supports multiple debate paradigms. | HIGH | Two-round minimum (independent + critique). Requires structured message passing between agents. |
| Result selection by arbitrator | Someone must pick the best answer. ChatEval uses majority vote; argue uses peer review scoring; Magentic-One's Orchestrator makes final call. | MEDIUM | Arbitrator evaluates and selects. Selection criteria must be explicit and logged. |
| Session persistence (file-based) | Every research session must be saved. TapeAgents uses structured tapes; AutoGen uses conversation history. | LOW | Per-session markdown files. PROJECT.md already specifies file-per-session. |
| Timestamped activity logging | Users need to know what happened and when. Cross-agent audit trails are standard. | LOW | JSONL or structured log per session. Trace IDs (trace_id, session_id) are the industry pattern. |
| Basic web search tool integration | Research without search is just model knowledge. OpenAI Deep Research, Gemini Deep Research, Perplexity all use web search as primary tool. | MEDIUM | Configurable search provider (AGENT-03). The research question demands current information. |
| CLI interface | Developer-friendly access. Most multi-agent systems provide CLI first. | MEDIUM | REPL with question input, answer display. Must show reasoning trace. |

### Differentiators (Competitive Advantage)

Features that set this system apart from generic multi-agent frameworks.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Structured debate with peer critique | Most debate research (ChatEval, D3, argue) shows multi-agent debate significantly outperforms single-agent answers. Structured round-by-round critique with full visibility. | HIGH | Round 2 requires each agent to read peer answers and provide critique. More structured than freeform AutoGen GroupChat. |
| SOUL.md personality system | Most systems use simple system prompts. SOUL.md files (from Soul Agent Framework pattern) are richer — markdown files describing identity, values, knowledge, and behavior. | MEDIUM | Separate files per agent with full personality definition. Makes agent distinctiveness explicit and editable. |
| Scientific paper search (ArXiv, Semantic Scholar) | Not available in general-purpose systems (AutoGen, CrewAI). Only specialized research systems (Open Coscientist, AlphaInsight) integrate paper databases. | MEDIUM | AGENT-04 requirement. ArXiv API is straightforward; Semantic Scholar API adds citation graph data. |
| Reasoned selection with full trace | The arbitrator doesn't just pick — it explains WHY one answer won. Full reasoning trace shows the chain from question through debate to final answer. | MEDIUM | ARB-06/07. Production systems (argue, D3) score this as a key feature — traceability builds trust. |
| Dual interface (CLI + Web REPL) | Most frameworks provide one or the other. CLI + web from day one serves both developers and end-users. | HIGH | Two separate UIs sharing the same backend. Web REPL needs PHP built-in server, session polling or SSE. |
| File-based per-agent configuration | AutoGen, CrewAI, LangGraph all use code-based config (Python dicts/classes). File-per-agent dirs are more portable and editable without code changes. | LOW | PROJECT.md already specifies this. Differentiator against Python frameworks — easier to reconfigure without redeploying. |
| Debate transparency (see all peer answers) | Some systems hide peer answers to avoid bias. Full visibility (as specified in ARB-05) is more transparent and enables richer critique. | LOW | Every agent sees every other agent's first-round answer. Straightforward message passing. |
| Arbitrator-as-moderator (not participant) | In AutoGen GroupChat, the orchestrator participates. Here, the arbitrator moderates without contributing content — reduces bias in selection. | MEDIUM | Pure evaluation role. The arbitrator doesn't produce its own research answer — it judges others'. |

### Anti-Features (Commonly Requested, Often Problematic)

Features that seem good but create problems for this project.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Persistent database (PostgreSQL, SQLite) | "We need to query past sessions" | Overkill for v1. Session files are already structured markdown. A database adds schema migrations, connection management, and deployment complexity. | grep/find across session files. Add database only when session volume makes text search unusable (1000+ sessions). |
| User authentication / multi-user | "Multiple team members should use it" | Single-user system per requirements. Auth adds sessions, password management, access control — none of which help the core value proposition. | Keep single-user. If multi-user is needed later, add it as a thin auth layer in front of existing sessions. |
| Scheduled/automated research | "Run research overnight" | Adds cron management, job queues, notification system. The core value is interactive research with debate — scheduling is orthogonal. | Manual trigger only. Schedule can be added later as a separate scheduler process reading the same config. |
| Plugin system for custom agent capabilities | "Let users add custom tools" | Plugin systems require API surface definition, sandboxing, version management. Massive scope expansion for uncertain value. | Capabilities defined in config/personality (SOUL.md + tools config). If plugin need emerges, add to specific agent types. |
| Real-time streaming of agent thoughts | "Watch agents think in real-time" | Streaming adds SSE/WebSocket complexity, state management for partial outputs, UI re-rendering. The debate protocol is naturally async (wait for all agents). | Show results per round (not per thought). If real-time is needed, add SSE for web REPL showing per-agent completion status. |
| N-round configurable debate (arbitrary rounds) | "Let users set any number of debate rounds" | N-round debate dramatically increases token cost and latency. Each round multiplies API calls by agent count. The marginal value of round 3+ diminishes significantly (D3 paper confirms). | Fixed 2-round debate (independent + critique). If multi-round is needed, cap at 3 and add cost warning. |
| Visual workflow designer | "Drag-and-drop agent orchestration" | Massive frontend project. ChatDev 2.0 added this as a major feature, but it's a product in itself. Does not help the core research quality. | CLI config file editing. Web REPL for running research, not designing workflows. |
| Custom consensus policies | "Let users pick voting rules" | Consensus policy system (majority, supermajority, unanimity, weighted) adds significant complexity. Many policies produce identical results with 3+ agents. | Arbitrator selects best answer based on quality evaluation. Consensus is implicit — the arbitrator decides. |
| Agent-to-agent direct messaging | "Let agents talk to each other freely" | Creates unstructured conversations, context window pressure, and non-deterministic behavior. AutoGen's GroupChat showed this leads to token explosion and role drift. | Structured rounds only. All communication goes through the arbitrator. Agents don't speak unless it's their turn in a defined round. |

## Feature Dependencies

```
Agent Configuration (model, API key, provider)
    └──requires──> Config file directory per agent

SOUL.md Personality
    └──requires──> Agent Configuration (file must exist)

Job Distribution
    └──requires──> Agent Configuration (knows which agents exist)
    └──requires──> SOUL.md Personality (agents need identity)
    └──requires──> CLI/Web REPL (user submits question)

Timeout Enforcement
    └──requires──> Job Distribution (timeout runs per job)
    └──requires──> Agent Configuration (per-agent timeout values)

Web Search Tool
    └──requires──> Agent Configuration (API keys in config)
    └──requires──> Search API provider config

Paper Search Tool
    └──requires──> Agent Configuration (API keys if needed)
    └──requires──> Search API provider config

Round 1 (Independent Research)
    └──requires──> Job Distribution
    └──requires──> Timeout Enforcement
    └──requires──> Web Search Tool
    └──requires──> Paper Search Tool

Round 2 (Debate)
    └──requires──> Round 1 (needs answers to critique)
    └──requires──> Agent Configuration (agents need debate persona component)

Arbitrator Selection
    └──requires──> Round 2 (needs debate output)
    └──requires──> Selection criteria defined

Result Presentation
    └──requires──> Arbitrator Selection
    └──requires──> CLI/Web REPL

Session Logging
    └──requires──> Round 1 (logs round 1 activities)
    └──requires──> Round 2 (logs round 2 activities)
    └──requires──> Arbitrator Selection (logs decision)

Session File Storage
    └──requires──> Session Logging (content to store)

```
### Dependency Notes

- **Round 2 requires Round 1**: The debate round needs content to critique. Without first-round answers, there's nothing to debate. This is the most critical dependency chain and drives the two-phase architecture.
- **Tool integration requires Agent Configuration**: API keys must be in agent config before tools can be invoked. Tool availability must be checked at config-load time, not at runtime.
- **Web REPL requires CLI first**: Building the Web REPL depends on the same backend logic as CLI. The CLI should be built first (simpler, debuggable), then the Web REPL wraps the same PHP classes.
- **Logging is woven through everything**: Every component writes to the same log stream. The log format and file structure must be defined before implementing any feature that logs.

## MVP Definition

### Launch With (v1)

Minimum viable product — what's needed to validate the concept.

- [x] **ARB-01**: Arbitrator reads configuration from its own folder — essential for system identity
- [x] **ARB-02**: Arbitrator distributes research question to all configured agents — core job
- [x] **ARB-03**: Per-step timeout enforcement — prevents hanging, defines cost boundary
- [x] **ARB-04**: Evaluate Round 1 answers — first quality check
- [x] **ARB-05**: Facilitate Round 2 debate — the differentiator, peer critique
- [x] **ARB-06**: Select best final answer — the output
- [x] **ARB-07**: Present answer with reasoning trace — usability
- [x] **AGENT-01**: Agent reads config (model provider, model, API key) — table stakes
- [x] **AGENT-02**: Agent researches using LLM model knowledge — minimum research capability
- [x] **AGENT-03**: Agent performs web searches — essential for current information
- [x] **AGENT-05**: Agent respects timeout signal — reliability
- [x] **AGENT-06**: Agent participates in debate round — the differentiator
- [x] **SHELL-01**: CLI REPL — primary interface
- [x] **CONFIG-01**: Per-agent/arbitrator config directories — architecture foundation
- [x] **CONFIG-02**: Config files for model provider, model name, API key — must work
- [x] **CONFIG-03**: SOUL.md personality — agent distinctiveness
- [x] **CONFIG-04**: Preferences file for tool access — tool configuration
- [x] **LOG-01**: Timestamped activity logging — observability
- [x] **STORE-01**: Session saved as markdown file — persistence

### Add After Validation (v1.x)

Features to add once core is working and user feedback is gathered.

- [ ] **CONFIG-03 enhanced**: SOUL.md gains structured sections for debate persona (how to critique, what to look for) — based on whether agents need stronger identity in debate rounds
- [ ] **AGENT-04**: Scientific paper search (ArXiv, Semantic Scholar) — once web search is proven and users need academic sources
- [ ] **SHELL-02**: Web REPL — once CLI proves the concept and users want browser access
- [ ] **Selection criteria configuration**: Allow users to influence how the arbitrator picks winners (e.g., "prefer answers with citations") — once basic selection is working
- [ ] **Configurable timeout per agent**: Different timeout values per search vs. debate round — once timeouts are working and users need fine-tuning
- [ ] **Retry with backoff on API failures**: Exponential backoff when providers return 429/5xx — after first production outages

### Future Consideration (v2+)

Features to defer until product-market fit is established.

- [ ] **N-round configurable debate** — only if 2 rounds proves insufficient (evidence from literature suggests diminishing returns)
- [ ] **Quality scores on agent responses** (explicit scoring dimensions like correctness, completeness, consistency) — after arbitrator needs stronger selection heuristics
- [ ] **Agent elimination** (remove consistently poor performers mid-session) — if agent counts grow beyond 4
- [ ] **Session replay** (re-run a session with different config) — if debugging becomes a pain point
- [ ] **Export to PDF/HTML** (beyond markdown) — if users need to share results with non-technical audiences
- [ ] **Multi-session comparison** (compare two research sessions side by side) — if users run multiple sessions per topic
- [ ] **Performance analytics** (per-agent success rate, average response time) — if agents become unreliable
- [ ] **Agent hot-reload** (change config without restarting) — if configuration changes become frequent

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Agent configuration (model, API key) | HIGH | LOW | P1 |
| SOUL.md personality system | HIGH | MEDIUM | P1 |
| Job distribution (arbitrator -> agents) | HIGH | LOW | P1 |
| Per-step timeout enforcement | HIGH | MEDIUM | P1 |
| Round 1 independent research | HIGH | MEDIUM | P1 |
| Round 2 debate with peer critique | VERY HIGH | HIGH | P1 |
| Arbitrator selects best answer | HIGH | MEDIUM | P1 |
| Result presentation with trace | HIGH | LOW | P1 |
| CLI REPL | HIGH | MEDIUM | P1 |
| Web search tool integration | HIGH | MEDIUM | P1 |
| Timestamped session logging | MEDIUM | LOW | P1 |
| File-per-session storage | MEDIUM | LOW | P1 |
| Scientific paper search | MEDIUM | MEDIUM | P2 |
| Web REPL | HIGH | HIGH | P2 |
| Configurable timeout per agent | LOW | LOW | P2 |
| Retry with backoff on API failures | MEDIUM | MEDIUM | P2 |
| N-round configurable debate | LOW | HIGH | P3 |
| Quality scores on responses | MEDIUM | HIGH | P3 |
| Agent elimination | LOW | MEDIUM | P3 |
| Session replay | MEDIUM | HIGH | P3 |
| Export to PDF/HTML | LOW | MEDIUM | P3 |
| Multi-session comparison | MEDIUM | HIGH | P3 |

**Priority key:**
- P1: Must have for launch (table stakes + core differentiator)
- P2: Should have, add when core is validated
- P3: Nice to have, future consideration

## Competitor Feature Analysis

| Feature | AutoGen (v0.4/v0.7) | CrewAI | ChatDev | Magentic-One | argue (onevcat) | Our Approach |
|---------|---------------------|--------|---------|--------------|-----------------|--------------|
| Agent configuration | Code-based (Python dicts) | Code-based (YAML/class) | Code-based (inception prompts) | Code-based (Python) | Harness-agnostic delegate | File-based per agent directory |
| Personality system | System messages | Role strings | Inception prompting | System messages | Not built-in | SOUL.md markdown files |
| Orchestration | GroupChat (auto/round-robin) | Sequential/ hierarchical | Chat Chain (linear) | Orchestrator + specialists | AgentTaskDelegate | Arbitrator broadcasts + evaluates |
| Debate rounds | Freeform conversation | Flow abstraction | Iterative critique | Progress ledger (not debate) | Claim lifecycle + voting | Structured 2-round (independent + debate) |
| Timeout management | Async framework (v0.4) | Coarse | Not built-in | Two-loop progress check | Elimination (timeout = removed) | Per-step hard timeout + partial answer |
| Web search | Tool integration | Tool integration | Not built-in | WebSurfer agent | Via delegate interface | Configurable search API provider |
| Paper search | Not built-in | Not built-in | Not built-in | Not built-in | Not built-in | ArXiv + Semantic Scholar integration |
| Quality scoring | Not built-in | Not built-in | Implicit (works if no errors) | Progress ledger checks | Peer review (4 dimensions) | Arbitrator evaluation (subjective, reasoned) |
| Result selection | Natural conversation end | Task completion | Final agent in chain | Orchestrator decides | Scoring + representative composes | Arbitrator selects with reasoning trace |
| Logging | Conversation traces | Task-level event logs | Chat history | OTel tracing | Claim lifecycle events | Timestamped JSONL activity log |
| Session persistence | Conversation history | Task outputs | Phase memory | Checkpointing | Claim state | File-per-session markdown |
| User interface | Python API | Python API | CLI + Web v2 | Python API | Swift package | CLI + Web REPL |
| Transparency | Full conversation visible | Role output visible | Phase history visible | Ledger visible | Claim state visible | Full trace per session file |

## Sources

- AutoGen v0.4 architecture and features (Microsoft Research, 2025)
- CrewAI vs LangGraph vs AutoGen 2026 comparison (FutureAGI)
- ChatDev paper: "Communicative Agents for Software Development" (ACL 2024, arXiv:2307.07924)
- Magentic-One technical report (Microsoft Research, arXiv:2411.04468, 2024)
- argue framework (onevcat/argue on GitHub)
- MALLM framework (ACL 2025, arXiv:2509.11656)
- ChatEval: Multi-agent debate for LLM evaluation (arXiv:2308.07201)
- D3: Debate, Deliberate, Decide (EACL 2026)
- WISE: Weighted Iterative Society-of-Experts (arXiv:2512.02405)
- Soul Agent Framework (markdown-based agent configuration)
- AlphaInsight: LangGraph debate system (GitHub: Robot-Nav/AlphaInsight)
- OpenAI Deep Research system design (February 2025)
- GitHub Blog: "Multi-agent workflows often fail" (2026)
- ACM: "The Hidden Token Trap of Agent Orchestration" (2026)
- VentureBeat: "AI agents generating chaos engineering failures" (2026)

---
*Feature research for: Multi-Agent Research and Debate Systems*
*Researched: 2026-06-13*
