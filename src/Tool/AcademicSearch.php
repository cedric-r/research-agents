<?php

declare(strict_types=1);

namespace App\Tool;

use App\Http\HttpHelper;
use App\Log\Logger;

/**
 * Academic paper search tool combining arXiv and Semantic Scholar.
 *
 * Queries both APIs for a given research question, then merges and
 * deduplicates results into a formatted context block for LLM prompts.
 * Each API failure is handled independently — one can fail while the
 * other continues. Returns empty string on total failure.
 */
class AcademicSearch
{
    private const ARXIV_BASE = 'http://export.arxiv.org/api/query';
    private const SEMANTIC_SCHOLAR_BASE = 'https://api.semanticscholar.org/graph/v1/paper/search';

    private HttpHelper $http;
    private array $config;
    private ?Logger $logger;

    /**
     * @param HttpHelper   $http   Centralized HTTP utility
     * @param array        $config Configuration:
     *                             - 'max_results' (int, optional, default 5): per-API limit
     *                             - 'semantic_scholar_api_key' (string, optional): for higher rate limits
     * @param Logger|null  $logger Optional logger for tool activity
     */
    public function __construct(HttpHelper $http, array $config = [], ?Logger $logger = null)
    {
        $this->http = $http;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Execute academic paper search across arXiv and Semantic Scholar.
     *
     * @param  array  $params Parameters:
     *                        - 'q' (string, required): search query
     *                        - 'max_results' (int, optional): per-API limit (overrides config)
     * @return string Formatted context block with merged, deduplicated results, or empty string
     * @throws \RuntimeException If query parameter is missing
     */
    public function execute(array $params): string
    {
        $query = $params['q'] ?? '';

        if (trim($query) === '') {
            throw new \RuntimeException('AcademicSearch: parameter \'q\' (search query) is required');
        }

        $maxResults = (int) ($params['max_results'] ?? $this->config['max_results'] ?? 5);
        $maxResults = max(1, min(20, $maxResults));

        // Query both APIs independently
        $arxivPapers = $this->queryArxiv($query, $maxResults);
        $semanticPapers = $this->querySemanticScholar($query, $maxResults);

        $allPapers = array_merge($arxivPapers, $semanticPapers);

        if (empty($allPapers)) {
            return '';
        }

        // Deduplicate by arXiv ID and DOI
        $allPapers = $this->deduplicate($allPapers);

        // Sort: year descending, then citationCount descending
        usort($allPapers, function (array $a, array $b): int {
            $yearA = (int) ($a['year'] ?? 0);
            $yearB = (int) ($b['year'] ?? 0);
            if ($yearA !== $yearB) {
                return $yearB <=> $yearA;
            }
            return ($b['citationCount'] ?? 0) <=> ($a['citationCount'] ?? 0);
        });

        return $this->formatResults($allPapers);
    }

    /**
     * Query arXiv API for papers matching the search query.
     *
     * @param  string $query Search query
     * @param  int    $max   Max results
     * @return array  List of paper arrays with normalized keys
     */
    private function queryArxiv(string $query, int $max): array
    {
        $url = self::ARXIV_BASE
            . '?search_query=all:' . urlencode($query)
            . '&start=0&max_results=' . $max;

        try {
            $response = $this->http->get($url, ['Accept: application/atom+xml']);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: arXiv request failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: arXiv non-200 response', [
                    'http_code' => $response['http_code'],
                ]);
            }
            return [];
        }

        try {
            $xml = new \SimpleXMLElement($response['body']);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: arXiv XML parse failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }

        $papers = [];
        $nsAtom = 'http://www.w3.org/2005/Atom';
        $nsArxiv = 'http://arxiv.org/schemas/atom';

        foreach ($xml->entry as $entry) {
            $id = (string) $entry->id;
            $arxivId = $this->extractArxivId($id);

            $title = $this->normalizeWhitespace((string) $entry->title);
            $summary = $this->normalizeWhitespace((string) $entry->summary);

            $published = (string) $entry->published;
            $year = $published !== '' ? (int) substr($published, 0, 4) : 0;

            // Extract authors
            $authors = [];
            foreach ($entry->author as $author) {
                $authors[] = (string) $author->name;
            }

            // Extract abstract URL from link with rel="alternate"
            $absUrl = '';
            foreach ($entry->link as $link) {
                $attrs = $link->attributes();
                if ((string) $attrs['rel'] === 'alternate') {
                    $absUrl = (string) $attrs['href'];
                    break;
                }
            }

            // Extract DOI from arxiv namespace
            $doi = '';
            $arxivChildren = $entry->children($nsArxiv);
            if (isset($arxivChildren->doi)) {
                $doi = (string) $arxivChildren->doi;
            }

            $papers[] = [
                'title'         => mb_substr($title, 0, 200),
                'abstract'      => mb_substr($summary, 0, 300),
                'year'          => $year,
                'authors'       => $authors,
                'url'           => $absUrl ?: $id,
                'doi'           => $doi,
                'citationCount' => 0,
                'arxivId'       => $arxivId,
                'source'        => 'arxiv',
            ];
        }

        return $papers;
    }

    /**
     * Query Semantic Scholar API for papers matching the search query.
     *
     * @param  string $query Search query
     * @param  int    $max   Max results
     * @return array  List of paper arrays with normalized keys
     */
    private function querySemanticScholar(string $query, int $max): array
    {
        $fields = 'title,abstract,year,authors,citationCount,externalIds';
        $url = self::SEMANTIC_SCHOLAR_BASE
            . '?query=' . urlencode($query)
            . '&limit=' . $max
            . '&fields=' . urlencode($fields)
            . '&sort=citationCount:desc';

        $headers = ['Accept: application/json'];

        // Optional API key for higher rate limits
        if (!empty($this->config['semantic_scholar_api_key'])) {
            $headers[] = 'x-api-key: ' . $this->config['semantic_scholar_api_key'];
        }

        try {
            $response = $this->http->get($url, $headers);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: Semantic Scholar request failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }

        if ($response['http_code'] !== 200) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: Semantic Scholar non-200 response', [
                    'http_code' => $response['http_code'],
                ]);
            }
            return [];
        }

        try {
            $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->logger) {
                $this->logger->warn('AcademicSearch: Semantic Scholar JSON parse failed', [
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }

        $results = $data['data'] ?? [];

        if (empty($results)) {
            return [];
        }

        $papers = [];
        foreach ($results as $item) {
            $authors = [];
            foreach ($item['authors'] ?? [] as $author) {
                $authors[] = $author['name'] ?? '';
            }
            $authors = array_values(array_filter($authors));

            $externalIds = $item['externalIds'] ?? [];
            $arxivId = $externalIds['ArXiv'] ?? '';

            $papers[] = [
                'title'         => mb_substr($item['title'] ?? '', 0, 200),
                'abstract'      => mb_substr($item['abstract'] ?? '', 0, 300),
                'year'          => (int) ($item['year'] ?? 0),
                'authors'       => $authors,
                'url'           => sprintf('https://api.semanticscholar.org/%s', $item['paperId'] ?? ''),
                'doi'           => $externalIds['DOI'] ?? '',
                'citationCount' => (int) ($item['citationCount'] ?? 0),
                'arxivId'       => $arxivId,
                'source'        => 'semantic_scholar',
            ];
        }

        return $papers;
    }

    /**
     * Deduplicate merged paper list by arXiv ID and DOI.
     *
     * When duplicates found, keeps the entry with more complete data
     * (prefers the one with a non-empty abstract).
     *
     * @param  array $papers List of paper arrays
     * @return array Deduplicated list
     */
    private function deduplicate(array $papers): array
    {
        $seen = [];
        $deduped = [];

        foreach ($papers as $paper) {
            $key = '';

            // Use arXiv ID as primary dedup key
            if (!empty($paper['arxivId'])) {
                $key = 'arxiv:' . $paper['arxivId'];
            } elseif (!empty($paper['doi'])) {
                $key = 'doi:' . $paper['doi'];
            }

            if ($key === '') {
                // No dedup key — use title fingerprint as fallback
                $key = 'title:' . mb_substr(md5(mb_strtolower(trim($paper['title']))), 0, 16);
            }

            if (isset($seen[$key])) {
                $existing = $seen[$key];
                // Keep the entry with more complete data
                if (!empty($paper['abstract']) && empty($existing['abstract'])) {
                    $deduped[$key] = $paper;
                }
                continue;
            }

            $seen[$key] = $paper;
            $deduped[$key] = $paper;
        }

        return array_values($deduped);
    }

    /**
     * Format merged paper list as a context block for LLM prompts.
     *
     * @param  array  $papers Deduplicated, sorted paper list
     * @return string Formatted context block string
     */
    private function formatResults(array $papers): string
    {
        $lines = ["## Academic Paper Results"];

        foreach ($papers as $paper) {
            $title = $paper['title'] !== '' ? $paper['title'] : 'Untitled';
            $authors = $this->formatAuthors($paper['authors'] ?? []);
            $year = $paper['year'] > 0 ? (string) $paper['year'] : 'n.d.';
            $abstract = $paper['abstract'] !== '' ? $paper['abstract'] : 'No abstract available';
            $url = $paper['url'] ?? '';
            $citations = $paper['citationCount'] ?? 0;
            $doi = $paper['doi'] ?? '';

            $parts = ["- {$title} ({$authors}, {$year}): {$abstract}"];

            $meta = [];
            if ($url !== '') {
                $meta[] = "URL: {$url}";
            }
            if ($citations > 0) {
                $meta[] = "Citations: {$citations}";
            }
            if ($doi !== '') {
                $meta[] = "DOI: {$doi}";
            }

            if (!empty($meta)) {
                $parts[] = '  ' . implode(' | ', $meta);
            }

            $lines[] = implode("\n", $parts);
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Format author list: first 3 names then "et al." if more.
     *
     * @param  array  $authors Author name strings
     * @return string Formatted author string
     */
    private function formatAuthors(array $authors): string
    {
        $authors = array_values(array_filter($authors));
        if (empty($authors)) {
            return 'Unknown';
        }

        $display = array_slice($authors, 0, 3);
        $result = implode(', ', $display);

        if (count($authors) > 3) {
            $result .= ' et al.';
        }

        return $result;
    }

    /**
     * Normalize whitespace in strings from XML/API sources.
     * Replaces newlines and multiple spaces with single space.
     */
    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Extract arXiv ID from the arXiv paper URL.
     *
     * @param  string $id arXiv ID URL (e.g., http://arxiv.org/abs/XXXX.XXXXX)
     * @return string Extracted arXiv ID, or empty string
     */
    private function extractArxivId(string $id): string
    {
        // arXiv IDs can be: http://arxiv.org/abs/XXXX.XXXXXv1 or arxiv:XXXX.XXXXX
        if (preg_match('#(?:arxiv\.org/abs/|arxiv:)([a-z\-]+/\d{7}|\d{4}\.\d{4,5})(v\d+)?#i', $id, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
