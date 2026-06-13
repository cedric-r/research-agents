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

namespace App\Arbitrator;

/**
 * Compute n-gram overlap for diversity scoring.
 *
 * Pure PHP implementation -- no external dependencies.
 * Word-level n-grams with configurable n (default: 3).
 * Returns overlap coefficient: intersection_size / min(set1, set2).
 *
 * @see D-03: Diversity-weighted scoring
 */
final class DiversityAnalyzer
{
    /**
     * Compute pairwise n-gram similarity for an array of answer texts.
     *
     * @param  array<string, string> $answers Agent name => answer text
     * @param  int                   $n       N-gram size (default: 3, range: 2-4)
     * @return array<string, float>           Agent name => avg similarity to all others (0.0-1.0)
     */
    public static function computeSimilarityScores(array $answers, int $n = 3): array
    {
        if (empty($answers)) {
            return [];
        }

        $agentNames = array_keys($answers);
        $similarities = [];

        foreach ($agentNames as $name) {
            $similarities[$name] = 0.0;
        }

        $pairCount = 0;
        $count = count($agentNames);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $nameA = $agentNames[$i];
                $nameB = $agentNames[$j];

                $score = self::overlapCoefficient(
                    $answers[$nameA],
                    $answers[$nameB],
                    $n
                );

                $similarities[$nameA] += $score;
                $similarities[$nameB] += $score;
                $pairCount++;
            }
        }

        // Average similarity per agent
        if ($pairCount > 0) {
            foreach ($agentNames as $name) {
                $similarities[$name] /= ($count - 1);
            }
        }

        return $similarities;
    }

    /**
     * Compute overlap coefficient between two texts.
     *
     * overlap = |intersection(word_n_grams)| / min(|setA|, |setB|)
     *
     * @param  string $textA First answer text
     * @param  string $textB Second answer text
     * @param  int    $n     N-gram size
     * @return float          0.0 (no overlap) to 1.0 (identical overlap)
     */
    public static function overlapCoefficient(string $textA, string $textB, int $n = 3): float
    {
        // Check raw input strings first -- "both empty" and "one empty" refer
        // to the original text inputs, not the n-gram set counts.
        $trimmedA = trim($textA);
        $trimmedB = trim($textB);

        if ($trimmedA === '' && $trimmedB === '') {
            return 1.0; // Both empty strings: identical empty content
        }

        if ($trimmedA === '' || $trimmedB === '') {
            return 0.0; // One empty string: no overlap possible
        }

        $gramsA = self::wordNgrams($textA, $n);
        $gramsB = self::wordNgrams($textB, $n);

        $countA = count($gramsA);
        $countB = count($gramsB);

        // min(countA, countB) == 0: text had content but was too short for n-grams
        if ($countA === 0 || $countB === 0) {
            return 0.0;
        }

        $intersection = array_intersect($gramsA, $gramsB);
        $overlap = count($intersection) / min($countA, $countB);

        return min($overlap, 1.0);
    }

    /**
     * Generate word-level n-grams from text.
     *
     * Normalizes whitespace, lowercases, splits on whitespace,
     * then generates sliding window n-grams.
     *
     * @param  string $text Input text
     * @param  int    $n    N-gram size (default: 3)
     * @return string[]     Array of n-gram strings, or [] if too short
     */
    public static function wordNgrams(string $text, int $n = 3): array
    {
        // Normalize: lowercase, collapse whitespace
        $text = mb_strtolower(trim(preg_replace('/\s+/', ' ', $text)));

        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/', $text);
        $wordCount = count($words);

        if ($wordCount < $n) {
            return [];
        }

        $ngrams = [];
        for ($i = 0; $i <= $wordCount - $n; $i++) {
            $ngrams[] = implode(' ', array_slice($words, $i, $n));
        }

        return $ngrams;
    }

    /**
     * Compute diversity bonus from average similarity.
     *
     * bonus = (1.0 - avgSimilarity) * diversityFactor
     *
     * @param  float $avgSimilarity   Average pairwise similarity (0.0-1.0)
     * @param  float $diversityFactor Diversity weight factor (default: 0.15)
     * @return float                  Diversity bonus (0.0 to diversityFactor)
     */
    public static function diversityBonus(float $avgSimilarity, float $diversityFactor = 0.15): float
    {
        // Clamp avgSimilarity to [0.0, 1.0]
        $avgSimilarity = max(0.0, min(1.0, $avgSimilarity));

        return (1.0 - $avgSimilarity) * $diversityFactor;
    }
}
