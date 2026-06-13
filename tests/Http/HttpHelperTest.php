<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpHelper;
use App\Http\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Http\HttpHelper
 * @covers \App\Http\HttpException
 */
class HttpHelperTest extends TestCase
{
    private const TEST_PORT = 8899;
    private const TEST_BASE = 'http://127.0.0.1:8899';

    private static ?int $serverPid = null;

    public static function setUpBeforeClass(): void
    {
        $serverDir = __DIR__;
        $routerFile = $serverDir . '/server.php';
        $cmd = sprintf(
            'php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!',
            self::TEST_PORT,
            escapeshellarg($routerFile)
        );
        $output = [];
        exec($cmd, $output, $exitCode);
        self::$serverPid = (int) ($output[0] ?? 0);

        // Wait for server to start
        usleep(300000);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null && self::$serverPid > 0) {
            exec('kill ' . self::$serverPid . ' 2>/dev/null');
        }
    }

    public function testGetReturnsBodyAndHttpCodeOnSuccess(): void
    {
        $http = new HttpHelper();
        $result = $http->get(self::TEST_BASE . '/');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('http_code', $result);
        $this->assertEquals(200, $result['http_code']);
        $this->assertEquals('OK', $result['body']);
    }

    public function testGetReturnsJsonResponse(): void
    {
        $http = new HttpHelper();
        $result = $http->get(self::TEST_BASE . '/json', ['Accept: application/json']);

        $this->assertEquals(200, $result['http_code']);
        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertEquals('ok', $decoded['status']);
    }

    public function testPostReturnsResponse(): void
    {
        $http = new HttpHelper();
        $data = ['message' => 'hello', 'count' => 42];
        $result = $http->post(self::TEST_BASE . '/echo', $data);

        $this->assertEquals(200, $result['http_code']);
        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertEquals('POST', $decoded['method']);
        $this->assertEquals('hello', $decoded['body']['message']);
        $this->assertEquals(42, $decoded['body']['count']);
    }

    public function testGetThrowsHttpExceptionOnConnectionFailure(): void
    {
        $this->expectException(HttpException::class);
        $http = new HttpHelper(timeout: 5, connectTimeout: 2);
        $http->get('http://127.0.0.1:1/');
    }

    public function testGetRespectsTimeout(): void
    {
        $this->expectException(HttpException::class);
        $http = new HttpHelper(timeout: 2, connectTimeout: 1);
        $http->get(self::TEST_BASE . '/slow');
    }

    public function testGetReturnsCustomHeaders(): void
    {
        $http = new HttpHelper();
        $result = $http->get(self::TEST_BASE . '/echo', ['X-Custom: test-value']);

        $this->assertEquals(200, $result['http_code']);
        $decoded = json_decode($result['body'], true);
        $this->assertIsArray($decoded);
        $this->assertEquals('GET', $decoded['method']);
    }

    public function testGetMultiReturnsResults(): void
    {
        $http = new HttpHelper();
        $urls = [
            'first'  => self::TEST_BASE . '/',
            'second' => self::TEST_BASE . '/json',
        ];
        $results = $http->getMulti($urls);

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertEquals(200, $results['first']['http_code']);
        $this->assertEquals(200, $results['second']['http_code']);
    }

    public function testHttpExceptionIsThrowable(): void
    {
        $exception = new HttpException('test error');
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('test error', $exception->getMessage());
    }
}
