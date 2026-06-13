---
status: testing
phase: 01-foundation-single-agent-baseline
source: [01-VERIFICATION.md]
started: 2026-06-13T12:02:00Z
updated: 2026-06-13T12:02:00Z
---

## Current Test

number: 1
name: Authenticated API test
expected: |
  With a valid API key configured in config/agents/researcher/config.json,
  `php research.php "What is the capital of France?"` returns a formatted
  answer with metadata line (model, time, tokens, correlation ID).
awaiting: user response

## Tests

### 1. Authenticated API test
expected: With valid API key, `php research.php "What is the capital of France?"` returns answer + metadata
result: [pending]

### 2. Visual formatting review
expected: Output is readable, metadata line is clear, color/formatting works well
result: [pending]

### 3. Deliberate config failure test
expected: Break config, run `php bin/check-config`, verify [FAIL] output and non-zero exit code
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
