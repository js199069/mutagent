<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Generation\RepairHints;
use PHPUnit\Framework\TestCase;

final class RepairHintsTest extends TestCase
{
    public function test_data_provider_annotation_problem_is_explained(): void
    {
        // خروجی واقعی جلسه‌ی ۲
        $output = 'ArgumentCountError: Too few arguments to function Tests\Generated\ShippingCalculatorGeneratedTest::testCostReturnsCorrectValue(), 0 passed';

        $hints = (new RepairHints())->for($output);

        $this->assertCount(1, $hints);
        $this->assertStringContainsString('#[\PHPUnit\Framework\Attributes\DataProvider', $hints[0]);
    }

    public function test_wrong_expected_value_asks_for_tracing(): void
    {
        $hints = (new RepairHints())->for('Failed asserting that 45000 is identical to 40000.');

        $this->assertStringContainsString('tracing the source', $hints[0]);
    }

    public function test_unknown_output_gives_no_hint(): void
    {
        $this->assertSame([], (new RepairHints())->for('Something unexpected'));
    }

    public function test_fix_prompt_includes_the_hint(): void
    {
        $message = (new PromptBuilder())->fix('Too few arguments to function X\FooTest::testBar(), 0 passed');

        $this->assertStringContainsString('Likely cause', $message['content']);
        $this->assertStringContainsString('DataProvider', $message['content']);
    }

    public function test_string_versus_number_mismatch_is_explained(): void
    {
        // خروجی واقعی روی DecimalMoneyParser
        $hints = (new RepairHints())->for("Failed asserting that '-1000' is identical to -1000.");

        $this->assertStringContainsString("expect the string literal '-1000'", $hints[0]);
    }
}
