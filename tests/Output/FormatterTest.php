<?php

declare(strict_types=1);

namespace App\Tests\Output;

use PHPUnit\Framework\TestCase;

class FormatterTest extends TestCase
{
    public function testFormatterClassExists(): void
    {
        $this->assertTrue(class_exists(\App\Output\Formatter::class));
    }
}
