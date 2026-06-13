# Phase 1: Foundation & Single-Agent Baseline - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-13
**Phase:** 1-Foundation & Single-Agent Baseline
**Areas discussed:** Config file format, LLM API client, CLI entry point, SOUL.md format, Logging format

---

## Config File Format

| Option | Description | Selected |
|--------|-------------|----------|
| PHP arrays | config.php returns array — include, zero parsing, type-safe, IDE autocomplete | |
| JSON | config.json — universal, readable, needs json_decode | ✓ |
| Both | PHP arrays for app config, JSON for external/tool configs | |

**User's choice:** JSON
**Notes:** JSON preferred for its universality and readability.

---

## LLM API Client

| Option | Description | Selected |
|--------|-------------|----------|
| Direct curl per provider | Separate handler for each provider | |
| Abstracted client | Single LlmClient with provider adapters | ✓ |
| Minimal abstraction | Single client, provider differences via config | |

**User's choice:** Abstracted client
**Notes:** Provider differences handled via adapters behind a common LlmClient interface.

---

## CLI Entry Point

| Option | Description | Selected |
|--------|-------------|----------|
| php bin/research | Structured single command | |
| php research.php | Flat script at root | ✓ |
| Interactive only | php bin/research starts REPL | |

**User's choice:** php research.php
**Notes:** Simple flat script for v1. Interactive REPL deferred to Phase 5.

---

## SOUL.md Format

| Option | Description | Selected |
|--------|-------------|----------|
| Freeform system prompt | Plain text markdown persona | |
| Structured sections | Identity, Values, Instructions, Constraints | ✓ |
| Hybrid | Frontmatter + freeform body | |

**User's choice:** Structured sections
**Notes:** Sections: ### Identity, ### Values, ### Instructions, ### Constraints.

---

## Logging Format

| Option | Description | Selected |
|--------|-------------|----------|
| JSONL | Machine-parseable JSON lines | |
| Human-readable | Formatted text log | ✓ |
| Dual | JSONL files + human-readable console | |

**User's choice:** Human-readable
**Notes:** Human-readable formatted text with channel prefix.

---

## Claude's Discretion

- Directory structure beyond config layout
- Error handling specifics
- Exact config schema fields beyond provider/model/key

## Deferred Ideas

None.
