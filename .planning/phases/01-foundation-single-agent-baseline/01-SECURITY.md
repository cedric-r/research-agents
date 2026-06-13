# SECURITY.md

**Phase:** 01 -- Foundation (Single-Agent Baseline)
**ASVS Level:** 1
**Audit Date:** 2026-06-13

## Threat Verification

All 11 declared threats from the PLAN.md threat registers (01-01, 01-02, 01-03) were verified against the implemented code.

| Status | Count |
|--------|-------|
| CLOSED | 11 |
| OPEN   | 0 |

---

### T-01-01: Information Disclosure -- config/agents/*/config.json

| Field | Value |
|-------|-------|
| Category | Information Disclosure |
| Component | config/agents/*/config.json |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `.gitignore` line 2: `config/agents/*/config.json` (also `config/arbitrator/config.json` on line 3). `SKELETON.md` section "Configuration Security" (lines 68-70) documents file permission requirement: `chmod 600 config/agents/*/config.json`. |
| Files | `.gitignore`, `SKELETON.md` |

---

### T-01-02: Tampering -- Config files

| Field | Value |
|-------|-------|
| Category | Tampering |
| Component | config files |
| Disposition | accept |
| Status | CLOSED |
| Evidence | Accepted risk documented here: config files are local-only (no remote write surface). Content loaded via `json_decode` or closure-scoped `include` from known hardcoded paths only. Attackers require local filesystem access, which implies broader compromise. |
| Files | `src/Config/Loader.php` |

---

### T-01-03: Information Disclosure -- Config Loader error messages

| Field | Value |
|-------|-------|
| Category | Information Disclosure |
| Component | Config Loader error messages |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/Config/Loader.php`: error messages reference field names only (e.g., `Missing required field: 'api_key'`). Comment at lines 98-99 explicitly states: "Never returns the decoded config values in error messages to prevent leaking sensitive fields (e.g., api_key)." No exception message contains decoded config values. |
| Files | `src/Config/Loader.php` lines 98-99, 107-119 |

---

### T-01-04: Tampering -- PHP array include

| Field | Value |
|-------|-------|
| Category | Tampering |
| Component | PHP array include |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/Config/Loader.php` lines 80-93 (`loadPhpArray`): uses closure-scoped include `(function () use ($path): mixed { return include $path; })()` to prevent variable leakage. File paths are hardcoded in calling code (`research.php` line 42), not user-supplied. Only `.php` files accepted (line 37-38). |
| Files | `src/Config/Loader.php` lines 80-93, `research.php` line 42 |

---

### T-01-05: Tampering -- Log file injection

| Field | Value |
|-------|-------|
| Category | Tampering |
| Component | Log file injection |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/Log/Logger.php` line 35: `preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x0A\x0D]/', '', $message)` strips control characters from log messages before writing. |
| Files | `src/Log/Logger.php` line 35 |

---

### T-01-06: Spoofing -- json_decode null return

| Field | Value |
|-------|-------|
| Category | Spoofing |
| Component | json_decode null return |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `JSON_THROW_ON_ERROR` flag used on all json_decode/json_encode calls: `src/Config/Loader.php` line 58, `src/LlmClient/LlmClient.php` lines 40 and 91. No use of `json_last_error()` surface. |
| Files | `src/Config/Loader.php` line 58, `src/LlmClient/LlmClient.php` lines 40, 91 |

---

### T-01-07: Information Disclosure -- curl error messages

| Field | Value |
|-------|-------|
| Category | Information Disclosure |
| Component | curl error messages |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/LlmClient/LlmClient.php` line 76-77: error message includes only curl errno and error string (no request body or Authorization header). Lines 82-85: API response truncated to 500 characters via `mb_substr($response, 0, 500)` for HTTP error messages. |
| Files | `src/LlmClient/LlmClient.php` lines 76-77, 82-85 |

---

### T-01-08: Denial of Service -- LLM API timeout

| Field | Value |
|-------|-------|
| Category | Denial of Service |
| Component | LLM API timeout |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/LlmClient/LlmClient.php` line 58: `CURLOPT_TIMEOUT => $options['timeout'] ?? 60` (default 60s). Line 59: `CURLOPT_CONNECTTIMEOUT => 10` (10s) enforced on all HTTP calls. Line 74: `if ($response === false)` check before any processing ensures timeout failures are caught. |
| Files | `src/LlmClient/LlmClient.php` lines 58-59, 74 |

---

### T-01-09: Elevation of Privilege -- CLI input injection

| Field | Value |
|-------|-------|
| Category | Elevation of Privilege |
| Component | CLI input injection |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/Agent/ResearchAgent.php` line 80: `$question = mb_substr($question, 0, 2000)` -- capped at 2000 characters. Line 89-91: question sent as `['role' => 'user', 'content' => $question]` -- passed only to LLM as message content. Never passed to shell, eval, or include. |
| Files | `src/Agent/ResearchAgent.php` lines 80, 89-91 |

---

### T-01-10: Spoofing -- curl_exec false masking

| Field | Value |
|-------|-------|
| Category | Spoofing |
| Component | curl_exec false masking |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | `src/LlmClient/LlmClient.php` line 74: strict `if ($response === false)` check BEFORE json_decode on line 91. Comment on line 73 documents Pitfall 3: "curl_exec returns false, not empty string, on network failure." |
| Files | `src/LlmClient/LlmClient.php` lines 73-74, 91 |

---

### T-01-11: Information Disclosure -- Question in log output

| Field | Value |
|-------|-------|
| Category | Information Disclosure |
| Component | Question in log output |
| Disposition | mitigate |
| Status | CLOSED |
| Evidence | Two locations truncate the question to 200 chars before logging: `src/Agent/ResearchAgent.php` line 84: `'question' => mb_substr($question, 0, 200)` and `research.php` line 36: `'question' => mb_substr($question, 0, 200)`. |
| Files | `src/Agent/ResearchAgent.php` line 84, `research.php` line 36 |

---

## Accepted Risks Log

| Risk ID | Description | Rationale | Date Accepted |
|---------|-------------|-----------|---------------|
| T-01-02 | Config file tampering -- attacker modifies local config files to change API endpoint or agent behavior | Config files are local-only. No remote write access. Content loaded via `json_decode`/closure-scoped `include` from known hardcoded paths only. Attack requires local filesystem access (broader system compromise). Acceptable for Phase 1 single-user CLI. | 2026-06-13 |

---

## Unregistered Flags

None. No SUMMARY.md `## Threat Flags` sections contained unregistered threats. 01-01-SUMMARY.md reports "None" for threat flags. 01-02-SUMMARY.md and 01-03-SUMMARY.md do not contain a `## Threat Flags` section.
