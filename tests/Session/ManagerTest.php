<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Session\Manager;
use PHPUnit\Framework\TestCase;

class ManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/research-agents-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }

    public function testSlugFromQuestion(): void
    {
        $slug = Manager::slugFromQuestion('What are the latest advances in transformer architectures?');
        $this->assertSame('what-are-the-latest-advances-in-transformer-architectures', $slug);
    }

    public function testSlugTruncatesAt60Chars(): void
    {
        $long = str_repeat('word ', 20);
        $slug = Manager::slugFromQuestion($long);
        $this->assertLessThanOrEqual(60, strlen($slug));
    }

    public function testSlugStripsSpecialCharacters(): void
    {
        $slug = Manager::slugFromQuestion('What is "AI"? (test) [urgent]');
        $this->assertStringNotContainsString('"', $slug);
        $this->assertStringNotContainsString('?', $slug);
        $this->assertStringNotContainsString('(', $slug);
        $this->assertStringNotContainsString('[', $slug);
    }

    public function testCreateSessionCreatesDirectory(): void
    {
        $manager = new Manager($this->tempDir);
        $question = 'What is quantum computing?';
        $slug = $manager->createSession($question);
        $this->assertDirectoryExists($this->tempDir . '/' . $slug);
    }

    public function testCreateSessionReturnsDatePrefixedSlug(): void
    {
        $manager = new Manager($this->tempDir);
        $slug = $manager->createSession('Test question here');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_/', $slug);
    }

    public function testCreateSessionCreatesSessionMd(): void
    {
        $manager = new Manager($this->tempDir);
        $slug = $manager->createSession('Test question', ['results' => [], 'debate' => null, 'duration_ms' => 0]);
        $this->assertFileExists($this->tempDir . '/' . $slug . '/session.md');
    }

    public function testSessionMdContainsRequiredSections(): void
    {
        $manager = new Manager($this->tempDir);
        $data = [
            'results' => [
                'alpha' => ['answer' => 'Answer A', 'model' => 'gpt-4', 'response_time_ms' => 1000, 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]],
            ],
            'debate' => ['winner' => 'alpha', 'score_table' => [], 'narrative' => 'Good answer.'],
            'duration_ms' => 5000,
        ];
        $slug = $manager->createSession('Test question', $data);
        $content = file_get_contents($this->tempDir . '/' . $slug . '/session.md');
        $this->assertStringContainsString('## Summary', $content);
        $this->assertStringContainsString('## Raw Answers', $content);
        $this->assertStringContainsString('## Debate', $content);
    }

    public function testSessionFilePermissions(): void
    {
        $manager = new Manager($this->tempDir);
        $slug = $manager->createSession('Permission test', ['results' => [], 'debate' => null, 'duration_ms' => 0]);
        $perms = fileperms($this->tempDir . '/' . $slug . '/session.md') & 0777;
        $this->assertLessThanOrEqual(0600, $perms);
    }

    public function testListSessionsReturnsArray(): void
    {
        $manager = new Manager($this->tempDir);
        $manager->createSession('First question', ['results' => [], 'debate' => null, 'duration_ms' => 0]);
        $manager->createSession('Second question', ['results' => [], 'debate' => null, 'duration_ms' => 0]);
        $sessions = $manager->listSessions();
        $this->assertCount(2, $sessions);
    }

    public function testReadSessionReturnsData(): void
    {
        $manager = new Manager($this->tempDir);
        $slug = $manager->createSession('Read test', ['results' => [], 'debate' => null, 'duration_ms' => 0]);
        $data = $manager->readSession($slug);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('question', $data);
        $this->assertSame('Read test', $data['question']);
    }
}
