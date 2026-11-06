<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Generation\TestGenerator;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PHPUnit\Framework\TestCase;

final class TestGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mutagent-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function class(): ClassInfo
    {
        return (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
    }

    private function llm(array $responses): ChatClient
    {
        return new class ($responses) implements ChatClient {
            public array $calls = [];

            public function __construct(private array $responses) {}

            public function complete(array $messages): string
            {
                $this->calls[] = $messages;

                return array_shift($this->responses);
            }
        };
    }

    private function runner(array $results): TestRunner
    {
        return new class ($results) implements TestRunner {
            public function __construct(private array $results) {}

            public function run(string $testFile): RunResult
            {
                return array_shift($this->results);
            }
        };
    }

    private const CODE = "```php\n<?php\n\nfinal class ShippingCalculatorGeneratedTest {}\n```";

    public function test_stops_at_the_first_passing_attempt(): void
    {
        $llm = $this->llm([self::CODE]);
        $result = (new TestGenerator($llm, $this->runner([new RunResult(true, 'OK')])))
            ->generate($this->class(), $this->dir . '/T.php');

        $this->assertTrue($result->passed);
        $this->assertSame(1, $result->attempts);
        $this->assertFileExists($this->dir . '/T.php');
    }

    public function test_feeds_the_failure_output_back_to_the_model(): void
    {
        $llm = $this->llm([self::CODE, self::CODE]);
        $runner = $this->runner([new RunResult(false, 'Failed asserting that 45000 is identical to 40000.'), new RunResult(true, 'OK')]);

        $result = (new TestGenerator($llm, $runner))->generate($this->class(), $this->dir . '/T.php');

        $this->assertTrue($result->passed);
        $this->assertSame(2, $result->attempts);
        $secondCall = end($llm->calls);
        $this->assertStringContainsString('45000 is identical to 40000', end($secondCall)['content']);
    }

    public function test_gives_up_and_moves_the_file_aside(): void
    {
        $llm = $this->llm([self::CODE, self::CODE]);
        $runner = $this->runner([new RunResult(false, 'fail 1'), new RunResult(false, 'fail 2')]);

        $result = (new TestGenerator($llm, $runner))->generate($this->class(), $this->dir . '/T.php', maxAttempts: 2);

        $this->assertFalse($result->passed);
        $this->assertFileDoesNotExist($this->dir . '/T.php', 'a failing test must not stay in the suite');
        $this->assertFileExists($this->dir . '/T.php.failed');
    }

    public function test_a_response_without_code_counts_as_a_failed_attempt(): void
    {
        $llm = $this->llm(['I cannot do that.', self::CODE]);

        $result = (new TestGenerator($llm, $this->runner([new RunResult(true, 'OK')])))
            ->generate($this->class(), $this->dir . '/T.php');

        $this->assertTrue($result->passed);
        $this->assertSame(2, $result->attempts);
    }
}
