<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Editing\TestMethodEditor;
use Js199069\Mutagent\Generation\RepairHints;
use Js199069\Mutagent\Generation\TestGenerator;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PHPUnit\Framework\TestCase;

final class SalvageTest extends TestCase
{
    private const CODE = <<<'MD'
    ```php
    <?php

    namespace Tests\Generated;

    use PHPUnit\Framework\TestCase;

    final class ShippingCalculatorGeneratedTest extends TestCase
    {
        public function testOne(): void { $this->assertTrue(true); }

        public function testTwo(): void { $this->assertTrue(true); }

        public function testThree(): void { $this->assertTrue(true); }

        public function testFour(): void { $this->assertTrue(true); }

        public function testBad(): void { $this->assertTrue(false); }
    }
    ```
    MD;

    public function test_failing_test_names_are_read_from_phpunit_output(): void
    {
        $output = "There was 1 failure:\n\n1) Tests\\Generated\\XTest::parse_handles_zero\nFailed asserting that exception of type \"Money\\Exception\\ParserException\" is thrown.";

        $this->assertSame(['parse_handles_zero'], TestMethodEditor::failingTests($output));
    }

    public function test_exception_not_thrown_is_explained(): void
    {
        $hints = (new RepairHints())->for('Failed asserting that exception of type "Money\Exception\ParserException" is thrown.');

        $this->assertStringContainsString('does not throw Money\Exception\ParserException', $hints[0]);
    }

    public function test_a_few_contradicting_tests_are_removed_and_the_rest_kept(): void
    {
        $file = sys_get_temp_dir() . '/mutagent-salvage-' . bin2hex(random_bytes(4)) . '.php';

        $llm = new class (self::CODE) implements ChatClient {
            public function __construct(private string $code) {}

            public function complete(array $messages): string
            {
                return $this->code;
            }
        };
        $runner = new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                $code = (string) file_get_contents($testFile);

                return str_contains($code, 'testBad')
                    ? new RunResult(false, "1) Tests\\Generated\\ShippingCalculatorGeneratedTest::testBad\nFailed asserting that false is true.")
                    : new RunResult(true, 'OK (4 tests)');
            }
        };

        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
        $result = (new TestGenerator($llm, $runner))->generate($class, $file, maxAttempts: 1);

        $this->assertTrue($result->passed);
        $this->assertSame(['testBad'], $result->removedFailing);
        $code = (string) file_get_contents($file);
        $this->assertStringNotContainsString('testBad', $code);
        $this->assertStringContainsString('testFour', $code);

        @unlink($file);
    }
}
