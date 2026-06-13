<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\ResearchAgent;
use App\Config\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ResearchAgent cooperative deadline check (Layer 4, D-16).
 *
 * @covers \App\Agent\ResearchAgent::research
 */
class ResearchAgentTest extends TestCase
{
    private string $tempAgentDir;

    protected function setUp(): void
    {
        // Create temp agent directory with minimal config files
        $this->tempAgentDir = sys_get_temp_dir() . '/test_agent_' . uniqid();
        mkdir($this->tempAgentDir, 0775, true);
        file_put_contents($this->tempAgentDir . '/config.json', json_encode([
            'provider' => 'openrouter',
            'model'    => 'test-model',
            'api_key'  => 'test-key',
        ]));
        file_put_contents($this->tempAgentDir . '/SOUL.md', 'You are a helpful test assistant.');
        file_put_contents($this->tempAgentDir . '/preferences.json', json_encode([
            'tools' => ['llm_only' => true],
        ]));
    }

    protected function tearDown(): void
    {
        // Clean up temp agent fixtures
        $files = [
            $this->tempAgentDir . '/config.json',
            $this->tempAgentDir . '/SOUL.md',
            $this->tempAgentDir . '/preferences.json',
        ];
        foreach ($files as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        rmdir($this->tempAgentDir);
    }

    public function testDeadlineCheckSkipsToolsWhenDeadlineExceeded(): void
    {
        // ResearchAgent with deadline already passed
        $loader = new Loader();
        $agent = new ResearchAgent($this->tempAgentDir, $loader);

        $deadline = microtime(true) - 10; // 10 seconds past deadline

        // Should throw RuntimeException on deadline check before LLM call
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadline reached before LLM call');
        $agent->research('test question', $deadline);
    }

    public function testDeadlineCheckPassesWithSufficientTime(): void
    {
        // ResearchAgent with deadline far in the future
        $loader = new Loader();
        $agent = new ResearchAgent($this->tempAgentDir, $loader);

        $deadline = microtime(true) + 3600; // 1 hour from now

        // Should NOT throw deadline exception (but may fail on actual LLM call)
        // llm_only: true means no tool calls, so it should reach the LLM call
        // Since there's no real LLM to call, it will fail with LlmException or connection error
        // We just verify it doesn't throw the deadline Runtime Exception
        try {
            $agent->research('test question', $deadline);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('deadline', $e->getMessage());
        } catch (\Throwable $e) {
            // Any non-deadline exception is fine -- proves deadline check passed
            $this->assertTrue(true);
        }
    }
}
