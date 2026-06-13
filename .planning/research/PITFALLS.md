# Pitfalls Research: Multi-Agent Research & Debate Systems

**Domain:** Multi-agent LLM orchestration with debate rounds
**Researched:** 2026-06-13
**Confidence:** HIGH (research backed, cross-verified sources)

## Critical Pitfalls

Mistakes that cause rewrites, infinite loops, or fundamentally broken agent behavior.

---

### Pitfall 1: Over-Engineering Multi-Agent When a Single Agent Suffices

**What goes wrong:**
Teams invest heavily in multi-agent architectures -- orchestrating complex debates, handoffs, and coordination -- only to discover that a well-prompted single agent achieves equivalent or better results at a fraction of the cost. Anthropic's engineering team found this pattern repeatedly: "improved prompting on a single agent achieved equivalent results" to multi-agent systems teams had spent months building (Claude blog, 2025).

**Why it happens:**
- Multi-agent systems are intellectually appealing and feel more "advanced"
- Teams decompose problems by work type (planner, researcher, critic) instead of by context boundaries
- The overhead of inter-agent communication is invisible during design but dominates at runtime
- A false assumption that "more agents = more intelligence" -- research shows scaling often increases noise, hallucination risk, and coordination overhead without improving output quality

**How to avoid:**
- Start with one agent. Only add agents when you can measure a clear quality improvement
- Decompose by context boundaries, not by work type: one agent handles a feature end-to-end rather than splitting into planner/implementer/tester
- Measure the single-agent baseline before building multi-agent orchestration
- If you do need multiple agents, limit to 2-3 with well-defined context boundaries

**Warning signs:**
- Architecture documents describe agents by role ("researcher," "critic," "summarizer") without clear context isolation
- You cannot articulate what unique data each agent has that others don't
- The design has more than 4 agents before any single-agent baseline has been measured
- Team members disagree on which agent is responsible for which decisions

**Phase to address:**
Phase 1 (Foundation). The single-agent baseline should be the first deliverable. Only Phase 3+ should add multi-agent capabilities after the single-agent quality ceiling has been empirically established.

---

### Pitfall 2: Echo Chambers and Harmful Conformity in Debate

**What goes wrong:**
Multi-agent debate rounds can create echo chambers that amplify errors rather than correct them. When agents share similar architecture, training data, or prompting patterns, their responses reinforce one another. Majority opinions dominate even when systematically erroneous. Research (Kim & Torr, MoLaCE, arXiv 2512.23518, 2025) confirms: "This vulnerability poses an even greater risk in multi-agent debate, where echo chambers tend to reinforce biases rather than correct them."

**Why it happens:**
- LLM agents have an inherent bias toward agreement and convergence -- they do not naturally produce diverse dissenting opinions
- Research (arXiv 2606.00820, 2026) shows 29% of stance flips in debate are strict conformity, and 57-77% of those conformity flips are harmful (correct-to-wrong)
- Even vacuous reasoning carries persuasive weight: associated with 20-39% error adoption
- When all agents use the same provider (e.g., all Claude, all GPT), correlated errors compound
- Debate gains are largely ensembling effects (majority vote over independent outputs) -- the debate rounds themselves produce little to no systematic benefit

**How to avoid:**
- Use diverse models across agents (different providers, different model sizes, different temperature settings)
- Conduct independent research rounds (Round 1) before any agent sees peer answers -- preserve independent thought
- Implement Round 0 (pre-debate) confidence scoring: agents state confidence levels before seeing peers, so you can detect conformity pressure
- Limit debate rounds to 2: independent research then critique. More rounds show diminishing returns and increasing conformity
- Use the arbitrator to detect early warning signals of harmful conformity (AUC of 0.79 in research)
- Consider running a "devil's advocate" agent with contrarian prompting as a systematic dissent mechanism

**Warning signs:**
- Agents in later debate rounds simply agree with each other or rephrase prior answers
- Final answers don't differ meaningfully from a majority vote of Round 1 outputs
- Confidence levels rise across rounds without new evidence being introduced
- Error patterns are identical across agents (suggests correlated failure, not independent verification)

**Phase to address:**
Phase 4 (Debate System). Prevention must be built into the debate protocol from day one. Retro-fitting diversity mechanisms is harder than designing them in.

---

### Pitfall 3: Cascading Error Propagation (The 97% Reliability Trap)

**What goes wrong:**
Errors in one agent compound as they pass through the pipeline. Research on LLM cascading failure models shows that with realistic per-agent error rates, the system produces correct output only ~44% of the time when agents are chained. The heuristic: you need 97%+ per-agent reliability before chaining provides net benefit over a single agent.

**Why it happens:**
- Each small hallucination in Agent A becomes another agent's incorrect input
- LLMs amplify errors when communicating -- unstructured natural language between agents makes error detection nearly invisible
- No stable internal state means the LLM's "understanding" is probabilistic and degrades over long sequences
- Context degradation accelerates with more agents: irrelevant tokens accumulate, instructions drift, goals mutate
- The system has no type checking or schema enforcement at agent boundaries

**How to avoid:**
- Implement typed, schematized handoffs between agents (structured data, not free text)
- Validate outputs at every boundary before passing to the next agent
- Make each agent's output self-describing: include a confidence/ certainty score with every answer
- Build state checkpoints at each step. If a downstream agent fails, restart from the last valid checkpoint, not from scratch
- Enforce a maximum pipeline depth. If you need more stages, make them parallel, not serial
- Test the system end-to-end with deliberately corrupted intermediate outputs to verify error containment

**Warning signs:**
- Final answers contain information that no single agent could have generated (hallucination compounding)
- Error analysis shows the same error appearing in increasingly elaborate forms through the pipeline
- Agent B's critique of Agent A's work references information Agent A never provided
- You can trace an error back through three or more agent hops

**Phase to address:**
Phase 3 (Orchestration Pipeline). The handoff schema, validation layer, and confidence scoring must be part of the core pipe, not added later.

---

### Pitfall 4: No Structured Handoffs Between Agents

**What goes wrong:**
Agents exchange unstructured natural language (plain text). This creates a "telephone game" where meaning degrades with each hop. Critical structured information -- citations, confidence scores, data provenance, source URLs -- is embedded in prose and lost or misinterpreted.

**Why it happens:**
- Natural language between agents feels natural and "flexible"
- It's the path of least resistance in early development
- JSON schemas feel bureaucratic for what seems like a simple exchange
- Developers don't notice the problem during development because the initial agent pair can recover from ambiguity, but this degrades at scale

**How to avoid:**
- Define typed interfaces for every agent boundary (required fields, optional fields, response format validation)
- Use JSON schemas, not prose instructions, for agent output format
- Treat agent handoffs like API contracts: version them, validate them, break loudly on mismatch
- Pass structured metadata (confidence, citations, source URLs) alongside content, not embedded in it
- Use typed action schemas (GitHub Engineering pattern): define exactly what actions each agent can request of another

**Warning signs:**
- Agents ask clarifying questions about each other's output format
- Parsing logic for agent output uses regex or fragile string matching
- Two agents have "misunderstandings" that require human intervention to resolve
- Output format changes when you switch model providers (different LLMs format the same instruction differently)

**Phase to address:**
Phase 3 (Orchestration Pipeline). The message protocol between agents is foundational architecture. Changing it later requires changing every agent.

---

### Pitfall 5: Timeout Gaps (Agents Hang Forever)

**What goes wrong:**
Agents receive all tool results successfully but never generate the next message, or an API stream drops mid-response and the agent hangs indefinitely. Documented real-world pattern (GitHub Issue #4173, openclaw): "Agent receives all tool results successfully but never generates the next assistant message. No error logged, no timeout reported -- session appears stuck." The fix PR (#13846, opencode) found four compounding gaps: read timeout was documented but never enforced, stream-idle was undetected, retry loop had no cap, and sub-agent task tool had no timeout wrapper.

**Why it happens:**
- Developers set a timeout on the HTTP request but not on the stream idle time between chunks
- No watchdog timer for "agent received input but hasn't produced output"
- The retry loop has no maximum -- it retries forever on a permanently-failing call
- Different providers have different streaming behaviors and failure modes
- Developers assume "the LLM will always respond eventually" -- it won't

**How to avoid:**
- Implement four independent timeouts: HTTP connection, stream idle (per-chunk), per-turn processing, and total pipeline duration
- Add a stream-idle watchdog: reset on each chunk, trip at 120s of silence
- Cap retry loops at a sane maximum (3-5 for transient failures, 1 for non-retriable)
- On timeout, preserve the partial response and signal "partial answer" rather than failing invisibly
- Implement an escalating recovery strategy: retry -> downshift to cheaper/faster model -> abort with partial results
- Test with agent-chaos or similar fault injection: simulate timeouts, dropped streams, and slow chunks

**Warning signs:**
- Long-running sessions that never complete (infinite waiting)
- Users report "the system stopped responding" but no error was logged
- Agent's last logged action shows tool results received with no follow-up
- Final answer is truncated or ends mid-sentence without indication

**Phase to address:**
Phase 3 (Orchestration Pipeline). Timeouts must be a first-class architectural concern, not a configuration detail. Wiring them in later requires touching every agent and the orchestrator.

---

### Pitfall 6: PHP Process Management -- Zombies, Blocking I/O, Memory Leaks

**What goes wrong:**
Vanilla PHP has no built-in async runtime. Using `pcntl_fork()` to run agents concurrently creates zombie processes, memory leaks from shared state, and blocking I/O bottlenecks. A single agent calling `curl_exec()` blocks the entire parent process. Forked children inherit MySQL/Redis connections that corrupt when both parent and child use them. Without explicit `pcntl_waitpid()`, children become zombies that consume PID slots until the system runs out.

**Why it happens:**
- PHP's `curl_exec()` is synchronous and blocking -- it uses `curl_easy_perform()` which yields the CPU until the response arrives
- `pcntl_fork()` copies the entire process state including open file descriptors and DB connections
- PHP developers are used to request-response lifecycle (short-lived), not long-running CLI process orchestration
- Signal handling in PHP requires explicit `pcntl_signal_dispatch()` calls -- signals are not delivered asynchronously
- PHP's garbage collector doesn't handle shared memory well; child processes can leak

**How to avoid:**
- Use `curl_multi_init()` + `curl_multi_exec()` for concurrent HTTP calls within a single PHP process (avoids `pcntl_fork` entirely for concurrent API calls)
- If forking is necessary: call `pcntl_waitpid($pid, $status, WNOHANG)` in a `SIGCHLD` handler, and call `pcntl_signal_dispatch()` at the top of every loop iteration
- After `pcntl_fork()`, immediately close and reopen all external connections (MySQL, Redis, files) in both parent and child
- Use shared memory (`shmop` / `sysvshm`) with semaphores for cross-process coordination instead of files or Redis
- Prefer `curl_multi_*` over `pcntl_fork` for I/O-bound concurrency (which is this system's primary bottleneck)
- For truly long-running agent processes, consider a message queue (simple file-based FIFO) rather than raw process management
- Set `CURLMOPT_MAX_TOTAL_CONNECTIONS` to avoid exhausting system socket limits (~300 handles recommended ceiling)

**Warning signs:**
- `ps aux` shows increasing numbers of `php` processes over time (zombie accumulation)
- Database or Redis connections throw errors like "server has gone away" mid-session
- System logs show "Cannot fork" errors (PID exhaustion)
- Child processes don't exit cleanly after the orchestrator terminates
- Memory usage of the parent process grows monotonically over multiple sessions

**Phase to address:**
Phase 2 (Agent Runtime). The concurrency model must be chosen before agents are built. Switching from `pcntl_fork` to `curl_multi` later means rewriting every agent.

---

### Pitfall 7: Agent-to-Agent Prompt Injection

**What goes wrong:**
A compromised agent (or one that processes untrusted content) can inject malicious instructions into another agent's context. Research demonstrates this is not theoretical: the "Prompt Infection" paper (Lee & Tiwari, arXiv 2410.07283, 2024) shows self-replicating prompt injection that spreads through multi-agent systems like a worm. A malicious research agent convinced a financial assistant agent to disclose its system prompt, tools, and chat history, then execute an unauthorized stock purchase (Palo Alto Networks / SC World, 2025).

**Why it happens:**
- Agents treat each other's output as trustworthy -- there's no output sanitization or provenance checking
- An agent researching the web may encounter malicious content that enters the system through its response
- Agent-to-agent protocols (A2A, MCP) have no built-in authentication or integrity verification
- Traditional input validation and WAFs provide no defense against prompt injection
- The multi-agent surface area is much larger than single-agent: each handoff is an attack vector

**How to avoid:**
- Never pass raw agent output directly as instructions to another agent -- always validate and sanitize
- Implement output provenance tracking: tag every statement with which agent generated it, what tools it used, and its confidence
- Restrict agent capabilities with "behavior certificates": define exactly what each agent can do, and enforce at the orchestrator level
- Use a "system prompt firewall": separate user content from system instructions at every boundary; never embed untrusted content into a system prompt
- Implement human-in-the-loop checkpoints for destructive actions (making purchases, modifying files, sending messages)
- Run agents with least privilege: each agent should have access only to the tools and data it needs
- Consider cryptographically signing agent outputs so the receiving agent can verify integrity

**Warning signs:**
- Agent produces output that references commands or instructions it shouldn't know about
- Agent attempts to use a tool it shouldn't have access to
- Agent output contains system prompt fragments from another agent
- Unexpected tool invocations or data access patterns
- Cross-agent conversation contains meta-instructions ("ignore previous instructions and...")

**Phase to address:**
Phase 3 (Orchestration Pipeline). The security boundary between agents must be designed into the handoff protocol. Retrofitting security into an unstructured message-passing system is extremely difficult.

---

### Pitfall 8: API Rate Limiting Without Circuit Breakers or Failover

**What goes wrong:**
Multiple agents calling multiple LLM APIs concurrently creates rate-limit chaos. A single agent hitting a 429 error causes cascading retries that make the problem worse. Without circuit breakers, the system enters a "retry storm" -- every agent retries at the same time, hitting rate limits harder. Without provider failover, one provider's outage takes down the entire system.

**Why it happens:**
- Each agent manages its own API key and retry logic independently -- no central rate-limit awareness
- The default response to a 429 error is "wait and retry," but without coordination all agents wait the same duration and retry simultaneously
- Developers test with one provider at low concurrency, then deploy against multiple providers at full concurrency
- Per-provider rate limits are complex: tiered, token-bucket, RPM/TPM based, and providers change them without notice
- PHP's blocking I/O means a rate-limited agent can hold up the entire pipeline

**How to avoid:**
- Implement a central rate-limit coordinator that tracks usage across all agents per provider and per model
- Use circuit breakers: track consecutive 429/5xx errors per provider; after N failures (suggested: 10), trip the circuit for a cooldown period (suggested: 5 minutes)
- Implement provider failover with priority chains: primary -> secondary -> tertiary. On 429, fail immediately to the next provider
- Use exponential backoff with jitter: `min(base * 2^attempt, max_delay) + random(0, jitter)`. Never retry without jitter at system level
- Pre-flight cost estimation: estimate token usage before sending a request; reject if over budget
- Maintain a shared state file (or shared memory segment) with current rate-limit counters, updated atomically
- Log every API call with provider, model, tokens, cost, latency, and error type

**Warning signs:**
- Intermittent "Service unavailable" errors when all agents run simultaneously
- One provider consistently returns 429 while others work fine
- API costs spike in "retry storms" when the system recovers from a brief outage
- Agents make progress in serial mode but fail in parallel mode
- Rate limit errors appear in bursts -- many within seconds, then none for minutes

**Phase to address:**
Phase 2 (Agent Runtime). The rate-limit coordinator and circuit breaker must exist before multiple agents run concurrently. Adding it after the fact means retrofitting across all agent implementations.

---

## Moderate Pitfalls

---

### Pitfall 9: Unbounded Token Consumption

**What goes wrong:**
Agent conversations grow monotonically -- each round appends full context from prior rounds. Research shows multi-agent systems typically consume 3-10x more tokens than single-agent approaches. With 4 agents debating across 3 rounds, the total context cost grows quadratically: each agent's input includes its own output plus all peers' outputs from prior rounds.

**Why it happens:**
- Context windows are treated as unlimited storage ("it fits, so include it")
- There's no mechanism to summarize, prune, or forget
- Developers don't track token cost during development (small contexts, few rounds)
- The "infinite context window" is used as a substitute for real state management

**How to avoid:**
- Implement a token budget per session with hard caps (suggested: phased alerts at 60% and 80% of budget)
- Prune conversation history: keep only the most recent N rounds of debate, summarize older rounds into abstracts
- Use a tiered memory model: episodic (current session), semantic (structured findings), procedural (agent behavior history)
- Log token consumption per agent per round, and review cost before scaling to more agents or rounds
- Estimate cost before execution: count input tokens from prompts + accumulated context, warn if over threshold
- Consider whether each round needs full context from all prior rounds, or just summaries

**Warning signs:**
- Session token counts grow faster than linearly with each debate round
- API bills are higher than expected for the number of sessions run
- Agents start producing "context limit exceeded" errors
- Response latency increases over the course of a multi-round session
- Agent behavior changes noticeably between early and late rounds (instruction drift from context bloat)

**Phase to address:**
Phase 4 (Debate System). The debate protocol design determines the token growth curve. A context pruning strategy must be part of the protocol, not added when costs spiral.

---

### Pitfall 10: Session File Corruption from Concurrent Writes

**What goes wrong:**
Multiple agents writing to the same session file causes interleaved output, JSON corruption, binary data injection, and truncation. Documented pattern (VS Code Issue #296890): sending a message mid-response forked the session into two parallel threads, both writing to the same `.jsonl` file, producing interleaved JSON entries that made the session permanently unloadable.

**Why it happens:**
- PHP's file I/O operations (`fwrite`, `file_put_contents`) are not atomic for concurrent writes
- Multiple agents may write to the same session markdown file simultaneously
- No file locking mechanism (flock) is implemented
- The markdown format has no built-in corruption detection (no checksums, no structure enforcement)

**How to avoid:**
- Use file locking: `flock($handle, LOCK_EX)` for every write operation. Use `LOCK_NB` to fail-fast instead of blocking
- Use append-only files with structured entries (JSONL) where each line is independently parseable, avoiding half-written records
- Write to agent-specific temporary files, then merge into the session file after the agent completes
- Implement a write buffer: collect writes in memory and flush to disk on agent completion or at a fixed interval
- Validate file integrity on load: check for parseable structure, truncation markers, checksum hashes
- Implement session rotation: split long sessions into multiple files (e.g., per-round) to limit blast radius of corruption
- Keep backups: maintain the last N sessions automatically, with CRC checks on stored files

**Warning signs:**
- Session files fail to parse or load (JSON decode failures, truncated markdown)
- Entries in session files are interleaved (Agent A content inside Agent B's section)
- Session files contain binary characters or raw hex values
- Session file size doesn't match expectations (too small = truncation, too large = runaway agent)
- Users report "session not found" or "corrupted session" errors

**Phase to address:**
Phase 5 (Storage & Sessions). The session storage subsystem must be designed with concurrent write patterns in mind. File locking and structured output formats are less invasive if designed in, but can be retrofitted if done in dedicated storage phase.

---

### Pitfall 11: Logging Too Much or Too Little

**What goes wrong:**
Systems that log everything produce unreadable gigabytes of data where critical signals are buried. Systems that log too little provide no debugging information when agents fail. Both extremes make the system unmanageable.

**Why it happens:**
- Developers add logging reactively ("let's log everything for now") and never remove it
- No log level strategy -- everything is logged at the same verbosity
- LLM prompts and responses are logged at full size, dominating storage
- Developers debug locally with verbose logging and deploy without reducing verbosity
- No retention policy -- logs accumulate until disk fills

**How to avoid:**
- Implement structured logging with levels: DEBUG, INFO, WARN, ERROR. Production default = INFO
- Separate LLM prompt/response storage from operational logs. Store prompts in round-specific files, link by session ID
- Log per-agent: timestamp, agent_id, session_id, action, duration_ms, tokens (in/out), result length
- Sample successful operations (log 10-30%) but log 100% of errors, warnings, and timeouts
- Implement log rotation: archive logs older than N days, delete older than M days
- Use correlation IDs (trace_id) across all agents so you can reconstruct a full session from individual agent log entries
- Store LLM outputs as structured JSONL, not free-text log entries, so they're machine-parseable
- Create a "session abort snapshot" -- on session failure, dump the full context (all agent logs, inputs, outputs, error messages) to a dedicated error file for debugging

**Warning signs:**
- Log files consume more disk space than session data
- Finding a specific error requires grepping through gigabytes of logs
- Logs contain full LLM responses (tens of thousands of tokens) inline
- No one can explain what a specific log field means
- Important log entries are surrounded by trivial ones at the same level

**Phase to address:**
Phase 5 (Storage & Sessions). Logging structure should be decided when the session storage format is designed, not patched in during debugging.

---

### Pitfall 12: Web Search API Cost and Reliability Blind Spots

**What goes wrong:**
Agents make web search calls on every query without caching, cost tracking, or failover. A single research session may trigger 10+ web searches per agent. With 4 agents, that's 40+ API calls per session. At $5/1K queries (Google CSE pricing), a hundred sessions costs $20+. But the bigger risk is availability: Google is shutting down Custom Search JSON API on January 1, 2027, and SerpAPI is being sued by Google under DMCA (Dec 2025). The provider you choose today may not exist in 6 months.

**Why it happens:**
- Developers integrate one search provider (often the first they find) and never plan for alternatives
- No caching layer -- identical queries hit the API repeatedly (same question, multiple agents, same search)
- Cost tracking is an afterthought because small-scale testing has negligible costs
- Rate limits for search APIs are different from LLM APIs and managed separately
- Search API contracts change frequently: Google removed `num=100` in Sept 2025, forcing SerpAPI to limit to 3 results per call

**How to avoid:**
- Implement a search abstraction layer so you can swap providers without changing agent code
- Cache search results aggressively: same-agent same-query -> cache hit; cross-agent same-query -> cache hit (this alone can cut API calls by ~40%)
- Track search API costs per session, per agent, and cumulatively. Alert on anomalous spikes
- Budget for search costs: $0.50/session at 4 agents with no caching; $0.15/session with caching
- Use pay-as-you-go providers for variable usage (DataForSEO ~$0.002/query) instead of subscription models with expiring credits
- Implement provider failover: primary search API -> secondary -> fallback. On failure, fail immediately
- Consider open alternatives: DuckDuckGo (no API key needed but rate-limited), Bing Web Search API (Azure), or you.com API for AI-focused search

**Warning signs:**
- Search API costs are a significant fraction of total LLM API costs
- Identical searches appear in logs across different agents in the same session
- Search results are empty or degraded without the system noticing
- Provider bills show unused prepaid credits expiring
- Search latency spikes degrade overall session performance

**Phase to address:**
Phase 2 (Agent Runtime). The search abstraction layer must be in place before agents are written to use search. Every agent should call the abstraction, not a specific provider.

---

### Pitfall 13: Configuration Management Sprawl

**What goes wrong:**
Each agent has its own directory with config files, API keys, SOUL.md personality, and preferences. With 4+ agents, this creates 4+ config directories plus the arbitrator's config. API keys are scattered across files. Changes require editing multiple locations. A single typo in a config file breaks an agent silently.

**Why it happens:**
- Per-agent directories make sense conceptually ("everything an agent needs in one place")
- No validation layer: config files are loaded and used directly, errors surface at runtime
- No schema or required fields: a missing key in a config file doesn't fail until the agent tries to use it
- API keys in config files: not encrypted, not environment-variable sourced, easily committed to version control
- Config drift: as agents evolve, their config files change independently and the shared baseline diverges

**How to avoid:**
- Implement a config validation layer that runs on startup: required fields, type checking, value ranges
- Use a single shared config file for shared settings (global defaults, API key management, debug levels), with per-agent overrides only for unique settings
- Never store API keys in config files. Use environment variables or a `.env` file loaded at startup, with the key injected into the agent context in memory
- Add `gitignore` rules for all files that contain API keys: `.env`, `*.key`, and any config file that stores credentials
- Implement a "config health check" command: `php bin/check-config` that validates all agent configs and reports errors before any agent runs
- Use a config schema (JSON Schema or a PHP DTO with typed properties) so configuration errors fail at load time, not at runtime
- For SOUL.md and personality files, validate they produce valid system prompts: parse them, render them, and confirm the output is non-empty

**Warning signs:**
- "API key not found" errors that only appear during agent execution, not at startup
- Config files that were modified during debugging and never reverted
- An agent behaves differently than expected because its config was changed independently
- The same API key is stored in multiple config files and changes must be made in N places
- A config file containing an API key appears in a `git diff` or GitHub commit

**Phase to address:**
Phase 1 (Foundation). Config validation and the `.env` key injection pattern must be in place before any agent config directory is created.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Hardcoded provider/model strings | Fast setup | Changing models means editing code; no A/B testing | Never -- use config from day one |
| Single search provider (no abstraction) | One integration | Provider switch requires finding and replacing all search calls | Only MVP (Phase 1-2). Must abstract by Phase 3 |
| No timeout on agent responses | Simple implementation | Agents can hang forever, no recovery possible | Never -- every agent needs a timeout |
| Natural language agent handoffs | Quick to prototype | "Telephone game" errors compound; cannot validate or type-check | Only in single-agent baseline. Must add structure before multi-agent |
| Synchronous agent execution | Simple control flow | 4 agents x 3 rounds x 30s = 6 minute session; user waits | Acceptable only in Phase 1-2 single-agent. Move to concurrent in Phase 3 |
| Full-context-passing between rounds | Easy to implement | Token costs grow quadratically; context window limits hit | Phase 4 only. Must implement pruning before Phase 5 production |
| Log everything at DEBUG level | Easy to start | Gigabytes of logs per day; cannot find errors in noise | Only during active development. Set INFO level before deployment |
| API keys in config files | Works immediately | Risk of committing keys; key rotation requires editing N files | Never -- use environment variables from day one |
| No search result caching | Simple to implement | 40%+ extra API calls; higher costs and latency | Acceptable only in Phase 1-2. Cache must exist by Phase 3 |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| OpenAI API | Assuming streaming always completes without error | Implement stream-idle watchdog; preserve partial on error |
| Anthropic API | Assuming API keys never rotate | Load keys at runtime from env vars; support key rotation without restart |
| Google Custom Search | Assuming the API will exist indefinitely | Abstract behind interface; have a backup provider ready by 2026 Q4 |
| SerpAPI | Assuming subscription credits roll over | Use pay-as-you-go or monitor burn rate weekly |
| DuckDuckGo | Assuming no rate limits exist | Implement a 1-second delay between requests; handle 429s |
| GitHub / file storage | Assuming `file_put_contents` is atomic | Always use `flock` + temp-file-write-and-rename pattern |
| pcntl_fork | Assuming child can safely use parent's DB connection | Close and reopen all external handles in both parent and child |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Sequential agent execution | Session time grows linearly with agent count | Use `curl_multi_*` for concurrent API calls | At 3+ agents |
| Loading full conversation history every round | Token costs and latency spike each round | Prune/summarize older rounds; use tiered memory | At 3+ debate rounds |
| No response caching for identical queries | Same search hits API for every agent | Cache by (query_hash, search_provider) with TTL | At 2+ agents on same query |
| In-memory-only agent state | Session state lost on crash | File-based checkpoints at each round boundary | Any crash |
| Blocking I/O in PHP CLI | Whole system hangs on one slow API call | Use `curl_multi_*` with `curl_multi_select()` | Any concurrent agent execution |
| Single-process, single-thread | Can't serve CLI and web simultaneously | Separate CLI runner from web server; use different PHP processes | When adding web interface |
| No log rotation | Disk fills up; system crashes | Log rotation at configurable size/age; compression of old logs | ~10,000 sessions (depends on verbosity) |

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| API keys in config files | Accidental commit to version control; key theft | Use `.env` files loaded at runtime; add to `.gitignore` |
| No output sanitization between agents | Prompt injection spreads through agent chain | Validate agent outputs; strip unexpected instructions; require structured format |
| Same API key for all agents | One leaked key exposes all provider access | Per-agent keys with restricted scopes if provider supports it |
| Shared logging of LLM prompts | Sensitive data leaked in logs | Mask/secrets in log output; separate prompt storage from operational logs |
| Arbitrary function execution via agent tools | Agent can execute system commands | Whitelist allowed tools/commands; validate all parameters; no shell injection surfaces |
| No input length limits on agent responses | Context window overflow; cost surge | Hard cap on response tokens per agent per round (configurable per agent) |
| Session files readable by all users | Data leakage across users/sessions | Set file permissions (0600); store in directory with restricted access |
| No rate limiting on search API calls | Exceeds budget; provider bans account | Central rate limiter per search provider; circuit breaker on consecutive failures |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| No progress indicator during multi-round research | User thinks system is frozen | Show real-time status: "Agent 1 researching... Agent 2 debating... Round 2 critique..." |
| Final answer with no rationale | User doesn't trust the result | Always show reasoning trace with citations and agent contributions |
| Interleaved agent output without labels | User can't tell which agent wrote what | Label every output block with agent name, model, and round |
| Session disappears on network error (web) | User loses their work | Auto-save session state after every round; resume on reconnect |
| CLI output scrolls too fast to read | User misses important information | Pagination or structured output; use a step-by-step reveal pattern |
| No way to stop a running session | User must kill the process to abort | Implement a "stop" signal (Ctrl+C handling; abort button in web UI) |
| Raw markdown output without formatting | Web users see unrendered markdown | Render markdown to HTML in web interface; provide plain-text option |

## "Looks Done But Isn't" Checklist

Items that appear complete but are often missing critical pieces:

- [ ] **Agent timeout:** Handler is defined but never enforced in the actual HTTP call (documented timeout != enforced timeout). Verify by sending a deliberately slow request and confirming the agent terminates.
- [ ] **Stream idle timeout:** Connection timeout exists but per-chunk idle timeout does not. A stream that sends one chunk then goes silent will hang forever. Verify by stalling a stream mid-response.
- [ ] **Partial response handling:** The system claims to handle timeouts but discards the accumulated response and starts from scratch. Partial work must be preserved. Verify by cutting a request at 50% completion and checking the preserved output.
- [ ] **Config validation:** Config files are parsed and used but never validated for required fields. A missing API key surfaces as a confusing runtime error, not a clear startup warning. Verify by deleting a required field and observing the error message.
- [ ] **Concurrent write safety:** Session files are written from multiple agents but file locking is absent. Verify by running all agents simultaneously and checking the session file for corruption.
- [ ] **Cross-agent output format:** Each agent is instructed to produce structured output but there's no validation layer. The format drifts with model changes. Verify by switching the model provider and checking if the output still parses.
- [ ] **API key isolation:** Keys are in config files but not in `.gitignore`. A single `git add .` leaks them. Verify by running `git status` after initial setup.
- [ ] **Debate diversity:** Agents are claimed to have diverse perspectives but use the same model provider. They will produce correlated errors. Verify by computing inter-agent agreement on a held-out test set.
- [ ] **Search caching:** Cache is implemented but stores by exact query match only. The same query with different whitespace or punctuation generates a cache miss. Verify by running identical queries with minor variations.
- [ ] **Error recovery:** System claims to handle errors but the retry loop has no maximum and retries indefinitely. Verify by making a provider endpoint permanently unavailable and observing behavior.

## Recovery Strategies

When pitfalls occur despite prevention, how to recover:

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Corrupted session file | LOW (if structured JSONL) or HIGH (if monolithic markdown) | Restore from backup. Implement per-round files to limit blast radius. |
| Rate-limit lockout (all providers) | MEDIUM | Wait for circuit breaker cooldown. Consider manual rate limit reset via shared state file. |
| Agent hangs mid-session | LOW | Kill agent process. Restart session from last successful round checkpoint. |
| Search API provider removed | MEDIUM | Switch to backup provider via abstraction layer. Update config and restart. |
| LLM API key expired | LOW | Update `.env` file. No code changes needed if environment variable pattern is used. |
| Prompt injection attack | HIGH | Isolate affected agents. Rotate all API keys. Review session logs for unauthorized access. |
| Context limit exceeded in debate round | LOW | Reduce number of rounds or implement context pruning. Restart session from round N-1. |
| Config file corruption | LOW | Restore from version control. Config files should never be modified at runtime. |
| Process table full from zombie agents | MEDIUM | Kill all orphan processes. Fix `pcntl_waitpid` handling. Restart orchestrator. |
| Debate quality collapse (echo chamber) | HIGH | Add diverse model provider. Reduce rounds to 2. Implement devil's advocate agent. Rerun session. |

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Over-engineering agents | Phase 1: build single-agent first, measure baseline | Single-agent quality meets user needs before multi-agent expansion |
| Debate echo chambers | Phase 4: diverse models, independent Round 1, limited rounds | Inter-agent agreement measured; conformity detection < 20% false positives |
| Cascading error propagation | Phase 3: schematized handoffs, output validation | Deliberate error injection test: error in Agent A must not reach Agent C |
| No structured handoffs | Phase 3: JSON schemas, typed interfaces at every boundary | Change model provider; verify output format still parses |
| Timeout gaps | Phase 3: 4-layer timeout architecture, stream-idle watchdog | Fault injection: stalled stream terminates in < 120s |
| PHP process management | Phase 2: choose concurrency model (curl_multi, not raw fork) | `ps aux` shows zero zombie processes after 100 sessions |
| Agent-to-agent prompt injection | Phase 3: output validation, least privilege, provenance tags | Penetration test: inject instructions via one agent, verify other agents reject them |
| API rate limiting | Phase 2: central coordinator, circuit breakers, provider failover | All agents hitting same provider simultaneously: no 429s |
| Unbounded token consumption | Phase 4: token budget, context pruning, tiered memory | Session with 4 agents x 3 rounds stays under configurable token cap |
| Session file corruption | Phase 5: file locking, append-only JSONL, per-round files | 100 concurrent writes to same session: no parse failures |
| Logging balance | Phase 5: structured JSONL, log levels, sampling | Search for a specific error across 1000 sessions: < 30 seconds |
| Web search cost/reliability | Phase 2: abstraction layer, caching, provider failover | Primary provider goes down: system switches to backup within 2 rounds |
| Config management sprawl | Phase 1: schema validation, env vars, config health check | Missing required field in config: clear error at startup, not runtime |

## Sources

- "Why Do Multi-Agent LLM Systems Fail?" -- UC Berkeley, arXiv 2503.13657 (2025)
- "When to Use Multi-Agent Systems" -- Anthropic / Claude Blog (2025)
- "Multi-Agent Workflows Often Fail" -- GitHub Engineering Blog (2025)
- "Single LLM Debate, MoLaCE" -- Kim & Torr, arXiv 2512.23518 (2025) -- debate echo chamber research
- "Not All Flips Are Conformity" -- arXiv 2606.00820 (2026) -- harmful conformity in debate
- "Simulating Opinion Dynamics with LLM Agents" -- Chuang et al., NAACL 2024 Findings
- "Prompt Infection" -- Lee & Tiwari, arXiv 2410.07283 (2024)
- "From Prompt Injections to Protocol Exploits" -- arXiv 2506.23260 (2025)
- ServiceNow Agent Prompt Injection -- The Hacker News / AppOmni (Nov 2025)
- "From Agent2Agent Prompt Injection to Runtime Self-Defense" -- Wallarm / Security Boulevard (Dec 2025)
- "The Hidden Token Trap of Agent Orchestration" -- ACM Blog / Abhilash Pakalapati (2025)
- Sub-agent hang bug (GitHub Issue #4173, openclaw) -- production hang pattern
- Sub-agent timeout recovery (GitHub PR #13846, opencode) -- 4-layer timeout fix
- "Tool Timeout" -- FutureAGI Glossary (2026)
- Google Custom Search API shutdown -- dev.to (Jan 2027 deprecation)
- SerpAPI workaround blocked by Google -- ppc.land (Sept 2025)
- PHP pcntl_fork memory and signal handling -- PHP.net notes, Workerman.net community
- PHP curl_multi CPU bug -- PHP Bug #51314
- VS Code Agent Mode session corruption -- GitHub Issue #296890
- "The MEMORY.md Problem" -- Mem0 blog, dev.to community
- Multi-agent observability -- Aliyun engineering blog, W&B Weave (2026)

---
*Pitfalls research for: Multi-agent research and debate system (ResearchAgents)*
*Researched: 2026-06-13*
