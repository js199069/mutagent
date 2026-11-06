<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Improvement\TestImprover;
use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PHPUnit\Framework\TestCase;

final class TestImproverTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/mutagent-improve-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->file, 'ORIGINAL');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function mutant(int $line): Mutant
    {
        return new Mutant('escaped', 'Plus', 'src/X.php', $line, "@@ @@\n-a{$line}\n+b{$line}\n");
    }

    private function report(array $escaped, int $killed): MutationReport
    {
        $total = $killed + count($escaped);

        return new MutationReport($total, $killed, count($escaped), 0, 0, 0, $total ? round(100 * $killed / $total, 2) : 0, 100, $escaped);
    }

    private function llm(array $responses): ChatClient
    {
        return new class ($responses) implements ChatClient {
            public int $calls = 0;

            public function __construct(private array $responses) {}

            public function complete(array $messages): string
            {
                $this->calls++;

                return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
            }
        };
    }

    private function mutation(array $reports): MutationRunner
    {
        return new class ($reports) implements MutationRunner {
            public function __construct(private array $reports) {}

            public function run(string $testFile): MutationReport
            {
                return count($this->reports) > 1 ? array_shift($this->reports) : $this->reports[0];
            }
        };
    }

    private function runner(bool $passes): TestRunner
    {
        return new class ($passes) implements TestRunner {
            public function __construct(private bool $passes) {}

            public function run(string $testFile): RunResult
            {
                return new RunResult($this->passes, $this->passes ? 'OK' : 'FAILED');
            }
        };
    }

    private function class(): \Js199069\Mutagent\Analysis\ClassInfo
    {
        return (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
    }

    private const CODE = "```php\n<?php\n\nfinal class ShippingCalculatorGeneratedTest {}\n```";

    public function test_does_nothing_when_no_mutant_escapes(): void
    {
        $llm = $this->llm([self::CODE]);
        $result = (new TestImprover($llm, $this->runner(true), $this->mutation([$this->report([], 5)])))
            ->improve($this->class(), $this->file);

        $this->assertSame(0, $llm->calls);
        $this->assertSame(0, $result->rounds);
    }

    public function test_kills_mutants_and_keeps_the_new_file(): void
    {
        $m1 = $this->mutant(1);
        $m2 = $this->mutant(2);
        $response = "```json\n[{\"mutant\": 1, \"verdict\": \"kill\"}, {\"mutant\": 2, \"verdict\": \"kill\"}]\n```\n" . self::CODE;

        $result = (new TestImprover($this->llm([$response]), $this->runner(true), $this->mutation([$this->report([$m1, $m2], 3), $this->report([], 5)])))
            ->improve($this->class(), $this->file);

        $this->assertSame(0, $result->final->escaped);
        $this->assertSame(100.0, $result->final->msi);
        $this->assertStringContainsString('ShippingCalculatorGeneratedTest', (string) file_get_contents($this->file));
    }

    public function test_claimed_equivalent_mutants_are_not_retried(): void
    {
        $m1 = $this->mutant(1);
        $m2 = $this->mutant(2);
        $response = "```json\n[{\"mutant\": 1, \"verdict\": \"equivalent\", \"reason\": \"weight is always positive\"}, {\"mutant\": 2, \"verdict\": \"kill\"}]\n```\n" . self::CODE;
        $llm = $this->llm([$response]);

        $result = (new TestImprover($llm, $this->runner(true), $this->mutation([$this->report([$m1, $m2], 3), $this->report([$m1], 4)])))
            ->improve($this->class(), $this->file, rounds: 3);

        $this->assertSame(1, $llm->calls, 'the only survivor is claimed equivalent, so no second round');
        $this->assertCount(1, $result->equivalent);
        $this->assertSame('weight is always positive', $result->equivalent[0]->reason);
        $this->assertSame([], $result->unresolved);
        $this->assertSame(100.0, $result->msiExcludingClaimedEquivalent());
    }

    public function test_a_candidate_that_fails_on_the_original_code_is_reverted(): void
    {
        $m1 = $this->mutant(1);
        $response = "```json\n[{\"mutant\": 1, \"verdict\": \"kill\"}]\n```\n" . self::CODE;

        $result = (new TestImprover($this->llm([$response]), $this->runner(false), $this->mutation([$this->report([$m1], 3)])))
            ->improve($this->class(), $this->file, rounds: 1);

        $this->assertSame('ORIGINAL', file_get_contents($this->file));
        $this->assertCount(1, $result->unresolved);
    }

    public function test_a_mutant_is_targeted_at_most_twice(): void
    {
        $m1 = $this->mutant(1);
        $response = "```json\n[{\"mutant\": 1, \"verdict\": \"kill\"}]\n```\n" . self::CODE;
        $llm = $this->llm([$response]);

        $result = (new TestImprover($llm, $this->runner(true), $this->mutation([$this->report([$m1], 3)])))
            ->improve($this->class(), $this->file, rounds: 5);

        $this->assertSame(2, $llm->calls);
        $this->assertCount(1, $result->unresolved);
    }
}
