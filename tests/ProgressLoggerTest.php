<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Session\ProgressLogger;
use PHPUnit\Framework\TestCase;

class ProgressLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/research-agents-pl-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $logFile = $this->tempDir . '/session.log';
        if (file_exists($logFile)) { @unlink($logFile); }
        @rmdir($this->tempDir);
    }

    public function testLogEventCreatesFile(): void
    {
        $logFile = $this->tempDir . '/session.log';
        $logger = new ProgressLogger($logFile);
        $logger->logEvent('started', 'alpha', ['question' => 'test']);
        $this->assertFileExists($logFile);
    }

    public function testLogEventWritesValidJson(): void
    {
        $logFile = $this->tempDir . '/session.log';
        $logger = new ProgressLogger($logFile);
        $logger->logEvent('started', 'alpha', ['model' => 'gpt-4']);
        $line = trim(file_get_contents($logFile));
        $data = json_decode($line, true);
        $this->assertNotNull($data);
        $this->assertSame('alpha', $data['agent']);
        $this->assertSame('started', $data['event']);
        $this->assertArrayHasKey('ts', $data);
        $this->assertSame('PROGRESS', $data['channel']);
    }

    public function testReadNewLinesReturnsEvents(): void
    {
        $logFile = $this->tempDir . '/session.log';
        $logger = new ProgressLogger($logFile);
        $logger->logEvent('started', 'alpha');
        $logger->logEvent('web_search', 'beta');
        $result = $logger->readNewLines(0);
        $this->assertCount(2, $result['lines']);
        $this->assertGreaterThan(0, $result['new_offset']);
    }

    public function testReadNewLinesRespectsOffset(): void
    {
        $logFile = $this->tempDir . '/session.log';
        $logger = new ProgressLogger($logFile);
        $logger->logEvent('started', 'alpha');
        $firstResult = $logger->readNewLines(0);
        $this->assertCount(1, $firstResult['lines']);

        $logger->logEvent('web_search', 'beta');
        $secondResult = $logger->readNewLines($firstResult['new_offset']);
        $this->assertCount(1, $secondResult['lines']);
        $data = json_decode($secondResult['lines'][0], true);
        $this->assertSame('web_search', $data['event']);
    }

    public function testReadNewLinesFromMissingFile(): void
    {
        $logFile = $this->tempDir . '/nonexistent.log';
        $logger = new ProgressLogger($logFile);
        $result = $logger->readNewLines(0);
        $this->assertSame([], $result['lines']);
        $this->assertSame(0, $result['new_offset']);
    }

    public function testLogEventAllEventTypes(): void
    {
        $logFile = $this->tempDir . '/session.log';
        $logger = new ProgressLogger($logFile);
        $events = ['started', 'llm_call', 'web_search', 'paper_search', 'tool_result', 'score_evaluated', 'critique_r2_started', 'critique_completed', 'completed', 'timed_out', 'failed', 'batch_complete'];
        foreach ($events as $event) {
            $logger->logEvent($event, 'alpha');
        }
        $result = $logger->readNewLines(0);
        $this->assertCount(count($events), $result['lines']);
    }
}
