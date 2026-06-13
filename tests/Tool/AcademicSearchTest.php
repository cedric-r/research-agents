<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Http\HttpHelper;
use App\Tool\AcademicSearch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Tool\AcademicSearch
 */
class AcademicSearchTest extends TestCase
{
    public function testExecuteReturnsFormattedContextBlock(): void
    {
        $http = new HttpHelper();
        $search = new AcademicSearch($http);

        $result = $search->execute(['q' => 'transformer architecture']);

        $this->assertStringContainsString('Academic Paper Results', $result);
    }

    public function testExecuteThrowsOnMissingQueryParam(): void
    {
        $http = new HttpHelper();
        $search = new AcademicSearch($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('query');

        $search->execute([]);
    }
}
