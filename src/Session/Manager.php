<?php

/**
 * ResearchAgents -- multi-agent research and debate system.
 * Copyright (C) 2026 Cedric Raguenaud
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */



declare(strict_types=1);

namespace App\Session;

/**
 * Session persistence manager.
 *
 * Creates session directories with date-prefixed slugs, generates
 * full markdown transcripts per D-08, and provides read/list
 * operations for past sessions.
 *
 * @package App\Session
 */
class Manager
{
    private string $sessionDir;
    private array $config;

    /**
     * @param string $sessionDir Absolute path to sessions directory
     */
    public function __construct(string $sessionDir)
    {
        $this->sessionDir = rtrim($sessionDir, '/');

        // Load optional config
        $configPath = dirname($sessionDir, 2) . '/config/sessions/config.json';
        if (file_exists($configPath)) {
            $raw = file_get_contents($configPath);
            if ($raw !== false) {
                $parsed = json_decode($raw, true);
                $this->config = is_array($parsed) ? $parsed : [];
            }
        }
        $this->config ??= [];

        // Ensure session directory exists
        if (!is_dir($this->sessionDir)) {
            @mkdir($this->sessionDir, 0775, true);
        }
    }

    /**
     * Convert question text to a filesystem-safe slug.
     *
     * First 60 characters, lowercase, hyphens for spaces, stripped
     * of non-alphanumeric characters (except hyphens).
     */
    public static function slugFromQuestion(string $question): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9-]+/', '-', strtolower($question));
        $slug = trim($slug, '-');
        return rtrim(mb_substr($slug, 0, 60), '-');
    }

    /**
     * Create a new session directory and write the transcript.
     *
     * @param  string $question Research question
     * @param  array  $data     Optional result data: results, debate, duration_ms
     * @return string           Session ID (date-prefixed slug)
     */
    public function createSession(string $question, array $data = []): string
    {
        $slug = self::slugFromQuestion($question);
        $sessionId = date('Y-m-d') . '_' . $slug;
        $dir = $this->sessionDir . '/' . $sessionId;

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $transcript = $this->generateTranscript(
            $question,
            $data['results'] ?? [],
            $data['debate'] ?? null,
            $data['duration_ms'] ?? 0
        );

        $path = $dir . '/session.md';
        file_put_contents($path, $transcript, LOCK_EX);
        @chmod($path, 0600);

        // Save winner answer as separate file
        $debate = $data['debate'] ?? null;
        $results = $data['results'] ?? [];
        if ($debate !== null && !empty($debate['winner'])) {
            $winnerName = $debate['winner'];
            $winnerResult = $results[$winnerName] ?? null;
            if ($winnerResult !== null && !empty($winnerResult['answer'])) {
                $winnerMd = '# Winner: ' . $winnerName . "\n\n";
                $winnerMd .= '**Question:** ' . $question . "\n\n";
                $winnerMd .= '---' . "\n\n";
                $winnerMd .= $winnerResult['answer'] . "\n\n";
                $winnerMd .= '---' . "\n\n";
                $winnerMd .= '**Model:** ' . ($winnerResult['model'] ?? 'unknown') . "\n";
                $winnerMd .= '**Response time:** ' . ($winnerResult['response_time_ms'] ?? 0) . "ms\n";
                $winnerMd .= '**Tokens:** ' . ($winnerResult['usage']['prompt_tokens'] ?? 0) . ' in / ' . ($winnerResult['usage']['completion_tokens'] ?? 0) . " out\n";

                $winnerPath = $dir . '/winner.md';
                file_put_contents($winnerPath, $winnerMd, LOCK_EX);
                @chmod($winnerPath, 0600);
            }
        }

        return $sessionId;
    }

    /**
     * Generate the full markdown transcript.
     *
     * Structure: YAML frontmatter -> ## Summary -> ## Raw Answers ->
     * ## Debate -> ## Errors (per D-08).
     */
    public function generateTranscript(string $question, array $results, ?array $debate, int $durationMs): string
    {
        $agentNames = array_keys($results);
        $winner = $debate['winner'] ?? 'N/A';
        $correlationId = '';
        $models = [];
        $scoreSummary = '';

        foreach ($results as $name => $result) {
            if ($correlationId === '' && !empty($result['correlation_id'])) {
                $correlationId = $result['correlation_id'];
            }
            if (!empty($result['model'])) {
                $models[] = $result['model'];
            }
        }
        $modelInfo = implode(' ', array_unique($models));

        if ($debate !== null && isset($debate['score_table'])) {
            $parts = [];
            foreach ($debate['score_table'] as $name => $scores) {
                $parts[] = $name . ': ' . ($scores['weighted_total'] ?? 0);
            }
            $scoreSummary = implode(', ', $parts);
        }

        // YAML frontmatter
        $transcript = "---\n";
        $transcript .= 'question: ' . str_replace("\n", ' ', $question) . "\n";
        $transcript .= 'date: ' . date('c') . "\n";
        $transcript .= 'agent_count: ' . count($results) . "\n";
        $transcript .= 'agents: [' . implode(', ', array_map('trim', $agentNames)) . "]\n";
        $transcript .= 'winner: ' . $winner . "\n";
        $transcript .= 'correlation_id: ' . $correlationId . "\n";
        $transcript .= 'duration_ms: ' . $durationMs . "\n";
        $transcript .= 'model_info: ' . $modelInfo . "\n";
        $transcript .= 'score_summary: ' . $scoreSummary . "\n";
        $transcript .= "---\n\n";

        // ## Summary section
        $transcript .= "## Summary\n\n";
        $transcript .= '**Question:** ' . $question . "\n\n";
        $transcript .= '**Winner:** ' . $winner . "\n\n";
        $transcript .= '**Duration:** ' . round($durationMs / 1000, 1) . "s\n\n";

        if ($debate !== null && !empty($debate['score_table'])) {
            $transcript .= "| Agent | Quality | Critique | Diversity | Total |\n";
            $transcript .= "|-------|---------|----------|-----------|-------|\n";
            foreach ($debate['score_table'] as $name => $scores) {
                $transcript .= sprintf(
                    "| %s | %s/10 | %s | %s | %s |\n",
                    $name,
                    number_format((float) ($scores['quality'] ?? 0), 1),
                    number_format((float) ($scores['critique_avg'] ?? 0), 2),
                    number_format((float) ($scores['diversity_bonus'] ?? 0), 2),
                    number_format((float) ($scores['weighted_total'] ?? 0), 3)
                );
            }
            $transcript .= "\n";
        }

        // ## Raw Answers section
        $transcript .= "## Raw Answers\n\n";
        foreach ($results as $name => $result) {
            $transcript .= '### ' . $name . "\n\n";
            $transcript .= '- **Model:** ' . ($result['model'] ?? 'unknown') . "\n";
            $transcript .= '- **Response time:** ' . ($result['response_time_ms'] ?? 0) . "ms\n";
            $transcript .= '- **Tokens:** ' . ($result['usage']['prompt_tokens'] ?? 0) . ' in / ' . ($result['usage']['completion_tokens'] ?? 0) . " out\n";
            if (!empty($result['error'])) {
                $transcript .= '- **Error:** ' . $result['error'] . "\n";
            }
            $transcript .= "\n" . ($result['answer'] ?? '[No answer]') . "\n\n";
            $transcript .= "---\n\n";
        }

        // ## Debate section
        if ($debate !== null) {
            $transcript .= "## Debate\n\n";

            // Per-agent quality scores breakdown
            if (!empty($debate['quality_scores'])) {
                $transcript .= "### Quality Scores\n\n";
                $transcript .= "| Agent | Relevance | Completeness | Citations | Clarity | Confidence | Composite |\n";
                $transcript .= "|-------|-----------|-------------|-----------|---------|------------|-----------|\n";
                foreach ($debate['quality_scores'] as $name => $scores) {
                    $transcript .= sprintf(
                        "| %s | %s/10 | %s/10 | %s/10 | %s/10 | %s/10 | %s/10 |\n",
                        $name,
                        number_format((float) ($scores['relevance'] ?? 0), 1),
                        number_format((float) ($scores['completeness'] ?? 0), 1),
                        number_format((float) ($scores['citation_quality'] ?? 0), 1),
                        number_format((float) ($scores['clarity'] ?? 0), 1),
                        number_format((float) ($scores['confidence'] ?? 0), 1),
                        number_format((float) ($scores['composite'] ?? 0), 1)
                    );
                }
                $transcript .= "\n";

                // Scoring reasoning per agent
                foreach ($debate['quality_scores'] as $name => $scores) {
                    if (!empty($scores['reasoning'])) {
                        $transcript .= '**' . $name . ' reasoning:** ' . $scores['reasoning'] . "\n\n";
                    }
                }
            }

            // Peer critiques (Round 2)
            if (!empty($debate['critique_results'])) {
                $transcript .= "### Peer Critiques\n\n";
                foreach ($debate['critique_results'] as $critic => $cr) {
                    $transcript .= '**From ' . $critic . ':**' . "\n\n";
                    if (!empty($cr['critiques'])) {
                        // Parse critique JSON for structured display
                        $rawCritiques = $cr['critiques'];
                        $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', $rawCritiques);
                        $cleaned = preg_replace('/\n?```\s*$/', '', $cleaned);
                        $parsed = json_decode(trim($cleaned), true);
                        if (is_array($parsed)) {
                            foreach ($parsed as $peerKey => $critique) {
                                $transcript .= '  - **Peer ' . $peerKey . ':** score=' . ($critique['score'] ?? '?') . '/10';
                                if (!empty($critique['strengths'])) {
                                    $transcript .= ', strengths: ' . (is_array($critique['strengths']) ? implode('; ', $critique['strengths']) : $critique['strengths']);
                                }
                                if (!empty($critique['weaknesses'])) {
                                    $transcript .= ', weaknesses: ' . (is_array($critique['weaknesses']) ? implode('; ', $critique['weaknesses']) : $critique['weaknesses']);
                                }
                                $transcript .= "\n";
                            }
                        } else {
                            $transcript .= '  ' . mb_substr($rawCritiques, 0, 500) . "\n";
                        }
                    }
                    if (!empty($cr['error'])) {
                        $transcript .= '  _Error: ' . $cr['error'] . "_\n";
                    }
                    $transcript .= "\n";
                }
            }

            // Diversity analysis
            if (!empty($debate['diversity_data'])) {
                $transcript .= "### Diversity Analysis\n\n";
                $transcript .= "| Agent | Avg Similarity | Diversity Bonus |\n";
                $transcript .= "|-------|----------------|-----------------|\n";
                foreach ($debate['diversity_data'] as $name => $dd) {
                    $transcript .= sprintf(
                        "| %s | %s | %s |\n",
                        $name,
                        number_format((float) ($dd['avg_similarity'] ?? 0), 3),
                        number_format((float) ($dd['diversity_bonus'] ?? 0), 3)
                    );
                }
                $transcript .= "\n";
            }

            // Winner and judge narrative
            $transcript .= '### Winner: ' . $winner . "\n\n";
            $transcript .= ($debate['narrative'] ?? '[No narrative]') . "\n\n";
        }

        // ## Errors section
        $hasErrors = false;
        foreach ($results as $name => $result) {
            if (!empty($result['error'])) {
                if (!$hasErrors) {
                    $transcript .= "## Errors\n\n";
                    $hasErrors = true;
                }
                $transcript .= '- **' . $name . ':** ' . $result['error'] . "\n";
            }
        }

        return $transcript;
    }

    /**
     * Read a session's frontmatter from session.md.
     *
     * @param  string $slug Session slug (date-prefixed)
     * @return array|null   Parsed frontmatter, or null if not found
     */
    public function readSession(string $slug): ?array
    {
        $slug = basename($slug); // Prevent path traversal
        $path = $this->sessionDir . '/' . $slug . '/session.md';

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        // Parse YAML-like frontmatter between --- delimiters
        $lines = explode("\n", $content);
        if (count($lines) < 2 || trim($lines[0]) !== '---') {
            return null;
        }

        $frontmatter = [];
        $inFrontmatter = false;
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $inFrontmatter = true;
                continue;
            }
            if ($inFrontmatter && trim($line) === '---') {
                break;
            }
            if ($inFrontmatter && str_contains($line, ': ')) {
                $parts = explode(': ', $line, 2);
                $key = trim($parts[0]);
                $value = trim($parts[1]);

                // Parse typed values
                if ($value === 'true' || $value === 'false') {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif (is_numeric($value)) {
                    $value = str_contains($value, '.') ? (float) $value : (int) $value;
                } elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    $inner = trim(substr($value, 1, -1));
                    $value = $inner !== '' ? array_map('trim', explode(',', $inner)) : [];
                }

                $frontmatter[$key] = $value;
            }
        }

        $frontmatter['id'] = $slug;
        return $frontmatter;
    }

    /**
     * List all sessions sorted by date descending (newest first).
     *
     * @return array List of session frontmatter arrays
     */
    public function listSessions(): array
    {
        $pattern = $this->sessionDir . '/*/session.md';
        $files = glob($pattern);
        $sessions = [];

        if ($files === false || $files === []) {
            return [];
        }

        foreach ($files as $file) {
            $dirName = basename(dirname($file));
            $data = $this->readSession($dirName);
            if ($data !== null) {
                $sessions[] = $data;
            }
        }

        // Sort by date descending (newest first)
        usort($sessions, function (array $a, array $b): int {
            $dateA = $a['date'] ?? '';
            $dateB = $b['date'] ?? '';
            return strcmp($dateB, $dateA);
        });

        return $sessions;
    }
}
