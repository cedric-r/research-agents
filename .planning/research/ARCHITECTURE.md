# Architecture Research: Multi-Agent Debate System

**Domain:** Multi-agent research and debate system
**Researched:** 2026-06-13
**Confidence:** HIGH — based on published frameworks (AutoGen, CrewAI, Hermes Council, AgentScope), academic papers (Agent4Debate, ARGUS), and PHP patterns documentation.

## Standard Architecture

### System Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Presentation Layer                            │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────┐  ┌──────────────────────────────┐    │
│  │        CLI REPL          │  │        Web REPL (SSE)        │    │
│  │  (php://stdin + readline)│  │  (PHP built-in server)       │    │
│  └────────────┬─────────────┘  └──────────────┬───────────────┘    │
│               │                                │                    │
├───────────────┴────────────────────────────────┴────────────────────┤
│                       Application Layer                             │
├────────────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │                       SessionManager                            │ │
│  │  - Creates session from user input                              │ │
│  │  - Streams progress to presentation layer (callbacks/SSE)       │ │
│  │  - Writes final transcript to markdown file                     │ │
│  └───────────────────────┬────────────────────────────────────────┘ │
│                          │                                          │
├─────────────────────────┴──────────────────────────────────────────┤
│                         Workflow Layer                               │
├────────────────────────────────────────────────────────────────────┤
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │                         Arbitrator                              │  │
│  │  - Reads config/arbitrator/ for its own personality/config     │  │
│  │  - Discovers agents from config/agents/*/ directories          │  │
│  │  - Delegates question to all agents (Round 1)                  │  │
│  │  - Enforces per-step timeout (cooperative + hard limit)        │  │
│  │  - Collects results and distributes for Round 2 (debate)       │  │
│  │  - Evaluates final responses and selects best answer            │  │
│  └──────────┬─────────────────────────────────────────────────┬───┘  │
│             │  Round 1: Independent research                    │     │
│  ┌──────────┴─────────────────────────────────────────────────┐│     │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐                  ││     │
│  │  │ Agent 1  │  │ Agent 2  │  │ Agent 3  │  ...             ││     │
│  │  │ (Claude) │  │ (GPT-4)  │  │ (Gemini) │                  ││     │
│  │  │ ┌──────┐ │  │ ┌──────┐ │  │ ┌──────┐ │                  ││     │
│  │  │ │LLM   │ │  │ │LLM   │ │  │ │LLM   │ │                  ││     │
│  │  │ │Caller│ │  │ │Caller│ │  │ │Caller│ │                  ││     │
│  │  │ ├──────┤ │  │ ├──────┤ │  │ ├──────┤ │                  ││     │
│  │  │ │Search│ │  │ │Search│ │  │ │Search│ │                  ││     │
│  │  │ │Tools │ │  │ │Tools │ │  │ │Tools │ │                  ││     │
│  │  │ └──────┘ │  │ └──────┘ │  │ └──────┘ │                  ││     │
│  │  └──────────┘  └──────────┘  └──────────┘                  ││     │
│  └─────────────────────────────────────────────────────────────┘│     │
│             │  Round 2: Debate (all agents critique peers)      │     │
│             └───────────────────────────────────────────────────┘     │
├─────────────────────────────────────────────────────────────────────┤
│                        Storage Layer                                  │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌────────────────────┐  ┌─────────────┐          │
│  │ config/     │  │ sessions/          │  │ logs/       │          │
│  │ arbitrator/ │  │ session-{id}.md    │  │ app-{date}. │          │
│  │ agents/     │  │ (full transcript)  │  │ log         │          │
│  └─────────────┘  └────────────────────┘  └─────────────┘          │
└─────────────────────────────────────────────────────────────────────┘
```

### Architecture Philosophy

This system follows a **layered controller pattern** — the Arbitrator acts as a workflow controller, not a smart router. It does not interpret results itself; it delegates interpretation to agents in debate rounds and makes the final selection based on structured evaluation criteria. This keeps the Arbitrator thin and the agents opinionated.

The key distinction from frameworks like AutoGen: agents are **not autonomous actors** that decide when to speak. The Arbitrator controls the turn order explicitly (Round 1 parallel, Round 2 sequential critique). This is appropriate for a 2-round debate system where the interaction protocol is fixed.

### Component Responsibilities

| Component | Responsibility | Implementation Notes |
|-----------|---------------|---------------------|
| **CLI REPL** | Read user question, display real-time progress, show final answer with trace | `readline()` loop in `bin/research`. Non-blocking output via ANSI cursor control. History via `readline_add_history()`. |
| **Web REPL** | Serve HTML form, accept questions, stream progress via SSE, display final result | PHP built-in server (`php -S`). `router.php` routes to controllers. SSE endpoint keeps connection open with `Content-Type: text/event-stream`. |
| **SessionManager** | Create session (UUID + timestamp), persist transcript, coordinate streaming | Writes to `sessions/` directory. YAML frontmatter + markdown body. |
| **Arbitrator** | Orchestrate research workflow, enforce timeouts, manage rounds, select best answer | Reads `config/arbitrator/`. Calls agents sequentially. Maintains in-memory result buffer. |
| **Agent** | Research question using LLM + optional tools, produce structured answer, critique peers | Reads `config/agents/{name}/`. Single class with strategy methods: `research()`, `critique()`. LLM calls via HTTP. |
| **LLM Caller** | Send prompts to LLM provider, handle auth/rate limits/timeouts | Stream-based HTTP with `timeout` in stream context, or cURL if streaming needed. |
| **Search Tools** | Web search / academic paper search | HTTP API calls to configurable providers (Tavily, SerpAPI, arXiv, Semantic Scholar). |
| **Session Store** | Persist markdown transcripts to filesystem | `file_put_contents()` with `LOCK_EX`. Hierarchical by date. |
| **Logger** | Structured timestamped activity log | Date-rotated files. JSON context for structured data. PSR-3-like interface. |

### Comparison with Published Frameworks

| Aspect | This System (Vanilla PHP) | AutoGen (Python) | Hermes Council (Python) |
|--------|--------------------------|-------------------|------------------------|
| **Orchestration** | Explicit turn control (Round 1 -> Round 2 -> Select) | Pub/sub with `RoutedAgent` and subscriptions | Pipeline phases via `orchestrate.py` |
| **Agent execution** | In-process sequential (v1); subprocess (future) | Async concurrent (`asyncio.gather()`) | Subprocess spawning (`hermes -z`) |
| **Debate protocol** | Two-round: independent -> critique | Multi-round iterative exchange | Six-phase: compose, premortem, position, probe, reflect, synthesize |
| **Communication** | Shared in-memory Result objects | `publish_message()` over topics via `TypeSubscription` | File-based context passing |
| **Final selection** | Arbitrator evaluates and selects | Majority voting aggregator | Decision landscape (no forced consensus) |

---

## Recommended Project Structure

```
research-agents/
├── bin/
│   ├── research              # CLI entry point (#!/usr/bin/env php)
│   └── serve                 # Web server entry point
│
├── config/
│   ├── arbitrator/
│   │   ├── config.json       # Provider, model, API key
│   │   ├── SOUL.md           # Personality / judge instructions
│   │   └── preferences.json  # Evaluation criteria, debate settings
│   │
│   └── agents/
│       ├── researcher-alpha/
│       │   ├── config.json       # Model provider, model, API key
│       │   ├── SOUL.md           # Agent personality
│       │   └── preferences.json  # Search providers, tool access flags
│       ├── researcher-beta/
│       │   ├── config.json
│       │   ├── SOUL.md
│       │   └── preferences.json
│       └── .../
│
├── public/
│   ├── index.html            # Web REPL UI (form + SSE client JS)
│   ├── app.js                # SSE client, DOM manipulation
│   └── style.css
│
├── src/
│   ├── Cli/
│   │   └── Repl.php          # Readline loop, command dispatch
│   │
│   ├── Web/
│   │   ├── Router.php        # Request router for PHP built-in server
│   │   ├── ResearchController.php  # POST handler, session creation
│   │   └── StreamController.php    # SSE stream endpoint
│   │
│   ├── Arbitrator/
│   │   ├── Arbitrator.php    # Orchestration logic (rounds, selection)
│   │   ├── Config.php        # Load arbitrator config
│   │   └── Result.php        # Result value object (answer + metadata)
│   │
│   ├── Agent/
│   │   ├── AgentInterface.php    # research(), critique(), getName()
│   │   ├── AgentManager.php      # Discover agents from config dirs, instantiate
│   │   ├── ResearchAgent.php     # Default agent implementation
│   │   ├── Config.php            # Per-agent config loader
│   │   └── Tools/
│   │       ├── LlmClient.php     # HTTP client for LLM API calls
│   │       ├── WebSearch.php     # Web search provider abstraction
│   │       └── PaperSearch.php   # Academic paper search
│   │
│   ├── Debate/
│   │   ├── RoundController.php   # Orchestrate a single debate round
│   │   ├── Protocol.php          # Debate prompt templates
│   │   └── Evaluation.php        # Final answer selection logic
│   │
│   ├── Session/
│   │   ├── Session.php           # Session value object
│   │   ├── SessionManager.php    # Create, persist, stream sessions
│   │   └── Transcript.php        # Markdown transcript renderer
│   │
│   ├── Config/
│   │   └── Loader.php            # Generic JSON + env-var config loader
│   │
│   └── Log/
│       ├── Logger.php            # PSR-3-like structured logger
│       └── Handler/
│           └── RotatingFileHandler.php  # Date-rotated file handler
│
├── sessions/                 # Session transcripts (gitignored)
│   └── 2026-06-13/
│       └── session-{id}.md
│
├── logs/                     # Activity logs (gitignored)
│   └── app-2026-06-13.log
│
├── router.php                # PHP built-in server front controller
├── composer.json
└── README.md
```

### Structure Rationale

- **`src/Arbitrator/`** separated from **`src/Debate/`**: The Arbitrator owns *when* things happen (orchestration); the Debate namespace owns *how* the debate is structured (protocol, evaluation). This means the debate protocol can change without touching orchestration code.

- **`src/Agent/Tools/`** grouped under Agent: Search tools are properties of agents (not shared infrastructure). Each agent may have different search providers or none at all. The `AgentManager` injects tools based on each agent's `preferences.json`.

- **`src/Session/`** as a separate layer: Sessions are not Arbitrator concerns. The `SessionManager` wraps the Arbitrator and captures output. This allows the web REPL to stream progress via SSE while the CLI REPL prints inline.

- **`bin/` vs `public/`**: CLI entry points in `bin/`, web assets in `public/`. The `router.php` at project root serves as the PHP built-in server front controller (the server requires a script at root or specified path).

- **One directory per agent**: Each agent has its own `config.json`, `SOUL.md`, and `preferences.json`. The directory name becomes the agent name. Adding an agent = creating a directory. This matches the project requirement: "Each agent/arbitrator has own directory with config files."

---

## Architectural Patterns

### Pattern 1: Arbitrator as Workflow Controller

**What:** The Arbitrator is a procedural controller that executes a fixed workflow: Round 1 (distribute question, collect answers) -> Round 2 (share answers, collect critiques) -> Evaluate (select best answer). It does not use pub/sub, message queues, or agent-to-agent routing.

**When to use:** When the debate protocol has a fixed, small number of rounds (2-3) and agents do not autonomously decide when to speak. Overkill for single-agent systems; insufficient for open-ended multi-turn conversations.

**Trade-offs:**
- Pro: Simple to implement and reason about. Each step is a synchronous method call.
- Pro: Easy to add timeout enforcement between steps.
- Con: All agents run sequentially (no parallel execution in v1 — agents block waiting for each other).
- Con: Adding new round types requires modifying the controller.

**Example:**
```php
class Arbitrator {
    public function research(string $question): void {
        $this->event('round.start', ['round' => 1]);
        $answers = [];

        foreach ($this->agents as $agent) {
            $this->event('agent.start', ['agent' => $agent->getName()]);
            $answers[$agent->getName()] = $agent->research($question, $this->timeout);
            $this->event('agent.complete', ['agent' => $agent->getName()]);
        }

        $this->event('round.start', ['round' => 2]);
        foreach ($this->agents as $agent) {
            $peers = array_diff_key($answers, [$agent->getName() => null]);
            $critique = $agent->critique($question, $answers[$agent->getName()], $peers);
            $this->event('agent.critique', ['agent' => $agent->getName()]);
        }

        $final = $this->evaluate($answers);
        $this->event('final', ['result' => $final]);
    }
}
```

### Pattern 2: In-Process Agent Execution with Cooperative Timeout

**What:** Agents run as PHP objects in the same process as the Arbitrator, not as subprocesses. Each agent method receives a `$timeout` parameter (seconds) and checks the deadline cooperatively at each "yield point" (before/after LLM calls, before/after search calls). The Arbitrator enforces a hard timeout by throwing after the deadline.

**When to use:** When process isolation is not critical (single-user local tool), and the simplicity of in-process execution outweighs the safety of subprocess isolation. Subprocess model becomes important when agents may crash, consume unbounded memory, or need to run in parallel.

**Trade-offs:**
- Pro: No subprocess lifecycle management (no `proc_open`, pipe handling, zombie cleanup).
- Pro: Agent state is naturally shared (results are PHP objects in memory).
- Pro: Simple debugging — standard PHP error handling, stack traces, `var_dump()`.
- Con: A crashing agent takes down the entire research session.
- Con: No true parallelism — agents run sequentially.
- Con: Memory accumulates — one agent's memory leak affects all.

**Example:**
```php
class ResearchAgent implements AgentInterface {
    public function research(string $question, int $timeout): Result {
        $deadline = time() + $timeout;

        // Step 1: Initial LLM call
        $initialResponse = $this->llm->complete($this->buildResearchPrompt($question));

        if (time() >= $deadline) {
            return new Result($this->name, $initialResponse, partial: true);
        }

        // Step 2: Web search (if configured)
        $searchResults = [];
        if ($this->hasWebSearch()) {
            $searchResults = $this->webSearch->search($question);
        }

        if (time() >= $deadline) {
            $synthesis = $this->llm->complete(
                $this->buildSynthesisPrompt($question, $initialResponse, $searchResults)
            );
            return new Result($this->name, $synthesis, partial: true);
        }

        // Step 3: Final synthesis
        $final = $this->llm->complete(
            $this->buildSynthesisPrompt($question, $initialResponse, $searchResults)
        );

        return new Result($this->name, $final, partial: false);
    }
}

// Arbitrator enforces hard deadline
public function runAgentWithTimeout(AgentInterface $agent, string $question, int $timeout): Result {
    $start = microtime(true);
    try {
        $result = $agent->research($question, $timeout);
        $elapsed = microtime(true) - $start;

        if ($elapsed > $timeout) {
            $this->log->warning('Agent exceeded timeout, using partial result', [
                'agent' => $agent->getName(),
                'elapsed' => $elapsed,
                'timeout' => $timeout,
            ]);
        }

        return $result;
    } catch (\Throwable $e) {
        $this->event('agent.error', ['agent' => $agent->getName(), 'error' => $e->getMessage()]);
        return new Result($agent->getName(), 'Error: ' . $e->getMessage(), error: true);
    }
}
```

### Pattern 3: Session-Aware Event Streaming (Observer)

**What:** The presentation layer (CLI/Web) receives real-time updates from the research workflow through a callback-based observer pattern. The `SessionManager` subscribes as an observer to capture the transcript. The Web REPL's SSE controller subscribes to stream progress to the browser. The CLI REPL subscribes to print progress inline.

**When to use:** When the same workflow output needs to go to multiple destinations (transcript file, CLI display, web SSE stream) without coupling the workflow to any of them.

**Trade-offs:**
- Pro: Single research execution can feed CLI, Web, and file recording simultaneously.
- Pro: Adding a new output format (JSON export, webhook) requires only a new observer.
- Con: Callback overhead for fine-grained events (token-by-token streaming would be too noisy).

**Example:**
```php
interface ResearchObserver {
    public function onEvent(string $type, array $data): void;
}

class SessionManager implements ResearchObserver {
    public function onEvent(string $type, array $data): void {
        $this->transcript->append($type, $data);
        if ($type === 'final') {
            $this->store->persist($this->transcript);
        }
    }
}

class SseStream implements ResearchObserver {
    public function onEvent(string $type, array $data): void {
        echo "event: {$type}\n";
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }
}

class CliDisplay implements ResearchObserver {
    public function onEvent(string $type, array $data): void {
        match ($type) {
            'agent.start' => $this->write("  [{$data['agent']}] Researching..."),
            'agent.complete' => $this->overwrite("  [{$data['agent']}] Done ({$data['elapsed']}s)\n"),
            'final' => $this->displayFinalResult($data['result']),
            default => null,
        };
    }
}
```

### Pattern 4: Agent as Config-Driven Strategy

**What:** Each agent is a `ResearchAgent` instance configured at construction time from its directory. The `AgentManager` scans `config/agents/`, loads each agent's `config.json` + `SOUL.md` + `preferences.json`, and passes the right tools (LLM client, search APIs) based on preferences. The `SOUL.md` content is injected into the agent's system prompt.

**When to use:** When you want to add new agents by creating directories without modifying code. Standard convention in multi-agent systems (Hermes Council, Soul Agent Framework).

**Trade-offs:**
- Pro: Adding agents is purely configuration — no code changes.
- Pro: Agent personality and tools are explicit and auditable in markdown.
- Con: All agents use the same PHP code path — can't add custom capabilities without changing code.
- Con: Config validation is manual — a missing field fails at runtime.

**Example:**
```php
class AgentManager {
    /** @return AgentInterface[] */
    public function discoverAgents(string $agentsDir): array {
        $agents = [];
        foreach (new DirectoryIterator($agentsDir) as $dir) {
            if ($dir->isDot() || !$dir->isDir()) continue;

            $config = Config::load($dir->getPathname() . '/config.json');
            $soul = file_get_contents($dir->getPathname() . '/SOUL.md');
            $prefs = Config::load($dir->getPathname() . '/preferences.json');

            $llm = new LlmClient(provider: $config['provider'], model: $config['model'], apiKey: $config['api_key']);
            $tools = $this->resolveTools($prefs['tools'] ?? []);

            $agents[$dir->getFilename()] = new ResearchAgent(
                name: $dir->getFilename(),
                llm: $llm,
                soul: $soul,
                tools: $tools,
            );
        }
        return $agents;
    }
}
```

### Pattern 5: File-Based Session Store with Markdown Transcript

**What:** Each research session is a single markdown file in `sessions/{date}/session-{shortId}.md`. The file contains YAML frontmatter for metadata (question, date, agents, duration, status) and a markdown body for the full transcript with all rounds, agent responses, critiques, and the final answer.

**When to use:** When you want human-readable, git-trackable, grep-friendly archives with no database dependency. Standard in agent memory systems (Stoa, agent-memory, Soul Agent Framework).

**Trade-offs:**
- Pro: Zero infrastructure. Files are always accessible — no DB migrations, no connection pooling.
- Pro: Human-readable. You can `cat` a session file and understand it immediately.
- Pro: Greppable across all sessions for patterns.
- Pro: Easy to export or share (just a markdown file).
- Con: No indexing — finding "all sessions about X" requires grep or filesystem search.
- Con: No concurrent writes to the same session (single writer, but sessions are per-question so this is fine).

**Example structure:**
```
---
id: a1b2c3d4
question: "What is the best approach to microservices testing?"
date: 2026-06-13T14:30:00+00:00
agents: [researcher-alpha, researcher-beta, researcher-gamma]
duration_seconds: 112
status: completed
---

## Round 1: Independent Research

### researcher-alpha (14.2s)

[Full agent response markdown...]

### researcher-beta (22.1s)

[Full agent response markdown...]

## Round 2: Debate & Critique

### researcher-alpha critiques peers
[Critique of researcher-beta and researcher-gamma...]

### researcher-beta critiques peers
[Critique of researcher-alpha and researcher-gamma...]

## Final Answer

**Selected by arbitrator-alpha**

[Final answer content...]

### Reasoning Trace
1. researcher-alpha provided the most comprehensive coverage...
2. researcher-beta had stronger citations...
3. Consensus on key points X, Y disputes on Z...
```

---

## Data Flow

### Request Flow

```
[User types question in CLI or Web form]
    │
    ▼
[ResearchCoordinator/SessionManager]
    │  Creates session with unique ID
    │  Writes initial metadata to transcript
    ▼
[Arbitrator.orchestrate(question)]
    │
    ├── [Round 1: For each agent sequentially]
    │       Agent.research(question, timeout)
    │           ├── LLMClient.complete(prompt + SOUL)
    │           ├── [Optional] WebSearch.search(question)
    │           ├── [Optional] PaperSearch.search(question)
    │           └── Returns Result(answer, confidence, sources, partial)
    │       Event: agent.complete → Observer(s) update display + transcript
    │
    ├── [Round 2: For each agent sequentially]
    │       Agent.critique(question, ownResult, peerResults)
    │           ├── LLMClient.complete(critiquePrompt + SOUL + peerAnswers)
    │           └── Returns Critique(result, peer, criticisms, agreements)
    │       Event: critique.complete → Observer(s) update display + transcript
    │
    └── [Evaluation]
            Arbitrator.evaluate(allResults, allCritiques)
            ├── Applies criteria from preferences.json
            └── Returns FinalAnswer(content, selectedAgent, reasoning)
            Event: final → Observer(s) update display + write transcript
```

### Control Flow Detail (Round 1 - Sequential Agent Execution)

```
Arbitrator.orchestrate()
    ↓
for each $agent in $agents:
    ↓
Arbitrator.runAgentWithTimeout($agent, $question, $timeout)
    ↓
ResearchAgent.research($question, $remainingTimeout)
    ↓ check deadline ─── exceeded? ──→ return Result(partial: true)
    ↓
LlMClient.complete(researchPrompt + soul)
    ↓ check deadline ─── exceeded? ──→ return Result(partial: true)
    ↓
WebSearch.search($question) ── (if configured)
    ↓ check deadline ─── exceeded? ──→ return Result(partial: true)
    ↓
LlMClient.complete(synthesisPrompt + soul + searchResults)
    ↓
return Result(answer, confidence, sources, partial: false)
    ↓
Event: agent.complete(result)
    ↓
CLI: overwrite "Done" line | Web: send SSE event | Session: append to transcript
    ↓
Next agent...
```

### Data Flow for Debate Round

```
Arbitrator holds all Result objects from Round 1
    ↓
for each $agent in $agents:
    ↓
$peers = all results EXCEPT $agent's own
$critiquePrompt = buildCritiquePrompt(question, ownResult, peerResults)
    ↓
ResearchAgent.critique($critiquePrompt, $timeout)
    ↓
LLMClient.complete(critiquePrompt + soul)
    ↓
return Critique(agentName, peerResults, criticisms, agreements)
    ↓
Event: critique.complete(critique)
```

### State Management

Since this is a single-request, synchronous PHP process (no shared state between requests), state is managed in-memory:

```
SessionManager
    ├── sessionId: string (generated at start)
    ├── startTime: float
    ├── transcript: array (event log for file output)
    │
    └── calls Arbitrator, which maintains:
        ├── agents: AgentInterface[]
        ├── round1Results: array<string, Result>
        ├── round2Critiques: array<string, Critique>
        └── finalAnswer: ?FinalAnswer

All state is ephemeral — destroyed when the PHP process exits.
Persistence is only in the session markdown file (written at end)
and log files (appended during execution).
```

### Key Data Flows

1. **Research question flow:** User -> REPL -> SessionManager -> Arbitrator -> Agents -> LLM APIs -> Results -> Arbitrator evaluation -> Final answer -> Display + Transcript

2. **Event stream flow:** Arbitrator calls `$this->observers->notify()` at each lifecycle point -> SessionManager appends to in-memory transcript -> Transcript written to markdown at end; SSE controller sends JSON event to browser; CLI display updates terminal

3. **Config flow:** `bin/research` or `router.php` loads bootstrap -> Arbitrator/Config loads `config/arbitrator/config.json` -> AgentManager scans `config/agents/*/` -> Each agent loads its config and tools

4. **Timeout flow:** Arbitrator sets `$deadline = time() + $timeout` before calling agent -> Agent checks `time() >= $deadline` at each yield point -> Returns partial result if exceeded -> Arbitrator catches the partial result and moves to next agent

---

## Process Model

### In-Process Sequential (Recommended for v1)

All agents execute in the same PHP process, sequentially. The Arbitrator calls each agent's `research()` method, waits for it to return, then calls the next.

**Why this is the right choice for v1:**
- PHP is single-threaded by nature — truly parallel execution requires subprocesses or extensions.
- The sequential model has zero overhead for process management, pipe I/O, or IPC.
- LLM API calls are HTTP requests — they block the process naturally, but this is fine for a single-user tool.
- The SSE web endpoint keeps the connection open during execution, which works naturally with sequential execution.
- Observation: "Agent research takes 15-30 seconds each" — sequential execution with 3 agents takes ~60-90 seconds total, which is acceptable for a research tool.

**LLM API Call Mechanism:**
```php
// Stream-based HTTP (no cURL dependency)
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}",
        'content' => json_encode($payload),
        'timeout' => 60,  // Total request timeout
        'ignore_errors' => true,
    ],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
]);
$response = @file_get_contents($url, false, $context);
```

**When to reconsider (subprocesses for v2):**
- If agents need to run in parallel to reduce total wall-clock time.
- If an agent's LLM provider call hangs and blocks the entire session (hard timeout via `proc_terminate` would be cleaner).
- If memory isolation matters (one agent's large context shouldn't bloat other agents' memory).

### Future: Subprocess Execution Pattern

If subprocess execution is needed, the pattern is:

```php
class SubprocessAgent implements AgentInterface {
    public function research(string $question, int $timeout): Result {
        $descriptors = [
            0 => ['pipe', 'r'],   // stdin
            1 => ['pipe', 'w'],   // stdout
            2 => ['pipe', 'w'],   // stderr
        ];

        $process = proc_open(
            "exec php " . escapeshellarg(__DIR__ . '/../../bin/agent-worker'),
            $descriptors,
            $pipes
        );

        stream_set_blocking($pipes[1], 0);
        stream_set_blocking($pipes[2], 0);

        // Send question as JSON to agent process
        fwrite($pipes[0], json_encode(['action' => 'research', 'question' => $question]));
        fclose($pipes[0]);

        // Poll with timeout
        $deadline = time() + $timeout;
        $output = '';
        while (true) {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);

            if (!$status['running']) break;
            if (time() >= $deadline) {
                proc_terminate($process, 15); // SIGTERM
                usleep(100000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }
                break;
            }
            usleep(100000);
        }

        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($process);

        return Result::fromJson($output ?: '{"partial":true,"error":"timeout"}');
    }
}
```

---

## Timeout Handling Strategy

### Multi-Level Timeout Architecture

The system has three levels of timeout enforcement:

```
Level 1: PHP Maximum Execution Time (safety net)
    set_time_limit(300)  // 5 minutes for entire script
    If any code path hangs without hitting Level 2 or 3, PHP kills the process.

Level 2: HTTP Socket Timeout (per API call)
    stream_context_create(['http' => ['timeout' => 60]])
    Each LLM/search API call is bounded individually.
    If one API call hangs, only that call times out, not the whole agent.

Level 3: Agent-Step Deadline (cooperative + hard)
    $deadline = time() + $perStepTimeout
    Cooperative: Agent checks time() at yield points, returns partial result.
    Hard: Arbitrator enforces after deadline + grace period with exception.
```

### Timeout Configuration

Configured in `config/arbitrator/preferences.json`:

```json
{
    "timeout": {
        "per_agent_step": 90,
        "llm_api_call": 60,
        "search_api_call": 30,
        "total_session": 300
    }
}
```

### Partial Result Contract

When an agent times out, it must return a `Result` with `partial: true`. The transcript and display distinguish partial results from complete ones. The Arbitrator may deprioritize partial results during final selection.

```
Agent timeout ──→ Result(partial: true, answer: "…as far as I got…")
                      ↓
                  Transcript marks as [PARTIAL - timed out]
                      ↓
                  Arbitrator evaluation: penalizes in scoring
                      ↓
                  Final answer still selected from any agent
```

---

## Config Directory Architecture

### Configuration Design

Each agent has its own directory with three files:

| File | Purpose | Format | Example |
|------|---------|--------|---------|
| `config.json` | Machine config (provider, model, API key) | JSON | `{"provider":"anthropic","model":"claude-sonnet-4-20250514","api_key":"${ANTHROPIC_API_KEY}"}` |
| `SOUL.md` | Personality/behavior instructions | Markdown | `You are a skeptical researcher with expertise in distributed systems...` |
| `preferences.json` | Tool access, search providers, agent metadata | JSON | `{"tools":{"web_search":{"provider":"tavily","api_key":"${TAVILY_API_KEY}"}}}` |

### API Key Resolution

For security, API keys resolve in this priority order:
1. Environment variable reference: `"${ANTHROPIC_API_KEY}"` -> `getenv('ANTHROPIC_API_KEY')`
2. Direct string value in config (fallback for simpler setups)

The `Config` loader detects `${VAR_NAME}` patterns and substitutes environment variables.

### Agent Discovery

The `AgentManager` scans `config/agents/` at runtime:

```
config/agents/
├── researcher-alpha/     → Agent named "researcher-alpha"
│   ├── config.json
│   ├── SOUL.md
│   └── preferences.json
├── skeptic-bot/         → Agent named "skeptic-bot"
│   ├── config.json
│   ├── SOUL.md
│   └── preferences.json
└── paper-expert/        → Agent named "paper-expert"
    ├── config.json
    ├── SOUL.md
    └── preferences.json
```

Adding an agent = `mkdir config/agents/my-agent; cp template/* my-agent/; edit config.json; edit SOUL.md`.

---

## REPL Architectures

### CLI REPL

```
┌────────────────────────────────────────────┐
│  bin/research                                │
│                                              │
│  research> What is the best approach to...   │
│  [Session a1b2c3 starting...]               │
│    [researcher-alpha] Researching...         │
│    [researcher-alpha] Done (14.2s)           │
│    [researcher-beta] Researching...          │
│    [researcher-beta] Done (22.1s)            │
│    [skeptic-bot] Researching...             │
│    [skeptic-bot] Done (18.7s)               │
│                                              │
│  Round 2: Debate                              │
│    [researcher-alpha] Critiquing peers...    │
│    ...                                       │
│                                              │
│  ┌─── Final Answer ───────────────────────┐  │
│  │  Selected from: researcher-alpha         │  │
│  │                                          │  │
│  │  [Full answer content...]               │  │
│  │                                          │  │
│  │  Reasoning:                              │  │
│  │  1. Most comprehensive coverage...       │  │
│  │  2. All citations verified...            │  │
│  └──────────────────────────────────────────┘  │
│                                              │
│  Session saved to sessions/2026-06-13/       │
│  research> _                                 │
└────────────────────────────────────────────┘
```

**Implementation:**
```php
class Repl {
    public function run(): void {
        echo "ResearchAgents v1.0\n";
        echo "Type 'exit' to quit.\n\n";

        $historyFile = getenv('HOME') . '/.research_history';
        if (file_exists($historyFile)) readline_read_history($historyFile);

        while (true) {
            $input = readline('research> ');
            if ($input === false) break;

            $input = trim($input);

            if ($input === '' || $input === "\n") continue;
            if (in_array($input, ['exit', 'quit', 'q'])) break;
            if ($input === 'help') { $this->showHelp(); continue; }

            readline_add_history($input);

            $this->runResearch($input);
        }

        readline_write_history($historyFile);
    }

    private function runResearch(string $question): void {
        $session = $this->sessionManager;
        $session->addObserver(new CliDisplay());

        $result = $session->run($question);

        echo "\nSession saved to " . $result->path . "\n\n";
    }
}
```

### Web REPL

**Architecture:**
- PHP built-in server: `php -S localhost:8080 router.php`
- `router.php` routes requests to controllers or serves static files
- SSE endpoint keeps a connection open during research

**Request Flow:**
```
GET  /         -> public/index.html (straight HTML form)
POST /research -> Web\ResearchController
                  1. Creates session
                  2. Starts research in a new PHP process (via proc_open)
                  3. Returns session ID immediately
GET  /research/{id}  -> Web\StreamController (SSE)
                  1. Periodically checks session status file
                  2. Streams progress as SSE events
                  3. Streams final result when complete
```

Wait — this reveals a complication. With in-process sequential execution, the POST handler blocks while research runs. If we use SSE, we need the research to run in a way that the SSE endpoint can stream progress.

**Approach 1: Single SSE endpoint (blocking, but streamed)**
- POST to `/research` actually returns the SSE stream directly
- The form submission opens directly to the SSE URL
- Simple, but the POST response IS the research execution
- Browser connects to one URL and receives all events

**Approach 2: Separate trigger and stream (non-blocking)**
- POST to `/research` spawns a background PHP process (`proc_open` with no pipes)
- Returns immediately with session ID
- Browser connects to `/research/{id}/stream` via SSE
- SSE endpoint polls a status file or pipe for events
- More complex but supports multiple tabs, reconnection

**Approach 1 is simpler and recommended for v1.**

```
Browser:
  Form submits to /research
  Server responds with Content-Type: text/event-stream
  Browser's EventSource receives research events as they happen
  Page updates DOM as events stream in
```

```html
<!-- public/index.html -->
<form id="research-form">
    <textarea name="question" rows="3" cols="60"></textarea>
    <button type="submit">Research</button>
</form>
<div id="output"></div>

<script>
const form = document.getElementById('research-form');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(form);

    // Submit creates session and returns SSE URL
    const res = await fetch('/research', { method: 'POST', body: formData });
    const { sessionId } = await res.json();

    const evtSource = new EventSource(`/research/${sessionId}/stream`);

    evtSource.addEventListener('agent.start', (e) => {
        const data = JSON.parse(e.data);
        appendOutput(`[${data.agent}] Researching...`);
    });

    evtSource.addEventListener('agent.complete', (e) => {
        const data = JSON.parse(e.data);
        appendOutput(`[${data.agent}] Done (${data.elapsed}s)`);
    });

    evtSource.addEventListener('final', (e) => {
        const data = JSON.parse(e.data);
        displayFinal(data);
        evtSource.close();
    });

    evtSource.addEventListener('error', () => {
        // EventSource auto-reconnects
    });
});
</script>
```

```
PHP built-in server router:

/research POST -> Web\ResearchController
    Creates session, stores session state
    Spawns research as: proc_open("php bin/background-research {sessionId}", ...)
    Returns { sessionId: "..." }
    (Research runs in background, writes events to shared state)

/research/{id}/stream GET -> Web\StreamController
    Opens SSE connection
    Reads events from shared state file or SQLite
    Streams as SSE events
    Closes when 'final' event written
```

**Approach 2 is necessary** because the PHP built-in server is single-threaded per request. If the POST handler blocks for 90 seconds doing research, it blocks the SSE endpoint too (both hit the same server).

So for the web REPL:
1. POST handler creates session ID and spawns a background PHP process
2. Background process writes events to a shared file (e.g., `sessions/{sessionId}/events.jsonl`)
3. SSE endpoint reads from the events file and streams to browser
4. When "final" event appears, SSE closes

```php
class ResearchController {
    public function handle(string $question): void {
        $sessionId = bin2hex(random_bytes(8));
        $sessionDir = __DIR__ . '/../../sessions/' . date('Y-m-d') . '/' . $sessionId;
        mkdir($sessionDir, 0755, true);

        // Spawn background research process
        $cmd = sprintf(
            'exec php %s/bin/background-research %s %s > /dev/null 2>&1 &',
            escapeshellarg(__DIR__ . '/../..'),
            escapeshellarg($sessionId),
            escapeshellarg($question)
        );
        exec($cmd);

        header('Content-Type: application/json');
        echo json_encode(['sessionId' => $sessionId]);
    }
}

class StreamController {
    public function stream(string $sessionId): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');

        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', false);
        while (ob_get_level()) ob_end_clean();

        $eventsFile = $this->getEventsPath($sessionId);
        $lastPosition = 0;

        while (true) {
            if (connection_aborted()) break;

            if (file_exists($eventsFile)) {
                $fh = fopen($eventsFile, 'r');
                fseek($fh, $lastPosition);
                while ($line = fgets($fh)) {
                    $event = json_decode($line, true);
                    echo "event: {$event['type']}\n";
                    echo "data: {$event['data']}\n\n";
                    ob_flush(); flush();

                    if ($event['type'] === 'final') {
                        fclose($fh);
                        break 2; // Exit both loops
                    }
                }
                $lastPosition = ftell($fh);
                fclose($fh);
            }

            sleep(1);
        }
    }
}
```

---

## Logging Architecture

### Logger Design

Vanilla PHP implementation of PSR-3-like structured logging without external dependencies:

```php
interface LoggerInterface {
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
}

class Logger implements LoggerInterface {
    private string $channel;
    private string $logDir;
    private int $retentionDays;

    public function info(string $message, array $context = []): void {
        $this->write('INFO', $message, $context);
    }

    private function write(string $level, string $message, array $context): void {
        $entry = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d\TH:i:s.uP'),
            $this->channel,
            $level,
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        $logFile = $this->logDir . '/app-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
```

### Log Format

```
[2026-06-13T14:30:00.123456+00:00] arbitrator.INFO: Starting research session {"session_id":"a1b2c3","question":"What is X?"}
[2026-06-13T14:30:01.456789+00:00] agent.researcher-alpha.INFO: Research started {"timeout":90}
[2026-06-13T14:30:15.789012+00:00] agent.researcher-alpha.INFO: LLM call completed {"tokens":2048,"duration":14.2}
[2026-06-13T14:30:16.012345+00:00] agent.researcher-alpha.INFO: Research completed {"partial":false,"duration":14.5}
[2026-06-13T14:30:16.123456+00:00] arbitrator.WARNING: researcher-beta approaching timeout {"elapsed":85,"timeout":90}
```

### Log Channels

| Channel | Used By | Purpose |
|---------|---------|---------|
| `arbitrator` | Arbitrator | Session lifecycle, timeout decisions, final selection |
| `agent.{name}` | Agent instances | Per-agent activity, LLM calls, search queries |
| `system` | Bootstrapping | Config loading errors, process start/stop |

### Log Rotation

Date-based rotation via file naming:
```
logs/
├── app-2026-06-13.log
├── app-2026-06-14.log
├── app-2026-06-15.log
└── ...
```

Retention: delete files older than `$retentionDays` on each write (or via a separate cron/cleanup script).

---

## Build Order (Dependency-Driven)

### Phase 1: Foundation (No agents, no debate)
```
1. Config/Loader.php          — JSON + env-var config loading
2. Log/Logger.php             — Structured file logging
3. Agent/Config.php           — Per-directory agent config loading
4. Arbitrator/Config.php      — Arbitrator config loading
```
**Why first:** Everything depends on config and logging. Without these, nothing else works.

### Phase 2: Agent Core (Single agent, no debate)
```
5. Agent/AgentInterface.php   — Agent contract
6. Agent/Tools/LlmClient.php  — HTTP LLM API caller
7. Agent/ResearchAgent.php    — Single research implementation
8. Agent/AgentManager.php     — Agent discovery from filesystem
```
**Why second:** Need at least one working agent before building the arbitrator or debate system.

### Phase 3: Arbitrator (Basic orchestration)
```
9. Arbitrator/Result.php      — Result value object
10. Arbitrator/Arbitrator.php — Round 1 orchestration only
11. Debate/Protocol.php       — Debate prompt templates
12. Debate/RoundController.php— Round 2 orchestration
13. Arbitrator/Arbitrator.php — Full two-round workflow
```
**Why third:** Arbitrator needs agents to orchestrate, but debate round is built on top of basic orchestration.

### Phase 4: Storage and Presentation
```
14. Session/Session.php       — Session model
15. Session/Transcript.php    — Markdown transcript builder
16. Session/SessionManager.php— Session lifecycle + observer pattern
17. Cli/Repl.php              — CLI REPL
18. Web/*                     — Web REPL (router, controllers, SSE)
```
**Why fourth:** Storage needs data from the workflow; REPLs need storage. These are interface layers.

---

## Anti-Patterns

### Anti-Pattern 1: Subprocess-Based Agents as Default

**What people do:** Starting with `proc_open` for every agent, managing pipes, signals, and process lifecycle.

**Why it's wrong:** For a single-user local tool with 2-5 agents and sequential execution, subprocesses add enormous complexity with zero benefit:
- Pipe buffer deadlocks (writing to stdin without reading stdout)
- Zombie processes on crash
- Signal propagation confusion (PHP forwards signals to process groups)
- Serialization/deserialization of results through JSON over pipes
- `proc_get_status()` quirks (exitcode lost on second call pre-PHP 8.3)

**Do this instead:** In-process agents with cooperative timeout. Only move to subprocesses if you prove that:
1. An agent crash corrupts another agent's state, OR
2. You need true parallel execution to reduce total time, OR
3. An agent's memory consumption affects other agents

### Anti-Pattern 2: Blocking Web REPL Without Progress

**What people do:** The web form submits, the PHP process blocks for 90+ seconds doing research, and the user stares at a blank page until it completes (or the browser times out the HTTP request).

**Why it's wrong:** Users abandon the page thinking it's broken. Browsers may timeout HTTP requests at 60-120 seconds. No confidence the system is working.

**Do this instead:** Always stream progress on the web interface. Use SSE or poll a status file. Show "Agent 1 researching...", "Agent 2 researching..." so the user sees continuous progress.

### Anti-Pattern 3: Vague Debate Prompts

**What people do:** "Review each other's answers and improve them." This produces vague, agreeable critiques with no structure.

**Why it's wrong:** Research shows specific probe prompts (Karadzhov et al. 2024, R=0.41) produce significantly better debate outcomes. "Review my answer" produces "Looks good!" not substantive critique.

**Do this instead:** Structured critique prompts with explicit sections:
- "Identify specific factual errors in each peer's answer"
- "What evidence did they cite that you disagree with?"
- "What did they miss that you consider important?"
- "Rate their confidence: appropriate, overconfident, underconfident?"

### Anti-Pattern 4: Forced Consensus in Final Selection

**What people do:** Making the Arbitrator synthesize all answers into a single consensus that pleases everyone, even when agents genuinely disagree.

**Why it's wrong:** When agents with different models/personalities genuinely disagree, forced consensus produces a watered-down answer that satisfies no one. The "consensus hallucination" problem is documented in Hermes Council research.

**Do this instead:** If agents disagree on a key point, surface the disagreement in the final output:
- "All agents agree on X, Y"
- "researcher-alpha and researcher-beta disagree on Z"
- "Arbitrator selected researcher-alpha's position on Z because..."

---

## Scaling Considerations

**Context:** This is a single-tenant, local tool. "Scaling" means handling longer research sessions and more agents, not more users.

| Scale | Architecture Adjustments |
|-------|--------------------------|
| 1-3 agents, 60s research | In-process sequential is fine. No changes needed. |
| 4-10 agents, 120s research | Sequential becomes slow (10 agents x 30s each = 5min). Consider subprocess + parallel execution. |
| 10+ agents, long research | Need subprocess pool with concurrency limit. Consider async HTTP with `curl_multi_exec()`. |

### Scaling Priorities

1. **First bottleneck:** Total research time grows linearly with agent count. With sequential execution and 30s per agent, 5 agents = 2.5 minutes, 10 agents = 5 minutes.
   - **Fix:** Parallel agent execution via `proc_open` subprocesses, collected with polling. Or async HTTP via `curl_multi_exec()` for LLM calls.

2. **Second bottleneck:** PHP memory consumption with large LLM responses (context windows of 128K+ tokens = ~500KB per response).
   - **Fix:** Stream long responses to disk instead of keeping them in memory. Limit in-memory storage to abstracts/summaries.

---

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Anthropic API (Claude) | HTTP POST to `api.anthropic.com/v1/messages` | Uses `x-api-key` header. Model varies per agent config. |
| OpenAI API (GPT-4) | HTTP POST to `api.openai.com/v1/chat/completions | Uses `Authorization: Bearer` header. |
| Google API (Gemini) | HTTP POST to `generativelanguage.googleapis.com/v1/models/...` | API key in query param. |
| Tavily Web Search | HTTP POST to `api.tavily.com/search` | Returns structured results with scores. |
| arXiv API | HTTP GET to `export.arxiv.org/api/query` | Standard arXiv API, returns ATOM XML. |
| Semantic Scholar | HTTP GET to `api.semanticscholar.org/graph/v1/paper/search` | Returns JSON with paper metadata. |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| REPL -> SessionManager | Method call + Observer pattern | REPL subscribes to events; SessionManager orchestrates |
| SessionManager -> Arbitrator | Method call (synchronous) | `Arbitrator.orchestrate()` returns when complete |
| Arbitrator -> Agent | Method call per step (sequential) | `Agent.research()` + `Agent.critique()` return Results |
| Agent -> LlmClient | Method call (blocking HTTP) | `$llm->complete($prompt)` returns response string |
| Agent -> SearchTool | Method call (blocking HTTP) | `$search->search($query)` returns structured results |
| SessionManager -> Transcript | Method call (in-memory append) | Event data accumulated, written to file at end |
| SessionManager -> Log/Logger | Method call during events | Timestamped, structured entries written immediately |

---

## Sources

- **Hermes Council** — Multi-agent debate with compose/premortem/position/probe/reflect/synthesize protocol. Pure SKILL.md design. [GitHub](https://github.com/magnus919/hermes-council)
- **AutoGen Multi-Agent Debate Pattern** — Microsoft's pub/sub architecture with `RoutedAgent`, sparse communication topology, and majority voting. [AutoGen Docs](https://microsoft.github.io/autogen/0.5.1/user-guide/core-user-guide/design-patterns/multi-agent-debate.html)
- **Agent4Debate** (ICASSP 2026) — Dynamic multi-agent framework with searcher/analyzer/writer/reviewer roles. [arXiv](https://ar5iv.labs.arxiv.org/html/2408.04472)
- **AgentScope Multi-Agent Debate** — MsgHub broadcast pattern with structured moderator output. [AgentScope Docs](https://doc.agentscope.io/tutorial/workflow_multiagent_debate.html)
- **ARGUS** — Bayesian aggregation with Conceptual Debate Graph (C-DAG), PROV-O provenance. [PyPI](https://pypi.org/project/argus-debate-ai/1.3/)
- **PHP proc_get_status Manual** — Process status polling, exit code caching in PHP 8.3+. [PHP Manual](https://www.php.net/manual/en/function.proc-get-status.php)
- **PHP proc_terminate Manual** — Process signal handling, SIGTERM/SIGKILL patterns. [PHP Manual](https://www.php.net/manual/en/function.proc-terminate.php)
- **PHP readline Extension** — CLI history, tab completion, custom prompts. [PHP Manual](https://www.php.net/manual/en/book.readline.php)
- **Karadzhov et al. 2024** — Probing for reasoning as strongest predictor of group performance gain in multi-agent debate.
- **Midokura Multi-Agent Orchestration** — Peer reactions and learning notes as structured interaction types. [Midokura](https://midokura.com/infrastructure-for-the-agent-native-era-towards-multi-agent-orchestration/)
- **INOSX agent-memory** — File-based memory with categorized markdown vaults, session checkpoints. [GitHub](https://github.com/INOSX/agent-memory)
- **Stoa** — Three-layer architecture (wiki/raw/sessions) with session capture/harvest/crystallize pipeline. [GitHub](https://github.com/marcoskichel/stoa)
- **DevBytes PHP SSE Guide** — PHP built-in server SSE implementation with output buffering management. [DevBytes](https://devbytes.co.in/news/how-to-implement-server-sent-events-with-phps-native-features)
- **Soul Agent Framework** — SOUL.md/MEMORY.md/TOOLS.md pattern for multi-agent AI systems. [GitHub](https://github.com/mingrath/soul-agent-framework)

---
*Architecture research for: Multi-agent research and debate system (Vanilla PHP)*
*Researched: 2026-06-13*
