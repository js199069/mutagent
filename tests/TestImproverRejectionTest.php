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
use RuntimeException;

/** حالتی که در اجرای واقعی پیش آمد: نامزد جداگانه سبز است، ولی Infection آن را نمی‌پذیرد. */
final class TestImproverRejectionTest extends TestCase
{
    public function test_candidate_rejected_by_infection_is_reverted_without_crashing(): void
    {
        $file = sys_get_temp_dir() . '/mutagent-reject-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, 'ORIGINAL');

        $mutant = new Mutant('escaped', 'Plus', 'src/X.php', 1, "@@ @@\n-a\n+b\n");
        $report = new MutationReport(4, 3, 1, 0, 0, 0, 75.0, 100, [$mutant]);

        $llm = new class implements ChatClient {
            public function complete(array $messages): string
            {
                return "```json\n[{\"mutant\": 1, \"verdict\": \"kill\"}]\n```\n```php\n<?php\n\nfinal class XTest {}\n```";
            }
        };
        $runner = new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                return new RunResult(true, 'OK');
            }
        };
        $mutation = new class ($report) implements MutationRunner {
            private int $calls = 0;

            public function __construct(private MutationReport $report) {}

            public function run(string $testFile): MutationReport
            {
                if (++$this->calls > 1) {
                    throw new RuntimeException('Project tests must be in a passing state before running Infection.');
                }

                return $this->report;
            }
        };

        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
        $result = (new TestImprover($llm, $runner, $mutation))->improve($class, $file, rounds: 1);

        $this->assertSame('ORIGINAL', file_get_contents($file));
        $this->assertSame(1, $result->rejectedCandidates);
        $this->assertSame(75.0, $result->final->msi, 'the last valid report is kept');
        $this->assertCount(1, $result->unresolved);

        @unlink($file);
    }
}
