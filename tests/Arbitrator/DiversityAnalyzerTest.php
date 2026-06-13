<?php

declare(strict_types=1);

namespace App\Tests\Arbitrator;

use App\Arbitrator\DiversityAnalyzer;
use PHPUnit\Framework\TestCase;

class DiversityAnalyzerTest extends TestCase
{
    public function testOverlapCoefficientIdentical(): void
    {
        $text = 'The transformer architecture uses self-attention mechanisms for sequence processing';
        $score = DiversityAnalyzer::overlapCoefficient($text, $text, 3);
        $this->assertEqualsWithDelta(1.0, $score, 0.001);
    }

    public function testOverlapCoefficientCompletelyDifferent(): void
    {
        $textA = 'The transformer architecture uses self-attention mechanisms';
        $textB = 'Quantum computing relies on superposition and entanglement principles';
        $score = DiversityAnalyzer::overlapCoefficient($textA, $textB, 3);
        $this->assertEqualsWithDelta(0.0, $score, 0.001);
    }

    public function testOverlapCoefficientPartial(): void
    {
        // Two texts sharing some but not all content
        $textA = 'The transformer architecture uses self-attention and feed-forward layers';
        $textB = 'The transformer architecture uses self-attention for language modeling';
        $score = DiversityAnalyzer::overlapCoefficient($textA, $textB, 3);
        // Should have some overlap (the first few trigrams match) but not 1.0
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThan(1.0, $score);
    }

    public function testOverlapCoefficientEmptyInputs(): void
    {
        $this->assertEqualsWithDelta(1.0, DiversityAnalyzer::overlapCoefficient('', '', 3), 0.001);
        $this->assertEqualsWithDelta(0.0, DiversityAnalyzer::overlapCoefficient('hello world', '', 3), 0.001);
        $this->assertEqualsWithDelta(0.0, DiversityAnalyzer::overlapCoefficient('', 'hello world', 3), 0.001);
    }

    public function testWordNgrams(): void
    {
        $text = 'the quick brown fox';
        $ngrams = DiversityAnalyzer::wordNgrams($text, 3);
        $this->assertCount(2, $ngrams);
        $this->assertContains('the quick brown', $ngrams);
        $this->assertContains('quick brown fox', $ngrams);
    }

    public function testWordNgramsTooShort(): void
    {
        $this->assertSame([], DiversityAnalyzer::wordNgrams('hello world', 3));
        $this->assertSame([], DiversityAnalyzer::wordNgrams('', 3));
    }

    public function testDiversityBonus(): void
    {
        // High similarity = low bonus
        $highSim = DiversityAnalyzer::diversityBonus(0.8, 0.15);
        $this->assertEqualsWithDelta(0.03, $highSim, 0.001);

        // Low similarity = high bonus
        $lowSim = DiversityAnalyzer::diversityBonus(0.2, 0.15);
        $this->assertEqualsWithDelta(0.12, $lowSim, 0.001);

        // Clamped at bounds
        $this->assertEqualsWithDelta(0.0, DiversityAnalyzer::diversityBonus(1.5, 0.15), 0.001);
        $this->assertEqualsWithDelta(0.15, DiversityAnalyzer::diversityBonus(-0.5, 0.15), 0.001);
    }

    public function testComputeSimilarityScores(): void
    {
        $answers = [
            'agent_a' => 'The transformer architecture is a deep learning model introduced in 2017',
            'agent_b' => 'The transformer architecture introduced in 2017 revolutionized NLP',
            'agent_c' => 'Quantum computing uses qubits that can exist in superposition states',
        ];

        $scores = DiversityAnalyzer::computeSimilarityScores($answers, 3);

        // All agents should have a score (0.0-1.0)
        $this->assertCount(3, $scores);
        $this->assertArrayHasKey('agent_a', $scores);
        $this->assertArrayHasKey('agent_b', $scores);
        $this->assertArrayHasKey('agent_c', $scores);

        // agent_a and agent_b share "The transformer architecture" trigram -> should have some similarity
        // agent_c is completely different -> should be low
        $this->assertGreaterThan($scores['agent_c'], $scores['agent_a']);
        $this->assertGreaterThan($scores['agent_c'], $scores['agent_b']);
    }

    public function testComputeSimilarityScoresSingleAgent(): void
    {
        $scores = DiversityAnalyzer::computeSimilarityScores(['only_agent' => 'some text here'], 3);
        $this->assertEqualsWithDelta(0.0, $scores['only_agent'], 0.001);
    }

    public function testComputeSimilarityScoresEmpty(): void
    {
        $this->assertSame([], DiversityAnalyzer::computeSimilarityScores([], 3));
    }
}
