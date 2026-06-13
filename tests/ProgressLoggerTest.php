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
