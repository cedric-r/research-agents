# ResearchAgents

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

Multi-agent research and debate system. Distributes questions to AI agents with diverse models/personalities, runs a two-round debate (independent answers → peer critique → judge selection), and surfaces the best result with full traceability.

**Zero framework. Vanilla PHP. CLI + web.**

## Quick Start

```bash
# Install dependencies
composer install

# Run a one-shot research question
php research.php "What are the latest advances in transformer architectures?"

# Start interactive CLI REPL
php repl.php

# Start web REPL (browser at http://localhost:8080)
php -S localhost:8080 -t public/
```

## Requirements

- PHP 8.3+ (tested on 8.5.4)
- Extensions: `curl`, `readline`, `json`, `mbstring`, `pcntl`, `posix`, `sockets`
- API keys per agent (stored in `config/agents/{name}/config.json`)

## Architecture

```
research.php          — One-shot CLI entry point
repl.php              — Interactive CLI REPL (readline loop)
public/index.php      — Web REPL front controller (php -S)
src/
├── Agent/            — ResearchAgent, AgentManager
├── Arbitrator/       — Orchestrator (pcntl_fork), scoring, debate, DiversityAnalyzer
├── Config/           — JSON config loader with validation
├── Http/             — curl HTTP helper
├── LlmClient/        — LLM API abstraction (OpenAI-compatible)
├── Log/              — Structured file logger with correlation IDs
├── Output/           — ANSI formatting utilities
├── Session/          — Session persistence (Manager, ProgressLogger)
├── Tool/             — Tool registry, WebSearch (Brave API), AcademicSearch (arXiv/Semantic Scholar)
└── bootstrap.php     — PSR-4 autoloader
config/
└── agents/           — Per-agent config.json, preferences.json, SOUL.md
    ├── researcher/
    ├── researcher-deepseek/
    ├── researcher-groq/
    └── researcher-openrouter-free/
sessions/             — Auto-saved markdown transcripts per research run
logs/                 — Timestamped activity logs
```

## Usage

### CLI: `php research.php "question"`

Runs full research pipeline, prints score table + winner + judge reasoning, auto-saves session to `sessions/`.

### CLI REPL: `php repl.php`

Interactive shell with commands:

| Command | Description |
|---------|-------------|
| Type a question | Run research with progress display |
| `/help` | Show command list |
| `/replay <slug>` | Replay a past session transcript |
| `/config` | Show arbitrator configuration |
| `/agents` | List configured agents |
| `/last` | Re-render most recent result |
| `/history` | Show past questions |
| `/clear` | Clear screen |
| `/exit` | Exit |

### Web REPL: `php -S localhost:8080 -t public/`

- `GET /` — Submit question form
- `POST /api/ask` — Executes research in background, redirects to session page
- `GET /session/{id}` — Live progress via SSE, summary on completion
- `GET /session/{id}/stream` — SSE endpoint
- `GET /sessions` — Past sessions list

## Configuring Agents

Each agent is a directory under `config/agents/{name}/`:

```
config/agents/my-agent/
├── config.json       # Provider, model, API key, Brave API key
├── preferences.json  # Tool access flags
└── SOUL.md           # Personality prompt
```

**Example `config.json`:**

```json
{
    "provider": "openrouter",
    "model": "openrouter/free",
    "api_key": "sk-or-v1-...",
    "brave_api_key": "BSA-..."
}
```

Supported providers: `deepseek`, `openrouter`, `groq` (with `provider_base_url`).

## Research Pipeline

1. **Round 1** — All agents research independently in parallel (pcntl_fork)
2. **Quality scoring** — LLM judge scores each answer (relevance, completeness, clarity, etc.)
3. **Diversity analysis** — N-gram similarity check, bonus for unique perspectives
4. **Round 2 (critique)** — Agents see anonymized peer answers and produce structured critiques
5. **Selection** — Weighted formula (quality + critique + diversity) narrows to top candidates, LLM judge picks winner

## Session Storage

Each research run creates `sessions/YYYY-MM-DD_question-slug/`:

- `session.md` — Full transcript with frontmatter, agent answers, debate, scores
- `session.log` — JSON-line progress events (SSE source for web UI)

## Testing

```bash
php vendor/bin/phpunit
```

64 tests covering agents, arbitrator, config, tools, session persistence, progress logging.

## Phase Status

| Phase | Status | Description |
|-------|--------|-------------|
| 01 | ✅ | Foundation: config, LLM client, logging, SOUL.md |
| 02 | ✅ | Agent runtime: tool registry, web search, academic search |
| 03 | ✅ | Orchestration: pcntl_fork parallelism, timeout architecture |
| 04 | ✅ | Debate: scoring, critique, diversity bonus, judge selection |
| 05 | ✅ | Storage & Presentation: session persistence, CLI REPL, web REPL |

## License

GNU General Public License v3.0. See [LICENSE](LICENSE).
