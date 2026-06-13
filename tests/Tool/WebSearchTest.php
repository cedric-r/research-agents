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
    public function testConstructorRequiresApiKeyInConfig(): void
    {
        $http = new HttpHelper();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');

        new WebSearch($http, []);
    }

    public function testExecuteReturnsFormattedContextBlock(): void
    {
        $http = new HttpHelper();
        $config = ['api_key' => 'test-key-123', 'count' => 5];

        $search = new WebSearch($http, $config);
        $result = $search->execute(['q' => 'test query']);

        $this->assertStringContainsString('Web Search Results', $result);
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
}
