<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Generation\CodeExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CodeExtractorTest extends TestCase
{
    public function test_extracts_the_longest_php_block(): void
    {
        $response = "Here you go:\n```php\necho 1;\n```\nand the file:\n```php\n<?php\n\nfinal class FooTest {}\n```\n";

        $this->assertSame("<?php\n\nfinal class FooTest {}\n", (new CodeExtractor())->extract($response));
    }

    public function test_adds_open_tag_when_missing(): void
    {
        $this->assertSame("<?php\n\nfinal class FooTest {}\n", (new CodeExtractor())->extract("```php\nfinal class FooTest {}\n```"));
    }

    public function test_rejects_responses_without_a_class(): void
    {
        $this->expectException(RuntimeException::class);

        (new CodeExtractor())->extract('Sorry, I cannot help with that.');
    }
}
