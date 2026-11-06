<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Editing\TestMethodEditor;
use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Improvement\TestImprover;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EditingSafetyTest extends TestCase
{
    // «م» در UTF-8 بایت 0x85 دارد؛ با \R، شماره‌ی خط‌ها ناهم‌خوان می‌شد
    private const CODE = <<<'PHP'
    <?php

    namespace Tests\Generated;

    use PHPUnit\Framework\TestCase;

    final class XGeneratedTest extends TestCase
    {
        // مبلغ منفی
        public function testA(): void
        {
            $this->assertSame('a', 'a');
        }

        // مقدار مرزی
        public function testB(): void
        {
            $this->assertSame('b', 'b');
        }

        public function testC(): void
        {
            $this->assertSame('c', 'c');
        }

        public function testD(): void
        {
            $this->assertSame('d', 'd');
        }
    }

    PHP;

    public function test_removal_is_exact_with_persian_comments(): void
    {
        $result = (new TestMethodEditor())->remove(self::CODE, ['testB']);

        $this->assertStringNotContainsString('function testB', $result);
        $this->assertStringNotContainsString("'b'", $result, 'the body goes with its method');
        foreach (['testA' => "'a'", 'testC' => "'c'", 'testD' => "'d'"] as $name => $body) {
            $this->assertStringContainsString("function {$name}", $result);
            $this->assertStringContainsString($body, $result);
        }
    }

    public function test_failing_tests_are_read_from_indented_infection_output(): void
    {
        $output = "           1) Tests\\Generated\\NumberGeneratedTest::round_money_value_rounds_up\n           Failed asserting";

        $this->assertSame(['round_money_value_rounds_up'], TestMethodEditor::failingTests($output));
    }

    public function test_fix_prompt_drops_lines_that_change_on_every_run(): void
    {
        $output = "PHPUnit 10.5.66 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.3.14\nConfiguration: D:\\x\\phpunit.xml\nRandom Seed:   1793971518\n\n.F\n\nTime: 00:00.061, Memory: 12.00 MB\n\nThere was 1 failure:\n\n1) Tests\\Generated\\XTest::testA\nFailed asserting that 1 is identical to 2.";

        $content = (new PromptBuilder())->fix($output)['content'];

        $this->assertStringNotContainsString('1793971518', $content);
        $this->assertStringNotContainsString('Memory:', $content);
        $this->assertStringContainsString('1 is identical to 2', $content);
    }

    public function test_improve_salvages_tests_that_stop_infection_at_start(): void
    {
        $file = sys_get_temp_dir() . '/mutagent-start-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, self::CODE);

        $llm = new class implements ChatClient {
            public function complete(array $messages): string
            {
                return '';
            }
        };
        $runner = new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                return str_contains((string) file_get_contents($testFile), 'function testB')
                    ? new RunResult(false, '1) Tests\Generated\XGeneratedTest::testB')
                    : new RunResult(true, 'OK');
            }
        };
        $mutation = new class implements MutationRunner {
            public function run(string $testFile): MutationReport
            {
                if (str_contains((string) file_get_contents($testFile), 'function testB')) {
                    throw new RuntimeException("Infection did not produce a log.\n           1) Tests\\Generated\\XGeneratedTest::testB\n           Failed asserting");
                }

                return new MutationReport(3, 3, 0, 0, 0, 0, 100.0, 100, []);
            }
        };

        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
        $result = (new TestImprover($llm, $runner, $mutation))->improve($class, $file, rounds: 1);

        $this->assertSame(['testB'], $result->removedAtStart);
        $this->assertSame(100.0, $result->final->msi);
        $this->assertStringNotContainsString('function testB', (string) file_get_contents($file));

        @unlink($file);
    }
}
