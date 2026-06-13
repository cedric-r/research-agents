<?php

declare(strict_types=1);

namespace App\Tests\LlmClient;

use App\LlmClient\LlmClient;
use App\LlmClient\LlmException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\LlmClient\LlmClient
 * @covers \App\LlmClient\LlmException
 */
class LlmClientTest extends TestCase
{
    private const TEST_PORT = 8898;
    private const TEST_BASE = 'http://127.0.0.1:8898';

    private static ?int $serverPid = null;

    public static function setUpBeforeClass(): void
    {
        $serverDir = __DIR__ . '/../Http';
        $routerFile = $serverDir . '/server.php';
        $cmd = sprintf(
            'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
            self::TEST_PORT,
            escapeshellarg($routerFile)
        );
        $output = [];
        exec($cmd, $output, $exitCode);
        self::$serverPid = (int) ($output[0] ?? 0);

        usleep(300000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            exec('kill ' . self::$serverPid . ' 2>/dev/null');
        }
    }

    public function testConstructorAcceptsSameConfigShape(): void
    {
        $client = new LlmClient([
            'base_url' => self::TEST_BASE,
            'api_key'  => 'test-key',
            'model'    => 'test-model',
            'provider' => 'test-provider',
        ]);

        $this->assertInstanceOf(LlmClient::class, $client);
    }

    public function testChatReturnsStringContent(): void
    {
        $client = new LlmClient([
            'base_url' => self::TEST_BASE,
            'api_key'  => 'test-key',
            'model'    => 'test-model',
            'provider' => 'test-provider',
        ]);

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'What is the capital of France?'],
        ];

        $answer = $client->chat($messages);

        $this->assertIsString($answer);
        $this->assertStringContainsString('capital of France', $answer);
    }

    public function testChatReturnsResponseInfo(): void
    {
        $client = new LlmClient([
            'base_url' => self::TEST_BASE,
            'api_key'  => 'test-key',
            'model'    => 'test-model',
            'provider' => 'test-provider',
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $answer = $client->chat($messages);
        $info = $client->getLastResponseInfo();

        $this->assertIsString($answer);
        $this->assertArrayHasKey('model', $info);
        $this->assertArrayHasKey('usage', $info);
        $this->assertArrayHasKey('response_time_ms', $info);
        $this->assertArrayHasKey('prompt_tokens', $info['usage']);
        $this->assertArrayHasKey('completion_tokens', $info['usage']);
        $this->assertArrayHasKey('total_tokens', $info['usage']);
    }

    public function testThrowsLlmExceptionOnNon200Status(): void
    {
        $this->expectException(LlmException::class);

        // Test server checks Authorization header in /chat/completions
        // and returns 401 if it contains 'wrong-key'
        $client = new LlmClient([
            'base_url' => self::TEST_BASE,
            'api_key'  => 'wrong-key',
            'model'    => 'test-model',
            'provider' => 'test-provider',
        ]);

        $client->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    public function testThrowsLlmExceptionOnConnectionFailure(): void
    {
        $this->expectException(LlmException::class);

        $client = new LlmClient([
            'base_url' => 'http://127.0.0.1:1',
            'api_key'  => 'test-key',
            'model'    => 'test-model',
            'provider' => 'test-provider',
        ]);

        $client->chat([['role' => 'user', 'content' => 'Hello']]);
    }
}
