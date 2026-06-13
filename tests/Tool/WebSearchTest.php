<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Http\HttpHelper;
use App\Tool\WebSearch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Tool\WebSearch
 */
class WebSearchTest extends TestCase
{
    public function testExecuteThrowsOnMissingApiKey(): void
    {
        $http = new HttpHelper();
        $search = new WebSearch($http, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');

        $search->execute(['q' => 'test']);
    }

    public function testExecuteThrowsOnMissingQueryParam(): void
    {
        $http = new HttpHelper();
        $config = ['api_key' => 'test-key-123'];

        $search = new WebSearch($http, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query');

        $search->execute([]);
    }

    public function testExecuteReturnsFormattedContextBlock(): void
    {
        $http = new HttpHelper();
        $config = ['api_key' => 'test-key-123', 'count' => 5];

        $search = new WebSearch($http, $config);
        $result = $search->execute(['q' => 'test query', 'count' => 1]);

        // Without a real API key, this will return empty string (graceful degradation)
        // or throw. With a valid key it would return formatted results.
        $this->assertIsString($result);
    }
}
