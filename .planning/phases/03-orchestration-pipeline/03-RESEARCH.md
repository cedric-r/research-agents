# Phase 3: Orchestration Pipeline - Research

**Researched:** 2026-06-13
**Domain:** Process-level parallel execution, signal handling, IPC, timeout architecture
**Confidence:** HIGH (findings verified via PHP manual and runtime checks)

## Summary

Phase 3 delivers the Arbitrator orchestrator that runs all configured agents in parallel using `pcntl_fork`, enforces per-step timeouts via the 4-layer architecture, and collects structured results via temp-file IPC.

**Critical findings:**
1. The current `HttpHelper` does NOT set `CURLOPT_NOSIGNAL` -- this **must** be added before forking, otherwise libcurl's internal SIGALRM usage conflicts with fork+signal architecture. Without it, the 4-layer timeout breaks on fork.
2. PHP's `max_execution_time` defaults to `0` (unlimited) in CLI mode. Layer 1 provides NO protection unless explicitly set -- document as inactive or set a safe upper bound (e.g., 300s) in Arbitrator bootstrap.
3. `file_put_contents` is NOT async-signal-safe in PHP signal handlers. The SIGTERM handler in child processes must use a flag-based pattern, not direct file I/O. The actual partial answer write happens in main execution context after the signal handler returns.
4. Signal handlers and pending alarms are inherited across `fork()`. The child process **must** reset `SIGALRM` to `SIG_DFL` and call `pcntl_alarm(0)` to avoid inherited timeout behavior.
5. The polling-based wait loop (`pcntl_waitpid` + `WNOHANG` + `usleep`) is safer than `pcntl_alarm` for parent timeout enforcement, because it avoids signal conflicts entirely and handles batch concurrency more naturally.

**Primary recommendation:** Polling loop for parent timeout enforcement (check elapsed against each child's deadline, `posix_kill(SIGTERM)` when exceeded), flag-based SIGTERM handler in children with deferred file write from main context.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** New `src/Arbitrator/` namespace for the Arbitrator class. Clean separation from AgentManager and ResearchAgent.
- **D-02:** Arbitrator replaces the orchestration loop currently in `AgentManager::research()`. Uses AgentManager **only** for agent discovery (`getAgentConfigs()`). Spawns and manages agent execution itself.
- **D-03:** `research.php` creates the Arbitrator instance and calls `$arbitrator->research()`. Minimal CLI change -- keeps the same entry point.
- **D-04:** Arbitrator returns array of agent results in the same shape as current `AgentManager::research()` -- `array<string, array{answer, model, response_time_ms, usage, correlation_id}>`. research.php output formatting code stays compatible.
- **D-05:** `pcntl_fork` for process-level parallel execution. Each agent runs in its own forked child. Parent can SIGTERM on timeout. Full error isolation.
- **D-06:** Configurable max concurrent agents (default 5). Configured in Arbitrator configuration. If more agents exist than the limit, remaining agents wait in a queue.
- **D-07:** IPC via temp files. Each child writes its result to `sys_get_temp_dir()/agent_{name}_{correlationId}.json`. Parent reads after child exits or is killed. Works even on SIGTERM -- temp file persists.
- **D-08:** Simple `pcntl_waitpid()` loop for parent tracking child completion and status.
- **D-09:** Per-step timeout configurable per-agent in `config.json`. Default: 60 seconds.
- **D-10:** Graceful shutdown on timeout. Child process registers a SIGTERM handler -- catches the signal, writes a `PARTIAL_ANSWER` marker with whatever result it has buffered to the temp file, then exits.
- **D-11:** If temp file is empty or missing on timeout, display: `"[Agent name] timed out -- no partial answer"`.
- **D-12:** `pcntl_alarm` + `SIGALRM` for timeout enforcement from parent process.
- **D-13:** Layer 1 (PHP max_execution_time) -- **Implicit.** Rely on existing PHP defaults. Document but don't add code.
- **D-14:** Layer 2 (HTTP socket timeout) -- **Already implemented** in `HttpHelper` (`CURLOPT_TIMEOUT=60s`, `CURLOPT_CONNECTTIMEOUT=10s`). No changes needed.
- **D-15:** Layer 3 (stream-idle watchdog) -- **Skipped for v1.** No streaming in current LLM implementation (single `curl_exec` response). Defer to v2.
- **D-16:** Layer 4 (cooperative agent-step deadline) -- **Simple deadline check** in `ResearchAgent::research()`. Before each major step (tool call, LLM call), check elapsed time. If less than 5s remain before agent timeout, skip remaining steps and return what's ready.

### Claude's Discretion
- Exact temp file naming convention and cleanup strategy
- Signal handler registration details in child process
- Deadline check implementation specifics in ResearchAgent
- Arbitrator constructor signature and config schema details

### Deferred Ideas (OUT OF SCOPE)
- Stream-idle watchdog (Layer 3): Deferred to v2.
- Parallel CLI progress display: Defer to Phase 5 (CLI-03).
- Arbitrator evaluating Round 1 quality: That's Phase 4 (ORCH-05, ORCH-06).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| ORCH-01 | Arbitrator discovers all configured agents via AgentManager | Reuses existing `AgentManager::getAgentConfigs()` from Phase 2. No new discovery logic needed. |
| ORCH-02 | Arbitrator distributes research question to all agents for Round 1 | Fork-based parallel execution with max-concurrent batching. Each child creates a fresh ResearchAgent instance. |
| ORCH-03 | Arbitrator enforces per-step timeout, instructs agents to stop and provide best partial answer | 4-layer timeout cascade: L1 (PHP max_execution_time -- inactive by default in CLI), L2 (HTTP socket -- existing), L3 (deferred), L4 (cooperative deadline check). Parent can SIGTERM/SIGKILL unresponsive children. |
| ORCH-04 | Arbitrator collects Round 1 independent answers from all agents | Temp file IPC: each child writes `agent_{name}_{correlationId}.json` to `sys_get_temp_dir()`. Parent reads after child exits or is killed. |
| ORCH-10 | 4-layer timeout architecture: PHP max execution, HTTP socket, stream-idle watchdog, cooperative agent-step deadline | L1: CLI default is 0 (unlimited) -- must document or set explicit upper bound. L2: Already implemented in HttpHelper (60s timeout). L3: Skipped for v1. L4: Simple `microtime(true)` check before each ResearchAgent step with 5s buffer. |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Agent discovery | Arbitrator | AgentManager | Arbitrator calls AgentManager::getAgentConfigs() for discovery only. No duplicate logic. |
| Process spawning | Arbitrator | OS (pcntl_fork) | Arbitrator manages the fork/wait/reap lifecycle. OS handles actual process creation. |
| Per-agent timeout | Arbitrator (enforcement) | ResearchAgent (cooperative) | Arbitrator enforces via SIGTERM + SIGKILL. ResearchAgent cooperates via deadline checks. |
| LLM API call | ResearchAgent | LlmClient + HttpHelper | Same as Phase 2. Each forked child uses own HttpHelper instance. |
| Tool execution | ResearchAgent | ToolRegistry + tools | Same as Phase 2. Fresh instances in each forked child. |
| Result collection | Arbitrator (read temp files) | Filesystem (IPC storage) | Arbitrator reads child results from temp files. Filesystem is the communication channel. |
| Temp file cleanup | Arbitrator | — | Arbitrator must clean temp files after reading, both on success and failure paths. |

## Standard Stack

### Core

| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| `pcntl_fork()` | Bundled w/ PHP 8.5 | Process forking for parallel agent execution | D-05 locked decision. Available in CLI mode only. Only way to get OS-level parallelism in vanilla PHP. |
| `pcntl_waitpid()` | Bundled w/ PHP 8.5 | Child process reaping + status check | D-08 locked decision. Non-blocking with WNOHANG flag. |
| `posix_kill()` | Bundled w/ PHP 8.5 | Send SIGTERM/SIGKILL to child processes | D-05 requirement. Parent signals child on timeout. |
| `pcntl_async_signals(true)` | Bundled w/ PHP 8.5 | Async signal dispatch (PHP 7.1+) | **Preferred** over `declare(ticks=1)` -- zero performance overhead. Required for SIGTERM handler in child. |
| `sys_get_temp_dir()` | Bundled w/ PHP 8.5 | Temp file location for IPC | D-07 locked decision. Returns `/tmp/claude-1000` on this system. |

### Required Config Changes

| Package | Change Required | Why |
|---------|----------------|-----|
| `HttpHelper` | Add `CURLOPT_NOSIGNAL => true` to `buildCurlOptions()` | Without this, libcurl uses SIGALRM internally for `CURLOPT_TIMEOUT`. After fork, this conflicts with parent's SIGALRM usage (D-12) and can cause spurious timeout errors (curl error 28). Verified via PHP manual: the `CURLOPT_NOSIGNAL` flag tells libcurl to use `poll()`/`select()` instead of signals for timeout. **No downside.** |
| `ResearchAgent` | Add deadline check before tool calls and LLM call | D-16 requirement. Check `microtime(true) > $deadline - 5` before each major step. |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `pcntl_fork` for parallelism | `amphp/parallel` | Adds ~5 Composer deps. Breaks zero-dependency rule. Not justified for v1 -- fork management isn't complex enough. |
| Temp file IPC | Shared memory (`shmop_*`) | Temp files survive child SIGKILL. Shared memory does not. D-07 explicitly requires SIGTERM resilience. |
| `pcntl_alarm` for timeout | Polling loop (`usleep` + elapsed check) | `pcntl_alarm` conflicts with inherited libcurl SIGALRM. Polling is safer and handles batch concurrency more naturally. See Risks section. |
| `declare(ticks=1)` for signal dispatch | `pcntl_async_signals(true)` | Ticks add CPU overhead (PHP manual confirms). Async signals are zero-overhead and recommended for PHP 7.1+. |

## Architecture Patterns

### Recommended Project Structure
```
src/
├── Arbitrator/
│   └── Arbitrator.php          # Main orchestrator class
├── Agent/
│   ├── AgentManager.php        # UNCHANGED -- discovery only
│   └── ResearchAgent.php       # Modified -- add deadline check (D-16)
└── Http/
    └── HttpHelper.php          # Modified -- add CURLOPT_NOSIGNAL
```

### System Architecture Diagram

```
research.php
    |
    | Creates Arbitrator instance
    v
Arbitrator::research(question)
    |
    | 1. Discover agents via AgentManager::getAgentConfigs()
    v
    +-- [Batch 1: up to max_concurrent agents]
    |   |
    |   +-- pcntl_fork() --> Child A
    |   |   |  \ Reset SIGALRM to SIG_DFL
    |   |   |  \ pcntl_alarm(0)
    |   |   |  \ Register SIGTERM handler (sets $timedOut flag)
    |   |   |  \ Create fresh ResearchAgent
    |   |   |  \ Create fresh Logger (or null)
    |   |   |  \ ResearchAgent::research(question, $deadline)
    |   |   |       \ buildToolContext() -- skip if past deadline
    |   |   |       \ llm->chat() -- Layer 2 timeout (60s HTTP)
    |   |   |       \ After each step, check: microtime > deadline?
    |   |   |  \ Write result to /tmp/agent_A_{corrId}.json
    |   |   |  \ exit(0)
    |   |   |
    |   +-- pcntl_fork() --> Child B
    |   |   ...
    |   |
    |   |  Parent: wait loop
    |   |  while (children_running):
    |   |    for each child:
    |   |      $reaped = pcntl_waitpid($pid, $status, WNOHANG)
    |   |      if $reaped > 0: process result, remove from running list
    |   |      if elapsed > deadline:
    |   |        posix_kill($pid, SIGTERM)   -- child sets flag
    |   |        usleep(2000000)             -- 2s grace for partial write
    |   |        if still running: posix_kill($pid, SIGKILL)
    |   |    if children_running: usleep(100000)  -- 100ms poll
    |   |
    +-- [Batch 2: next slot available] ...
    |
    | 2. Collect all results from temp files
    | 3. Return results array matching AgentManager shape (D-04)
    v
research.php displays results (unchanged from Phase 2)
```

### Pattern 1: Fork + Wait with Timeout Enforcement

**What:** Process-level parallel execution with per-child timeout enforcement using polling loop.

**When to use:** When you need OS-level process isolation, independent error handling per agent, and the ability to kill individual hung agents without affecting others.

**Example:**

```php
<?php
declare(strict_types=1);

// In Arbitrator::research():
$children = [];
$results = [];

// Fork children (up to max_concurrent)
foreach (array_slice($agents, 0, $maxConcurrent) as $name => $info) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        // Fork failed - log and continue
        continue;
    }
    if ($pid === 0) {
        // ---- CHILD PROCESS ----
        // Reset inherited signal handlers
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(0);
        
        // Register SIGTERM handler (flag-based, no file I/O)
        $timedOut = false;
        pcntl_signal(SIGTERM, function(int $signo) use (&$timedOut): void {
            $timedOut = true;   // ONLY set flag -- no file I/O
        });
        
        try {
            $agent = new ResearchAgent($info['dir'], $configLoader);
            $result = $agent->research($question, $deadline);
            writeTempFile($correlationId, $name, $result, 'completed');
        } catch (\Throwable $e) {
            if ($timedOut) {
                // SIGTERM was received -- write partial answer
                $partial = ['answer' => '[Partial answer before timeout]', ...];
                writeTempFile($correlationId, $name, $partial, 'partial');
            } else {
                writeTempFile($correlationId, $name, ['error' => $e->getMessage()], 'killed');
            }
        }
        exit(0);
    }
    // ---- PARENT ----
    $children[$pid] = [
        'name' => $name,
        'deadline' => microtime(true) + $agentTimeout,
    ];
}

// Wait loop with timeout enforcement
$endTime = microtime(true) + $globalTimeout;
while (!empty($children) && microtime(true) < $endTime) {
    foreach ($children as $pid => $info) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped === -1 || $reaped > 0) {
            // Child exited or error -- collect result
            if ($reaped > 0) {
                $results[$info['name']] = readTempFile($correlationId, $info['name'], $status);
            }
            unset($children[$pid]);
            continue;
        }
        // Check per-child timeout
        if (microtime(true) > $info['deadline']) {
            posix_kill($pid, SIGTERM);      // Try graceful
            usleep(2000000);                  // 2s grace
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);    // Force kill
                pcntl_waitpid($pid, $status); // Reap
            }
            $results[$info['name']] = readTempFile($correlationId, $info['name'], $status);
            unset($children[$pid]);
        }
    }
    if (!empty($children)) {
        usleep(100000); // 100ms polling interval
    }
}

// Any remaining children after global timeout -- force kill
foreach ($children as $pid => $info) {
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $status);
    $results[$info['name']] = readTempFile($correlationId, $info['name'], $status);
}
```

### Pattern 2: Cooperative Deadline Check in ResearchAgent

**What:** Before each major step, check if the agent has exhausted its time budget. If less than 5 seconds remain, skip remaining work and return partial answer.

**When to use:** Layer 4 timeout enforcement -- catches cases where the agent is about to start a long operation but timeout is imminent. Prevents wasted work and avoids the need for a hard SIGTERM.

**Example:**

```php
<?php
// In ResearchAgent::research():
public function research(string $question, ?float $deadline = null): array
{
    $question = mb_substr($question, 0, 2000);
    
    // Layer 4 check: before tool building
    if ($deadline !== null && microtime(true) + 5 > $deadline) {
        // Skip tool context -- not enough time
        $toolContext = '';
    } else {
        $toolContext = $this->buildToolContext($question);
    }
    
    $messages = [
        ['role' => 'system', 'content' => $this->soul . ($toolContext ? "\n\n" . $toolContext : '')],
        ['role' => 'user', 'content' => $question],
    ];
    
    // Layer 4 check: before LLM call
    if ($deadline !== null && microtime(true) + 5 > $deadline) {
        throw new \RuntimeException('ResearchAgent: deadline reached before LLM call');
    }
    
    // ... proceed with LLM call ...
}
```

### Anti-Patterns to Avoid

- **Doing file I/O in signal handlers:** `file_put_contents` inside a `pcntl_signal` handler is not documented as safe and risks deadlock if the signal interrupts a filesystem operation. Use a flag-based pattern instead.
- **Sharing HttpHelper/Logger instances across fork:** File descriptors are inherited. Both parent and child writing to the same log file handle causes interleaved output. Each child creates its own instances.
- **pcntl_alarm without CURLOPT_NOSIGNAL:** libcurl's internal timeout uses SIGALRM via `alarm()`. If the parent uses `pcntl_alarm` AND curl handles exist in any process, signal conflicts produce spurious curl error 28 ("Timeout was reached").
- **Destroying temp files before parent reads them:** The child process exit might clean up `/tmp` files. Use `sys_get_temp_dir()` (not a subdir that gets cleaned) and keep files until parent explicitly reads and deletes.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Process parallelism | amphp/parallel, ReactPHP child processes | `pcntl_fork` + `pcntl_waitpid` | D-05 locked. Zero-dependency, OS-native, sufficient for v1. Add amphp/parallel only if fork management becomes complex (many agents, complex IPC). |
| IPC between parent/child | Sockets, shared memory, message queues | Temp files (`sys_get_temp_dir()`) | D-07 locked. Temp files survive child SIGKILL, don't require coordination, are trivially debuggable. |
| HTTP timeout enforcement | Custom socket-level timeout | `CURLOPT_TIMEOUT` in HttpHelper | Already implemented in Phase 2. Layer 2 is complete. Only change needed is adding `CURLOPT_NOSIGNAL`. |
| Async signal dispatch | `declare(ticks=1)` | `pcntl_async_signals(true)` | Zero overhead vs tick-based (PHP manual confirms). PHP 7.1+ recommended approach. |

**Key insight:** The combination of `pcntl_fork` + temp file IPC + polling wait loop is a well-established pattern in vanilla PHP CLI applications. It trades elegance for reliability -- the temp file persists even if the child is SIGKILLed. This is the correct tradeoff for an orchestrator that must not block indefinitely.

## Common Pitfalls

### Pitfall 1: `CURLOPT_NOSIGNAL` Not Set (CRITICAL)

**What goes wrong:** After fork, libcurl's internal timeout mechanism uses `SIGALRM`. If the parent uses `pcntl_alarm` OR if any signal handling is set up, curl calls fail with error 28 ("Timeout was reached") sporadically.

**Why it happens:** `CURLOPT_TIMEOUT` in libcurl uses `alarm(2)` internally on Unix systems. The forked child inherits the parent's signal handlers. When curl's internal alarm fires, it conflicts with PHP's signal handling.

**How to avoid:** Always set `CURLOPT_NOSIGNAL => true` in `HttpHelper::buildCurlOptions()`. This tells libcurl to use `poll()`/`select()` for timeout instead of signals.

**Current status:** HttpHelper lines 167-173 do NOT set `CURLOPT_NOSIGNAL`. **Must be added before Phase 3 implementation.**

**Warning signs:** Intermittent curl error 28 during parallel execution that disappears with `CURLOPT_TIMEOUT` removed.

### Pitfall 2: `max_execution_time = 0` in CLI (Layer 1 is Inactive)

**What goes wrong:** PHP's `max_execution_time` defaults to `0` (unlimited) in CLI mode. Layer 1 provides NO timeout protection unless explicitly set.

**Why it happens:** PHP CLI mode disables the time limit by design (long-running scripts). D-13 says "Rely on existing PHP defaults" -- but the default provides nothing.

**How to avoid:** Either set `set_time_limit(300)` in `research.php` as a safety net, or document that Layer 1 is inactive for CLI mode. **Decision needed:** Policy choice.

**Runtime verification:** `ini_get('max_execution_time')` returns `0` on this system.

### Pitfall 3: Signal Handler Inertia After Fork

**What goes wrong:** After `pcntl_fork()`, the child inherits ALL of the parent's signal handlers and pending alarms. If the parent set up `pcntl_alarm(timeout)` or a SIGALRM handler before forking, the child gets them too.

**Why it happens:** Signal handlers, signal masks, and pending signals are inherited across fork (standard POSIX behavior). PHP doesn't reset them.

**How to avoid:** In the child process, immediately after `pcntl_fork()` and before doing any work:
```php
pcntl_signal(SIGALRM, SIG_DFL);  // Reset ALRM handler to default
pcntl_alarm(0);                   // Cancel any pending alarm
```

### Pitfall 4: Temp File Race Conditions

**What goes wrong:** Multiple children might attempt to write to the same temp file (if correlation IDs collide), or a child's temp file might be missing when the parent reads it (child was killed before any write happened).

**Why it happens:** Correlation ID collision (8 hex chars = 4 billion combinations, low but non-zero probability). Child killed by SIGKILL immediately (no time to write).

**How to avoid:**
- Temp file naming: `agent_{sanitized_name}_{correlationId}_{pid}.json` -- the PID makes collision virtually impossible.
- Parent handles missing/empty temp file: D-11 specifies `"[Agent name] timed out -- no partial answer"`.
- Use `flock(LOCK_EX)` on temp file writes in the child to prevent partial writes (handles the case where SIGTERM arrives mid-write).

### Pitfall 5: Zombie Processes

**What goes wrong:** If the parent process doesn't reap children (call `pcntl_waitpid`), terminated children become zombies. Enough zombies exhaust the system's process table.

**Why it happens:** The parent either crashes, forgets to call `pcntl_waitpid`, or the exit notification is lost.

**How to avoid:**
- Always call `pcntl_waitpid()` after `posix_kill(SIGKILL)` -- even the killed child must be reaped.
- Use `WNOHANG` in the main loop for non-blocking reaping.
- Consider a SIGCHLD handler as a safety net (reap any zombie that was missed):

```php
pcntl_signal(SIGCHLD, function(): void {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        // Reap any zombie, discard status
    }
});
```

### Pitfall 6: Logger File Descriptor Conflicts

**What goes wrong:** The parent's Logger creates a file handle. After fork, the child inherits this handle. If both write, output interleaves. When the child exits, its shutdown sequence might flush/close the file descriptor, corrupting parent writes.

**Why it happens:** `file_put_contents` doesn't keep a persistent handle open, but the Logger class's `log()` method calls `fopen`/`fwrite`/`fclose` each time. The risk is low with `file_put_contents`, but the child's log entries write to the same file path, which is fine.

**How to avoid:** Each forked child should either: (a) create its own Logger instance with its own log file, or (b) not log at all (return results via temp file only). Option (b) is simpler and avoids log interleaving.

## Code Examples

### Verified Pattern: Fork-Safe cURL Configuration

```php
// HttpHelper::buildCurlOptions() -- MUST add CURLOPT_NOSIGNAL
// Source: PHP manual curl_setopt note #104597
private function buildCurlOptions(string $method, ?string $payload, array $headers): array
{
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $this->timeout,
        CURLOPT_CONNECTTIMEOUT  => $this->connectTimeout,
        CURLOPT_USERAGENT      => self::USER_AGENT,
        CURLOPT_NOSIGNAL       => true,            // CRITICAL for fork safety
        CURLOPT_HTTPHEADER     => $headers ?: [],
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $payload ?? '';
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }

    return $options;
}
```

### Verified Pattern: Flag-Based SIGTERM Handler in Child

```php
// Source: PHP manual pcntl_signal user notes (set flag, defer work)
// In child process, after fork:

pcntl_async_signals(true);  // Zero-overhead async dispatch (PHP 7.1+)

$timedOut = false;

pcntl_signal(SIGTERM, function(int $signo, mixed $siginfo) use (&$timedOut): void {
    $timedOut = true;       // ONLY set flag -- no I/O, no complex logic
});

// Main logic checks flag at safe points
if ($timedOut) {
    // Main context now writes partial answer -- this is safe
    file_put_contents($tempFile, json_encode([
        'status' => 'partial',
        'answer' => '[Research interrupted by timeout]',
        // ... other fields ...
    ]));
    exit(0);
}
```

### Verified Pattern: Non-Blocking Child Reaping

```php
// Source: PHP manual pcntl_waitpid user notes
// Reap all terminated children without blocking:

while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
    if (pcntl_wifexited($status)) {
        $exitCode = pcntl_wexitstatus($status);
        // Child exited normally with $exitCode
    } elseif (pcntl_wifsignaled($status)) {
        $signal = pcntl_wtermsig($status);
        // Child was killed by signal $signal
    }
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `declare(ticks=1)` | `pcntl_async_signals(true)` | PHP 7.1 (2016) | Zero-overhead async signal dispatch. Use async_signals everywhere. |
| `curl_close($ch)` (explicit) | Handle auto-closes | PHP 8.5 | `curl_close` is deprecated. Handles close when they go out of scope. |
| `curl_multi_remove_handle` + manual close | Just remove handle | PHP 8.5 | Skip `curl_close()` after `curl_multi_remove_handle()`. Already done in HttpHelper::getMulti(). |
| libcurl SIGALRM for timeout | CURLOPT_NOSIGNAL + poll/select | Modern libcurl | NOSIGNAL is strictly better for forked processes. No reason not to set it. |

**Deprecated/outdated:**
- `curl_close()`: Deprecated in PHP 8.5. Omit calls (already done in HttpHelper).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `max_execution_time = 0` in CLI means Layer 1 provides no protection | Timeout Cascade | Low -- Layer 2 and Layer 4 still work. Only affects cases where both HTTP timeout AND cooperative deadline fail. |
| A2 | `CURLOPT_NOSIGNAL` has no side effects on modern libcurl | Fork-Safe cURL | Very low -- NOSIGNAL only disables signal-based timeout in favor of poll/select. Standard practice. |
| A3 | Temp file writes using `file_put_contents` are atomic for reads under 8KB | Temp File IPC | Low -- `file_put_contents` with LOCK_EX in the child prevents partial reads, but only if parent respects the lock. Could use a `.tmp` rename pattern instead. |

## Open Questions

1. **Should `CURLOPT_NOSIGNAL` be set unconditionally in HttpHelper, or conditionally when child process is detected?**
   - What we know: NOSIGNAL has no downside -- it just disables signal-based timeout in favor of poll/select.
   - What's unclear: Whether setting it unconditionally changes curl behavior in non-forked contexts.
   - Recommendation: Set it unconditionally. The poll/select timeout is strictly equivalent to signal-based timeout on modern systems. Verified via PHP manual: NOSIGNAL is safe and recommended for threaded/forked environments.

2. **Should Layer 1 (PHP max_execution_time) be explicitly set for CLI mode, or left at 0/unlimited?**
   - What we know: CLI default is 0 (unlimited). D-13 says "Rely on existing PHP defaults."
   - What's unclear: Whether "existing PHP defaults" means the actual 0 value (which provides no protection) or was assumed to be 30s.
   - Recommendation: Discuss with user. Either set `set_time_limit(300)` for a safety net, or document Layer 1 as inactive. This is a policy decision, not a technical one.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `pcntl_fork` | Phase 3 core | Yes | PHP 8.5.4 | If pcntl unavailable, fall back to sequential execution (log warning) |
| `pcntl_waitpid` | Phase 3 core | Yes | PHP 8.5.4 | -- |
| `pcntl_signal` | Phase 3 core | Yes | PHP 8.5.4 | -- |
| `pcntl_async_signals` | Phase 3 core | Yes | PHP 8.5.4 | -- |
| `posix_kill` | Phase 3 core | Yes | PHP 8.5.4 | -- |
| `sys_get_temp_dir` | Phase 3 core | Yes | -- | `fallback: /tmp` |
| ext-curl | Phase 3 (existing) | Yes | -- | -- |
| Logs dir writable | Phase 3 (existing) | Yes | -- | -- |

**Missing dependencies with no fallback:** None -- all required extensions are available.

**Missing dependencies with fallback:**
- `pcntl` functions: If disabled in php.ini (currently not), Arbitrator falls back to sequential execution using AgentManager's existing loop. Log warning and continue.

## Validation Architecture

> nyquist_validation is enabled (workflow.nyquist_validation: true in config.json)

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit ^12.0 (dev dependency via composer.json) |
| Config file | phpunit.xml.dist (Phase 2 setup) |
| Quick run command | `php vendor/bin/phpunit tests/Arbitrator/ --no-coverage` |
| Full suite command | `php vendor/bin/phpunit --no-coverage` |

### Phase Requirements Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ORCH-01 | Arbitrator discovers agents via AgentManager::getAgentConfigs() | unit | `phpunit tests/Arbitrator/ArbitratorTest.php::testDiscoversAgents` | ❌ Wave 0 |
| ORCH-02 | Arbitrator distributes question to all agents | integration | `phpunit tests/Arbitrator/ArbitratorTest.php::testDistributesToAllAgents` | ❌ Wave 0 |
| ORCH-03 | Timeout enforcement produces partial answer | integration | `phpunit tests/Arbitrator/ArbitratorTest.php::testTimeoutProducesPartialAnswer` | ❌ Wave 0 |
| ORCH-04 | Arbitrator collects and returns Round 1 answers | integration | `phpunit tests/Arbitrator/ArbitratorTest.php::testCollectsRound1Answers` | ❌ Wave 0 |
| ORCH-10 | 4-layer timeout: L2 (HttpHelper) prevents hang | unit (existing) | `phpunit tests/Http/HttpHelperTest.php::testTimeout` | ❌ Wave 0 |
| ORCH-10 | 4-layer timeout: L4 (cooperative deadline) skips steps | unit | `phpunit tests/Agent/ResearchAgentTest.php::testDeadlineCheck` | ❌ Wave 0 |
| ORCH-10 | CURLOPT_NOSIGNAL is set in forked context | unit | `phpunit tests/Http/HttpHelperTest.php::testForkSafeCurlOptions` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php vendor/bin/phpunit tests/Arbitrator/ --no-coverage`
- **Per wave merge:** `php vendor/bin/phpunit --no-coverage`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Arbitrator/ArbitratorTest.php` -- covers ORCH-01 through ORCH-04
- [ ] `tests/Arbitrator/ArbitratorTest.php` -- test for temp file IPC (read/write/cleanup)
- [ ] `tests/Arbitrator/ArbitratorTest.php` -- test for max concurrent agent batching
- [ ] `tests/Agent/ResearchAgentTest.php` -- test for deadline check behavior
- [ ] `tests/Http/HttpHelperTest.php` -- test for CURLOPT_NOSIGNAL option presence

## Security Domain

> security_enforcement is enabled (workflow.security_enforcement: true in config.json)

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V5 Input Validation | Yes | Question sanitization (ResearchAgent caps at 2000 chars) |
| V8 Data Protection | Yes | Temp files in sys_get_temp_dir() -- clean up after reading |
| V9 Communication | Yes | API keys passed via env/config in child process -- inherited from parent after fork |
| V10 Malicious Code | Partial | Fork safety: temp file naming prevents injection. PID + correlationId + agentName sanitized. |

### Known Threat Patterns for pcntl_fork + IPC

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Temp file injection via agent name | Tampering | Sanitize agent name in temp file path: replace non-alphanumeric chars with `_`. Agent names come from config files (not user input). |
| Temp file race (multiple readers/writers) | Tampering | Use `LOCK_EX` on write in child. Parent uses shared read (no lock needed for read-only). |
| Fork bomb (runaway fork loop) | DoS | Cap at `maxConcurrent` (default 5). Fork parent tracks count -- never forks more than limit. |
| Signal injection via misdelivered SIGTERM | Spoofing | Child validates signal context. SIGTERM handler sets flag only. Parent does NOT rely on child-side validation. |

## Sources

### Primary (HIGH confidence)
- [PHP Manual: pcntl_fork](https://www.php.net/manual/en/function.pcntl-fork.php) -- Fork semantics, return values, fd inheritance, zombie prevention. Verified via runtime.
- [PHP Manual: pcntl_signal](https://www.php.net/manual/en/function.pcntl-signal.php) -- Signal handler semantics, dispatch methods, async_signals vs ticks. Verified.
- [PHP Manual: pcntl_waitpid](https://www.php.net/manual/en/function.pcntl-waitpid.php) -- WNOHANG, exit status check functions. Verified.
- [PHP Manual: pcntl_wifexited](https://www.php.net/manual/en/function.pcntl-wifexited.php) -- Exit status check helpers. Verified.
- [PHP Manual: posix_kill](https://www.php.net/manual/en/function.posix-kill.php) -- Signal sending, SIGTERM vs SIGKILL. Verified.
- [PHP Manual: curl_setopt](https://www.php.net/manual/en/function.curl-setopt.php) -- CURLOPT_NOSIGNAL critical for fork safety. Verified via note #104597.
- [PHP Manual: pcntl_alarm](https://www.php.net/manual/en/function.pcntl-alarm.php) -- Alarm signal semantics. Verified.
- Runtime check: PHP 8.5.4 with pcntl, posix, curl, json extensions. Verified.

### Secondary (MEDIUM confidence)
- WebSearch verification of pcntl_fork patterns in PHP 8.x. All findings consistent with manual.

### Tertiary (LOW confidence)
- None -- all critical claims verified via PHP manual or runtime checks.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH -- all functions verified via runtime existence check and PHP manual.
- Architecture: HIGH -- fork/wait/reap pattern is well-established PHP CLI practice. Verified against manual.
- Pitfalls: HIGH -- CURLOPT_NOSIGNAL (curl manual), signal handler safety (pcntl_signal manual), fork fd inheritance (pcntl_fork manual) all confirmed via primary sources.
- 4-layer timeout: MEDIUM -- L1 policy decision (0 default in CLI) and L4 implementation detail need user confirmation. L2 is already deployed and verified.

**Research date:** 2026-06-13
**Valid until:** Next PHP major version release (PHP 9) or pcntl API changes.
