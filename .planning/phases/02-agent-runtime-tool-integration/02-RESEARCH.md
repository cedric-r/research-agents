# Phase 2: Agent Runtime & Tool Integration - Research

**Researched:** 2026-06-13
**Domain:** Agent management, HTTP tool integration, API abstractions, timeout enforcement
**Confidence:** HIGH

## Summary

Phase 2 extends the single-agent baseline with three major capabilities: (1) AgentManager for multi-agent discovery and lifecycle management, (2) ToolRegistry pattern for web search and academic paper search tools, and (3) centralized HttpHelper for HTTP timeout enforcement. All components follow the project's zero-external-dependency constraint (vanilla PHP with ext-curl, ext-json, SimpleXMLElement).

The key architectural insight is that all three external APIs (Brave Search, arXiv, Semantic Scholar) use simple REST patterns — GET requests with query parameters, JSON or Atom XML responses — making them well-suited to curl-based implementation without any Composer packages. The ToolRegistry pattern provides a clean extension point for future tools while keeping tool logic fully encapsulated.

**Primary recommendation:** Implement in three plans: (1) HttpHelper + ToolRegistry infrastructure, (2) WebSearch + AcademicSearch tools, (3) AgentManager + LlmClient provider extension + integration wiring.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** AgentManager discovers agents by scanning `config/agents/*/` for config.json at startup. No explicit registration list.
- **D-02:** AgentManager creates a fresh ResearchAgent instance per `research()` call. Clean state per query.
- **D-03:** AgentManager returns array of agent answers — same structure as ResearchAgent output, wrapped per agent.
- **D-04:** AgentManager lives in `src/Agent/` namespace alongside ResearchAgent.
- **D-05:** Brave Search API as web search provider. API key configured per-agent in config.json.
- **D-06:** Tool results injected as context block in system prompt before user message, not returned as structured data to agent.
- **D-07:** ToolRegistry pattern — tools registered by name + handler + schema. ResearchAgent calls `run_tool(name, params)`.
- **D-08:** ToolRegistry and all tool classes live in `src/Tool/` namespace (new directory).
- **D-09:** Academic search is one combined AcademicSearch tool querying both arXiv (Atom XML via SimpleXMLElement) and Semantic Scholar (REST JSON). Returns merged, deduplicated results.
- **D-10:** Full detail results — title, authors, abstract (~300 chars truncated), URL, published date, DOI/citation count when available.
- **D-11:** Config-driven provider switching per D-04 pattern. Single LlmClient class reads provider + model from agent config per request. Provider-specific behavior via config (base URL, auth header format, model name).
- **D-12:** Centralized HttpHelper utility for all external HTTP calls (LLM API, Brave Search, arXiv, Semantic Scholar). Enforces CURLOPT_TIMEOUT=60s, CURLOPT_CONNECTTIMEOUT=10s. Single config point for all timeouts.

### Claude's Discretion
- Exact tool result formatting in context block — planner designs schema
- AgentManager constructor signature and ResearchAgent instantiation details
- HttpHelper class name and internal curl_multi_exec support (future-proofing for Phase 3 parallel agents)
- Error handling specifics per tool (retry, fallback, partial failure)

### Deferred Ideas (OUT OF SCOPE)
- None — discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CONF-08 | AgentManager discovers agents by scanning config directories at runtime | AgentManager pattern documented — scans `config/agents/*/config.json`, creates ResearchAgent instances per `research()`, returns wrapped results |
| TOOL-02 | Agent can perform web searches via configurable search API provider | Brave Search API documented — endpoint, auth, response format. WebSearch tool with provider abstraction via config |
| TOOL-03 | Agent can search arXiv API for scientific papers | arXiv API documented — Atom XML via SimpleXMLElement with namespace handling, field extraction pattern |
| TOOL-04 | Agent can search Semantic Scholar API for scientific papers and citations | Semantic Scholar API documented — REST JSON, field selection, rate limits. Combined in AcademicSearch per D-09 |
| TOOL-05 | LlmClient abstracts LLM API calls with model/provider selection | Existing LlmClient extended: `provider_base_url`, `provider_model_name` fields in config, per-request provider config loaded from agent config |
| TOOL-06 | WebSearch tool has provider abstraction for swapping search APIs | WebSearch class accepts provider config (type, base_url, api_key). Future providers added via config + handler method |
| TOOL-07 | PaperSearch tool wraps arXiv and Semantic Scholar endpoints | AcademicSearch class calls both APIs, merges results, deduplicates by arXiv ID/DOI. Uses SimpleXMLElement for arXiv, json_decode for Semantic Scholar |
| TOOL-08 | API response timeouts enforced at HTTP socket level | HttpHelper centralizes CURLOPT_TIMEOUT=60s, CURLOPT_CONNECTTIMEOUT=10s. All external HTTP goes through this single utility |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Agent discovery & lifecycle | Backend (AgentManager) | — | Manages agent instantiation lifecycle in PHP process. No frontend involvement. |
| Web search | Backend (WebSearch tool) | — | Direct HTTP call to Brave Search API. Result fed into LLM context. |
| Academic paper search | Backend (AcademicSearch tool) | — | Calls arXiv + Semantic Scholar directly. No intermediate server needed. |
| LLM API abstraction | Backend (LlmClient) | — | Curl-based HTTP to LLM providers. |
| HTTP timeout enforcement | Backend (HttpHelper) | — | Socket-level timeout on all outbound HTTP from PHP. |
| Tool orchestration | Backend (ToolRegistry + ResearchAgent) | — | Tools registered and called within agent's PHP runtime. |
| Tool result formatting | Backend (tool classes) | — | Tools return formatted string blocks ready for prompt injection. |

## Standard Stack

### Core
| Component | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| HttpHelper | v1 (new) | Centralized HTTP utility enforcing timeouts on all external calls | Zero-dependency curl wrapper. Single config point for CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT across all APIs. curl_multi_exec ready for Phase 3. |
| ToolRegistry | v1 (new) | Tool registration and dispatch by name + params | Extension-point pattern. ResearchAgent calls `run_tool(name, params)` — new tools added via register(), not inheritance. |
| AgentManager | v1 (new) | Agent discovery, lifecycle, and result collection | Scans `config/agents/*/config.json`. Creates fresh ResearchAgent per research(). Returns array of agent answers. |
| WebSearch tool | v1 (new) | Brave Search API web search | Config-driven provider abstraction. Provider type, API key, base URL all from agent config. Simple GET + JSON parse. |
| AcademicSearch tool | v1 (new) | Combined arXiv + Semantic Scholar paper search | One tool calls both APIs. arXiv via SimpleXMLElement (Atom XML). Semantic Scholar via curl + json_decode. Merged, deduplicated results. |
| LlmClient (extended) | v1 (existing + changes) | Config-driven provider switching | Extended to read `provider_base_url` and `provider_model_name` from agent config per request, enabling per-agent provider config. |

### Supporting
| Component | Version | Purpose | When to Use |
|-----------|---------|---------|-------------|
| SimpleXMLElement | PHP built-in | Parse arXiv Atom XML responses | arXiv only — other APIs return JSON |
| ext-curl | PHP built-in | HTTP transport for all API calls | Every external call goes through curl |
| ext-json | PHP built-in | JSON encode/decode for all API payloads | All APIs except arXiv |
| ext-mbstring | PHP built-in | Multi-byte string handling | Truncation of abstracts, result formatting |

### Alternatives Considered
| Recommended | Alternative | Tradeoff |
|-------------|-------------|----------|
| Single WebSearch class with provider config | Provider interface + implementations (IBraveSearch, IGoogleSearch, etc.) | Over-engineered for v1 with one provider. Config-driven switch adds five lines; interface hierarchy adds three files. Add interface when second provider is added. |
| ToolRegistry + tools in same `src/Tool/` | Tools as separate PHP files, no registry | Registry provides consistent `run_tool(name, params)` dispatch. Without it, agent must know tool class names. |
| SimpleXMLElement for arXiv | XMLReader, DOMDocument | SimpleXMLElement is the most concise for the expected response size (<50KB). DOMDocument for larger docs; XMLReader for streaming. |
| curl_multi_exec pattern in HttpHelper (future-proofed) | Sequential curl_exec calls | curl_multi provides foundation for Phase 3 parallel execution with no refactoring needed when parallel agents arrive. Sequential is simpler but requires rewriting later. |

**Installation:**
No external packages. All dependencies are PHP built-in extensions:
- `ext-curl` (HTTP transport)
- `ext-json` (JSON parsing)
- `ext-mbstring` (string handling)
- `ext-SimpleXML` (Atom XML parsing for arXiv)
- `ext-readline` (already loaded for Phase 5 CLI)

**Version verification:** All dependencies are PHP built-in extensions. Verify availability:
```bash
php -m | grep -E '^(curl|json|mbstring|SimpleXML|readline)$'
```
Expected output (all five present):
```
curl
json
mbstring
SimpleXML
readline
```
Verified on runtime PHP 8.5.4. [VERIFIED: PHP runtime]

## Package Legitimacy Audit

> No external packages are installed in this phase. All tooling uses PHP built-in extensions (curl, json, mbstring, SimpleXML, readline) confirmed available on the runtime system. No npm/PyPI/crates packages required.

**Packages removed due to slopcheck [SLOP] verdict:** N/A
**Packages flagged as suspicious [SUS]:** N/A

## Architecture Patterns

### System Architecture Diagram

```
                    +------------------+
                    |   AgentManager   |
                    | (src/Agent/)     |
                    +--------+---------+
                             |
              discovers via  |  scans config/agents/*/config.json
                             |
                    +--------v---------+
                    | ResearchAgent    |
                    | (existing,       |
                    |  extended)       |
                    +--------+---------+
                             |
                    uses     |  ToolRegistry::run_tool()
                             |
                    +--------v---------+
                    |   ToolRegistry   |
                    | (src/Tool/)      |
                    +--------+---------+
                             |
               registers    |
         +------------------+------------------+
         |                  |                  |
+--------v--------+  +------v--------+  +------v--------+
|   WebSearch     |  | AcademicSearch|  |  (Future)     |
| (Brave Search)  |  | arXiv+Semantic|  |  Tool N       |
| src/Tool/       |  | Scholar       |  |               |
+--------+--------+  +-------+-------+  +---------------+
         |                    |
         | HTTP via           | HTTP via
         | HttpHelper         | HttpHelper (curl)
         |                    |
+--------v--------+  +-------v--------+  +---------------+
|  Brave Search   |  |  arXiv API     |  |  Semantic     |
|  API (JSON)     |  |  (Atom XML)    |  |  Scholar API  |
|                 |  |  SimpleXMLElem |  |  (JSON)       |
+-----------------+  +----------------+  +---------------+
                              |
                    +---------v----------+
                    |   HttpHelper       |
                    | (src/HttpHelper    |
                    |  or src/Util/      |
                    | Claude's discretion)|
                    +---------+----------+
                              |
                    curl_exec / curl_multi_exec
                    CURLOPT_TIMEOUT=60
                    CURLOPT_CONNECTTIMEOUT=10
```

**Data flow for a research call with tools:**

1. AgentManager discovers agents (scans config/agents/*/)
2. For each agent: creates ResearchAgent, sets up ToolRegistry
3. ResearchAgent builds system prompt: SOUL.md + tool results context block
4. Agent calls ToolRegistry::run_tool('web_search', params) or ('paper_search', params)
5. Tools use HttpHelper to call external APIs (curl with enforced timeouts)
6. WebSearch returns formatted result string
7. AcademicSearch calls both APIs, merges results, deduplicates, returns formatted string
8. Tool results injected as context block at start of system prompt
9. ResearchAgent sends messages to LlmClient, gets answer
10. AgentManager collects all agent answers, returns array

### Recommended Project Structure
```
src/
├── Agent/
│   ├── ResearchAgent.php     (extended — tool integration)
│   └── AgentManager.php      (NEW — discovery + lifecycle)
├── Tool/
│   ├── ToolRegistry.php      (NEW — tool registration + dispatch)
│   ├── ToolInterface.php     (NEW — optional interface for tool contracts)
│   ├── WebSearch.php         (NEW — Brave Search API)
│   └── AcademicSearch.php    (NEW — arXiv + Semantic Scholar)
├── Http/
│   └── HttpHelper.php        (NEW or under Util/ — Claude's discretion)
├── LlmClient/
│   ├── LlmClient.php         (extended — per-request provider config)
│   └── LlmException.php
├── Config/
│   ├── Loader.php
│   └── ConfigException.php
├── Log/
│   └── Logger.php
└── bootstrap.php
config/
├── agents/
│   └── {agent_name}/
│       ├── config.json       (extended — added provider_base_url, provider_model_name)
│       ├── preferences.json  (existing — tool access flags)
│       └── SOUL.md
└── arbitrator/
    └── config.json
```

### Pattern 1: ToolRegistry — Registration and Dispatch

**What:** Central registry where tools are registered by name with a callable handler and a JSON schema describing accepted parameters. ResearchAgent calls `run_tool('web_search', ['q' => 'query'])` without knowing the tool class.

**When to use:** Any time the agent needs to invoke external capabilities (search, computation, data access) by name. Enables tool access control via preferences.json.

**Example:**
```php
<?php
// Source: Derived from OpenAI function calling tool pattern, adapted for vanilla PHP

// Registration
$registry = new ToolRegistry();
$registry->register('web_search', [
    'handler' => fn(array $params) => (new WebSearch($httpHelper, $config))->execute($params),
    'schema'  => [
        'name'        => 'web_search',
        'description' => 'Search the web for current information',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Search query'],
            ],
            'required'   => ['q'],
        ],
    ],
]);

// Dispatch in ResearchAgent
public function runTool(string $name, array $params): string
{
    return $this->toolRegistry->run($name, $params);
}
```

### Pattern 2: HttpHelper — Centralized HTTP with Timeout Enforcement

**What:** A single utility class that wraps curl_exec with standardized timeout values. All external HTTP calls (LLM, Brave Search, arXiv, Semantic Scholar) go through this class.

**When to use:** Every external HTTP call in the system. Prevents timeout configuration drift across tools.

**Example:**
```php
<?php
// Source: Derived from existing LlmClient curl pattern, centralized for all tools

class HttpHelper
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 60, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Execute a GET request and return response body.
     *
     * @param  string   $url     Fully qualified URL
     * @param  array    $headers Optional HTTP headers
     * @return array{body: string, http_code: int}
     * @throws HttpException On network failure (curl_errno)
     */
    public function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT      => 'ResearchAgents/1.0',
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new HttpException("HTTP request failed (errno {$errno}): {$error}");
        }

        return ['body' => $body, 'http_code' => $httpCode];
    }

    // future: getMulti(array $urls) using curl_multi_exec for Phase 3
}
```

### Pattern 3: AcademicSearch — Combined arXiv + Semantic Scholar

**What:** Single tool class that queries both arXiv (Atom XML via SimpleXMLElement) and Semantic Scholar (REST JSON), then merges and deduplicates results.

**When to use:** When the agent needs academic papers. Both APIs are called simultaneously (or sequentially with short timeout), and results are merged into one formatted response.

**Example — arXiv Atom XML parsing with namespaces:**
```php
<?php
// Source: Derived from arXiv API manual and PHP SimpleXMLElement documentation
// Namespace prefixes:
//   ''       => 'http://www.w3.org/2005/Atom'
//   'arxiv'  => 'http://arxiv.org/schemas/atom'
//   'opensearch' => 'http://a9.com/-/spec/opensearch/1.1/'

$xml = new SimpleXMLElement($responseBody);
$xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
$xml->registerXPathNamespace('arxiv', 'http://arxiv.org/schemas/atom');

foreach ($xml->entry as $entry) {
    // Atom namespace is default — access without prefix
    $id       = (string) $entry->id;          // e.g., http://arxiv.org/abs/...
    $title    = (string) $entry->title;
    $summary  = (string) $entry->summary;     // abstract
    $published = (string) $entry->published;
    $updated  = (string) $entry->updated;

    // Authors
    $authors = [];
    foreach ($entry->author as $author) {
        $authors[] = (string) $author->name;
    }

    // arXiv namespace — use children()
    $arxivChildren = $entry->children('http://arxiv.org/schemas/atom');
    $doi = (string) $arxivChildren->doi;

    // Links — iterate for alternate, pdf, doi
    foreach ($entry->link as $link) {
        $rel  = (string) $link['rel'];
        $href = (string) $link['href'];
        // 'alternate' => abstract page HTML URL
        // title='pdf' => PDF URL
        // title='doi' => DOI link
    }

    // Categories
    foreach ($entry->category as $cat) {
        $term = (string) $cat['term'];  // e.g., 'cs.AI'
    }
}
```

### Pattern 4: AgentManager — Discovery and Lifecycle

**What:** Scans `config/agents/*/config.json` to discover configured agents at runtime. Creates fresh ResearchAgent instances per `research()` call. Returns array of agent answers.

**When to use:** Any time multiple agents need to be discovered and managed. Central point for agent lifecycle.

**Example:**
```php
<?php
// Source: Derived from D-01, D-02, D-03, D-04

class AgentManager
{
    private string $agentsDir;
    private Loader $configLoader;
    private ?Logger $logger;

    /** @return string[] Agent names discovered */
    public function discoverAgents(): array
    {
        $agents = [];
        foreach (glob($this->agentsDir . '/*/config.json') as $configPath) {
            $dirName = basename(dirname($configPath));
            $agents[] = $dirName;
        }
        sort($agents);
        return $agents;
    }

    /** @return array[] Array of agent answers */
    public function research(string $question): array
    {
        $results = [];
        foreach ($this->discoverAgents() as $agentName) {
            $agentDir = $this->agentsDir . '/' . $agentName;
            $agent = new ResearchAgent($agentDir, $this->configLoader, $this->logger);

            // Set up tools based on preferences.json
            $this->configureTools($agent, $agentDir);

            $results[$agentName] = $agent->research($question);
        }
        return $results;
    }
}
```

### Anti-Patterns to Avoid
- **Singleton HttpHelper:** Don't make HttpHelper a static singleton. Constructor injection allows test mocks and per-component timeout overrides.
- **ToolRegistry as god class:** Each tool should be its own class file. Registry only dispatches and manages schemas. Tool logic in tool classes.
- **Mixing result types:** AgentManager should return a consistent array shape regardless of which agents are configured. Wrap all results in the same envelope.
- **Blocking on slow tools:** Use the centralized timeout (60s) to prevent one hung API from blocking the entire agent. Consider short-circuiting tool calls if the first API returns quickly.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTTP timeouts | Custom timeout logic per tool | HttpHelper with constructor-injected timeout values | Single configuration point. CURLOPT_TIMEOUT=60, CURLOPT_CONNECTTIMEOUT=10 everywhere. Change once, applies to all. |
| Atom XML namespace parsing | Regex or manual XML parsing | SimpleXMLElement::children($namespaceUri) | Namespace-aware parsing built into PHP. `$entry->children('http://arxiv.org/schemas/atom')->doi` is one line. |
| JSON response parsing | Custom JSON parser | json_decode with JSON_THROW_ON_ERROR | Built-in, zero-dependency, handles all edge cases (BOM, large integers, nested depth). |
| URL construction | String concatenation | http_build_query() | Proper URL encoding of special characters in search queries, array parameters, and nested structures. |
| Concurrent HTTP (future) | Process forks for parallel requests | curl_multi_exec | curl_multi is process-local, no IPC overhead, works within PHP's single-threaded model. Zero dependencies. |

**Key insight:** Every external API integration in this phase follows the same pattern (GET request with auth header, parse structured response). The standardization that HttpHelper provides eliminates the risk of one tool having different timeout values or error handling than another. The curl_multi_exec foundation in HttpHelper prepares for Phase 3 without any architectural change.

## Common Pitfalls

### Pitfall 1: arXiv Atom XML Namespace Handling
**What goes wrong:** SimpleXMLElement ignores elements in non-default namespaces if not registered. `$entry->doi` returns empty even though `<arxiv:doi>` is present.
**Why it happens:** The default Atom namespace `http://www.w3.org/2005/Atom` applies to `<entry>`, `<title>`, `<id>`, etc., but `<arxiv:doi>` and `<arxiv:comment>` use `http://arxiv.org/schemas/atom`.
**How to avoid:** Always call `$entry->children('http://arxiv.org/schemas/atom')` for arXiv-specific fields. Use `registerXPathNamespace()` for XPath queries.
**Warning signs:** DOIs, comments, or primary category fields appear empty in results.

### Pitfall 2: Brave Search API Key in Wrong Header
**What goes wrong:** API returns 401 or 403 because the key was sent as a query parameter or Bearer token.
**Why it happens:** Brave Search uses `X-Subscription-Token` header, not `Authorization: Bearer`. This is non-standard compared to other APIs.
**How to avoid:** Add header as `'X-Subscription-Token: ' . $apiKey` in HttpHelper or tool-specific header array.
**Warning signs:** HTTP 401/403 on Brave Search when other APIs work.

### Pitfall 3: arXiv Uses HTTP (Not HTTPS)
**What goes wrong:** Mixed content warnings or blocked requests in some environments. PHP's file_get_contents may fail on HTTPS redirects.
**Why it happens:** The arXiv API endpoint is `http://export.arxiv.org/api/query` — HTTP, not HTTPS. HTTPS may redirect or timeout.
**How to avoid:** Use curl (not file_get_contents). Curl handles HTTP->HTTPS redirects gracefully with CURLOPT_FOLLOWLOCATION.
**Warning signs:** Timeout or empty response from arXiv but curl works for other APIs.

### Pitfall 4: Semantic Scholar Rate Limiting Without API Key
**What goes wrong:** HTTP 429 errors after ~20 requests in 5 minutes without an API key.
**Why it happens:** Shared rate limit pool for non-authenticated requests.
**How to avoid:** Obtain a free Semantic Scholar API key for dedicated 1 req/sec pool. Implement exponential backoff in HttpHelper or tool error handler (deferred to v2 per user decisions but should be acknowledged).
**Warning signs:** Intermittent 429 responses from Semantic Scholar, especially during testing with repeated queries.

### Pitfall 5: Tool Result Context Overflow
**What goes wrong:** The context block with web/paper search results exceeds the LLM's context window, causing truncation or errors.
**Why it happens:** Web search returns 10 results with full snippets; academic search returns 5 papers with abstracts. Combined, this can exceed 4K-8K tokens.
**How to avoid:** Truncate per-result to 300 chars (as specified in D-10). Limit `count`/`max_results` to small values (5-10). Implement total context block character limit in tool result formatting.
**Warning signs:** LLM responses that seem to ignore the tool results, or API errors about max_tokens.

### Pitfall 6: Config Scope for Tool API Keys
**What goes wrong:** API keys for Brave Search, Semantic Scholar are stored per-agent in `config.json`, but multiple agents may share keys.
**Why it happens:** Each agent config has its own `api_key` field. If the key changes, every agent config must be updated.
**How to avoid:** Support a lookup pattern where `api_key: "env:BRAVE_API_KEY"` references environment variables, or use a shared config file. For v1, document that keys are per-agent and must be kept in sync.
**Warning signs:** Agent A works, Agent B fails with auth errors despite same key in config.

## Code Examples

Verified patterns from official sources:

### Brave Search API — Web Search Request
```php
<?php
// Source: Brave Search API documentation
//   Endpoint: GET https://api.search.brave.com/res/v1/web/search
//   Auth: X-Subscription-Token header
//   Response: JSON with web.results[] containing title, url, description

public function search(string $query, int $count = 10): array
{
    $url = 'https://api.search.brave.com/res/v1/web/search?q='
         . urlencode($query)
         . '&count=' . $count;

    $result = $this->http->get($url, [
        'Accept: application/json',
        'Accept-Encoding: gzip',
        'X-Subscription-Token: ' . $this->apiKey,
    ]);

    $data = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);

    return $data['web']['results'] ?? [];
}
// [VERIFIED: api.search.brave.com/app/documentation/web-search/get-started]
```

### arXiv API — Paper Search with SimpleXMLElement
```php
<?php
// Source: https://info.arxiv.org/help/api/user-manual.html
//   Endpoint: http://export.arxiv.org/api/query
//   Response: Atom XML with namespaces
//   Fields: id, title, summary (abstract), author/name, published, link, arxiv:doi

public function search(string $query, int $maxResults = 5): array
{
    $url = 'http://export.arxiv.org/api/query?search_query=all:'
         . urlencode($query)
         . '&start=0&max_results=' . $maxResults;

    $result = $this->http->get($url);
    $xml    = new SimpleXMLElement($result['body']);

    $papers = [];
    foreach ($xml->entry as $entry) {
        $authors = [];
        foreach ($entry->author as $author) {
            $authors[] = (string) $author->name;
        }

        $arxiv = $entry->children('http://arxiv.org/schemas/atom');

        $papers[] = [
            'title'       => (string) $entry->title,
            'authors'     => $authors,
            'abstract'    => mb_substr((string) $entry->summary, 0, 300),
            'url'         => $this->findLink($entry->link, 'alternate'),
            'published'   => (string) $entry->published,
            'doi'         => (string) $arxiv->doi,
        ];
    }

    return $papers;
}
// [VERIFIED: info.arxiv.org/help/api/user-manual.html]
```

### Semantic Scholar API — Paper Search
```php
<?php
// Source: https://api.semanticscholar.org/graph/v1/paper/search
//   Endpoint: GET /paper/search or /paper/search/bulk
//   Fields: title, abstract, year, authors, citationCount, externalIds
//   Response: JSON with data[] array

public function search(string $query, int $limit = 5): array
{
    $url = 'https://api.semanticscholar.org/graph/v1/paper/search?query='
         . urlencode($query)
         . '&limit=' . $limit
         . '&fields=title,abstract,year,authors,citationCount,externalIds';

    $result = $this->http->get($url, ['Accept: application/json']);

    $data = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);

    return $data['data'] ?? [];
}
// [VERIFIED: api.semanticscholar.org/graph/v1/paper/search]
```

### Tool Result as Context Block
```php
<?php
// Source: Derived from D-06 — tool results injected as context block in system prompt
// Claude's discretion on exact format — this is the recommended pattern

public function formatToolResults(array $webResults, array $paperResults): string
{
    $blocks = [];

    if (!empty($webResults)) {
        $blocks[] = "## Web Search Results";
        foreach ($webResults as $r) {
            $title = mb_substr($r['title'], 0, 200);
            $snippet = mb_substr($r['description'] ?? '', 0, 300);
            $blocks[] = "- {$title}: {$snippet} ({$r['url']})";
        }
    }

    if (!empty($paperResults)) {
        $blocks[] = "## Academic Paper Results";
        foreach ($paperResults as $p) {
            $authors = implode(', ', array_slice($p['authors'] ?? [], 0, 3));
            $abstract = mb_substr($p['abstract'] ?? '', 0, 300);
            $blocks[] = "- {$p['title']} ({$authors}, {$p['year'] ?? 'n.d.'}): {$abstract}";
        }
    }

    return implode("\n", $blocks);
}

// Then in ResearchAgent:
// $systemPrompt = $this->soul . "\n\n" . $toolResultsBlock;
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| LlmClient constructs provider config at __construct | LlmClient reads provider config per request from agent config | Phase 2 | Enables per-agent provider switching without re-constructing client. D-11. |
| Hardcoded timeouts in LlmClient | Centralized HttpHelper with constructor-injected timeouts | Phase 2 | Single config point for all external HTTP timeouts. D-12. |
| Single agent direct instantiation | AgentManager scanning + lifecycle | Phase 2 | Foundation for Phase 3 multi-orchestration. CONF-08. |
| Agent has LLM-only capability | Agent has registerable tools via ToolRegistry | Phase 2 | TOOL-02 through TOOL-07. Agent can search web and papers. |

**Deprecated/outdated:**
- Direct curl calls in LlmClient: Not removed, but the curl setup pattern (CURLOPT_TIMEOUT, CURLOPT_CONNECTTIMEOUT) should be extracted to HttpHelper. LlmClient uses HttpHelper after extraction.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Brave Search API `X-Subscription-Token` header format remains unchanged | Standard Stack | Auth failure — easy to fix by updating header key |
| A2 | arXiv API remains HTTP (not HTTPS) and Atom XML format unchanged | Code Examples | Backward compatible — arXiv doesn't make breaking changes to its 20+ year old API |
| A3 | Semantic Scholar free tier without API key allows ~100 req/5 min | Common Pitfalls | Works for development; may hit rate limits during CI testing. Mitigation: add free API key during setup |
| A4 | SimpleXMLElement correctly handles UTF-8 arXiv responses | Code Examples | arXiv returns UTF-8; SimpleXMLElement handles UTF-8 natively. Very low risk |
| A5 | Tool result context block length within LLM context window | Common Pitfalls | If results are too long, LLM truncates first. Mitigation: enforce char limits per result |
| A6 | AgentManager scanning agent configs is fast enough (<100ms) for 5-10 agents | Architecture Patterns | glob() on config directory is sub-millisecond. Only risk if configs are on network filesystem |

**If this table is empty:** All claims in this research were verified or cited.

## Open Questions

1. **HttpHelper file location — `src/Http/HttpHelper.php` vs `src/Util/HttpHelper.php`?**
   - What we know: Both follow PSR-4. Existing code has no `Util/` directory.
   - What's unclear: Whether to create a new `Http/` namespace or put utilities in `Util/`.
   - Recommendation: `src/Http/HttpHelper.php` with namespace `App\Http` — clearer intent. But this is Claude's discretion per D-12. Planner can decide.

2. **ToolInterface — define an interface or just duck-type?**
   - What we know: ToolRegistry only needs a callable handler and schema.
   - What's unclear: Whether formalizing a `ToolInterface` with an `execute(array $params): string` method adds value or just ceremony.
   - Recommendation: Skip the interface for v1. Tools are registered with callable handlers. Add interface if second tool pattern emerges. Planner decision.

3. **curl_multi_exec in HttpHelper now or future-proof only?**
   - What we know: Phase 3 needs concurrent HTTP for parallel agents. D-12 says "future-proofing."
   - What's unclear: Whether to implement `getMulti()` in HttpHelper now (stub or working) or leave it as a comment for Phase 3.
   - Recommendation: Implement the `getMulti()` method as a working implementation now — it's ~25 lines and prevents a refactor later. Each tool's current use is sequential so `getMulti()` won't be called until Phase 3.

4. **Semantic Scholar regular search vs bulk search endpoint?**
   - What we know: Two endpoints exist: `/paper/search` (relevance search) and `/paper/search/bulk` (bulk search with more features).
   - What's unclear: Which endpoint is appropriate for agent use. Bulk search supports sorting by citationCount which is useful.
   - Recommendation: Use `/paper/search/bulk` with `sort=citationCount:desc` for v1. It's the recommended endpoint per Semantic Scholar docs and returns more relevant results. But this is Claude's discretion.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP extensions (curl, json, mbstring, SimpleXML) | All APIs | Checked | Bundled with PHP 8.5.4 | Not applicable |
| Brave Search API key | WebSearch tool | Per-agent config | N/A | Documentation says free tier available at brave.com/search/api/ |
| Semantic Scholar API key (optional) | AcademicSearch tool | Optional | N/A | Works without key at ~20 req/min rate limit |
| curl CLI (for debugging) | Development | ✓ | 7.x+ | Not required for production |

**Missing dependencies with no fallback:**
- Brave Search API key must be obtained from https://api.search.brave.com. The tool cannot function without it. Required per D-05.

**Missing dependencies with fallback:**
- Semantic Scholar API key: Falls back to unauthenticated requests with shared rate limit pool. Recommend getting a free key.
- arXiv API key: Not required. arXiv API is free and open access.

## Validation Architecture

### Detect Test Infrastructure
- **Framework:** PHPUnit (recommended by project stack, not yet installed)
- **Config file:** None detected (no phpunit.xml, no composer.json)
- **Existing tests:** None detected (no test/ directory)
- **Command (once configured):** `phpunit` or `./vendor/bin/phpunit`

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CONF-08 | AgentManager discovers agents by scanning config dirs | Unit | `phpunit tests/Agent/AgentManagerTest.php` | Not yet — Wave 0 gap |
| TOOL-02 | WebSearch calls Brave API and returns formatted results | Integration (mock HTTP) | `phpunit tests/Tool/WebSearchTest.php` | Not yet — Wave 0 gap |
| TOOL-03 | AcademicSearch queries arXiv and parses Atom XML | Integration (mock/real) | `phpunit tests/Tool/AcademicSearchTest.php` | Not yet — Wave 0 gap |
| TOOL-04 | AcademicSearch queries Semantic Scholar and parses JSON | Integration (mock/real) | `phpunit tests/Tool/AcademicSearchTest.php` | Same file as TOOL-03 |
| TOOL-05 | LlmClient reads provider config per request | Unit | `phpunit tests/LlmClient/LlmClientTest.php` | Not yet — Wave 0 gap |
| TOOL-06 | WebSearch config-driven provider selection | Unit | `phpunit tests/Tool/WebSearchTest.php` | Same file as TOOL-02 |
| TOOL-07 | AcademicSearch merges and deduplicates results | Unit | `phpunit tests/Tool/AcademicSearchMergeTest.php` | Not yet — Wave 0 gap |
| TOOL-08 | HttpHelper enforces CURLOPT_TIMEOUT and CONNECTTIMEOUT | Unit | `phpunit tests/Http/HttpHelperTest.php` | Not yet — Wave 0 gap |
| LLM-01 | Agent with tool results sends correct system prompt | Integration | `phpunit tests/Agent/ResearchAgentToolTest.php` | Not yet — Wave 0 gap |

### Sampling Rate
- **Per task commit:** `phpunit tests/Tool/WebSearchTest.php tests/Tool/AcademicSearchTest.php` (tool-specific tests)
- **Per wave merge:** `phpunit tests/` (all tests)
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Http/HttpHelperTest.php` — covers TOOL-08
- [ ] `tests/Tool/WebSearchTest.php` — covers TOOL-02, TOOL-06
- [ ] `tests/Tool/AcademicSearchTest.php` — covers TOOL-03, TOOL-04
- [ ] `tests/Tool/AcademicSearchMergeTest.php` — covers TOOL-07
- [ ] `tests/Agent/AgentManagerTest.php` — covers CONF-08
- [ ] `tests/LlmClient/LlmClientTest.php` — covers TOOL-05
- [ ] `tests/Agent/ResearchAgentToolTest.php` — covers agent+tool integration
- [ ] `tests/bootstrap.php` — test autoloader
- [ ] PHPUnit configuration file (phpunit.xml.dist)
- [ ] Composer dev dependency: `phpunit/phpunit` ^12.0

*(Wave 0 gaps: All test infrastructure needs creation — no existing tests in project)*

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | API keys are stored in config files, not user authentication |
| V3 Session Management | No | No user sessions in this phase |
| V4 Access Control | No | No user roles or permissions |
| V5 Input Validation | Yes | Agent question capped at 2000 chars. All API responses decoded with JSON_THROW_ON_ERROR. Tool query parameters validated before sending. |
| V6 Cryptography | No | No encryption in scope. API calls use HTTPS (except arXiv which uses HTTP — accept the risk) |

### Known Threat Patterns for Vanilla PHP + curl

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| API key leakage in error messages | Information Disclosure | Truncate response bodies in error messages (existing pattern in LlmException — truncate to 500 chars) |
| SSRF via user-controlled URLs | Spoofing | All URLs are hardcoded or constructed from config, never from user input. Agent question is LLM message, not a URL. |
| Unvalidated JSON/XML from APIs | Tampering | json_decode with JSON_THROW_ON_ERROR for JSON APIs. SimpleXMLElement for arXiv — XML injection risk mitigated by not round-tripping XML output. |
| Long-running HTTP blocking system | DoS | HttpHelper enforces CURLOPT_TIMEOUT=60s, CURLOPT_CONNECTTIMEOUT=10s on all external calls. |

## Sources

### Primary (HIGH confidence)
- [arXiv API User Manual](https://info.arxiv.org/help/api/user-manual.html) — Endpoint URL, query format, Atom XML response fields, namespace URIs, max_results limits
- [Brave Search API Documentation](https://api.search.brave.com/app/documentation/web-search/get-started) — Endpoint URL, auth header format, request parameters, response JSON structure with web.results[] fields
- [Semantic Scholar API Tutorial](https://www.semanticscholar.org/product/api/tutorial) — Endpoint URLs, field selection, response format, rate limits
- [PHP Manual: SimpleXMLElement::children](https://www.php.net/manual/en/simplexmlelement.children.php) — Namespace-aware XML parsing pattern
- [PHP Manual: curl_multi_exec](https://www.php.net/manual/en/function.curl-multi-exec.php) — Concurrent HTTP pattern with curl_multi_select for CPU-efficient execution
- Existing codebase: `src/Agent/ResearchAgent.php`, `src/LlmClient/LlmClient.php`, `src/Config/Loader.php`, `src/Log/Logger.php` — Confirmed all existing patterns, constructors, and integration points

### Secondary (MEDIUM confidence)
- [Brave Community: Search API Response Objects](https://community.brave.app/t/search-api-response-objects/630474) — Detailed Brave response field documentation (community, not official API docs)
- [kayvane1/brave-api DeepWiki: Web Search Response](https://deepwiki.com/kayvane1/brave-api/3.1-web-search-response) — Brave response field reference (third-party API wrapper docs)
- Semantic Scholar rate limit documentation from various sources — Consistent: ~100 req/5 min without key, 1 req/sec with free key
- [Brave Search API Plans](https://api-dashboard.search.brave.com/app/plans?plan=search) — Pricing and rate limit tiers

### Tertiary (LOW confidence)
- None — all critical claims verified against official documentation or existing codebase

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — All components are PHP built-in extensions verified on runtime. API patterns confirmed from official docs.
- Architecture: HIGH — ToolRegistry, AgentManager, HttpHelper patterns derive from user decisions and standard PHP patterns.
- Pitfalls: HIGH — All pitfalls verified against documented API behavior and known SimpleXMLElement patterns.

**Research date:** 2026-06-13
**Valid until:** 2026-07-13 (30-day validity — APIs are stable, no rapid changes expected)
