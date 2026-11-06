<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Improvement\MutantVerdict;
use Js199069\Mutagent\Improvement\TestImprover;
use Js199069\Mutagent\Improvement\VerdictStore;
use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutantKey;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\CachingChatClient;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PHPUnit\Framework\TestCase;

final class TokenSavingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mutagent-save-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    private function counter(): ChatClient
    {
        return new class implements ChatClient {
            public int $calls = 0;

            public function complete(array $messages): string
            {
                $this->calls++;

                return 'answer ' . $this->calls;
            }
        };
    }

    public function test_identical_prompts_are_answered_from_the_cache(): void
    {
        $inner = $this->counter();
        $llm = new CachingChatClient($inner, $this->dir . '/cache', 'model-a');
        $messages = [['role' => 'user', 'content' => 'سلام']];

        $first = $llm->complete($messages);
        $second = $llm->complete($messages);
        $llm->complete([['role' => 'user', 'content' => 'دیگر']]);

        $this->assertSame($first, $second);
        $this->assertSame(2, $inner->calls);
        $this->assertSame(1, $llm->hits);
    }

    public function test_verdicts_round_trip(): void
    {
        $store = new VerdictStore($this->dir . '/verdicts.json');
        $store->save(['k1' => new MutantVerdict('k1', 'equivalent', 'وزن همیشه مثبت است', 'DecrementInteger', 19)]);

        $loaded = $store->load();

        $this->assertSame('وزن همیشه مثبت است', $loaded['k1']->reason);
        $this->assertSame(19, $loaded['k1']->line);
    }

    public function test_stored_equivalent_mutants_are_not_sent_to_the_model_again(): void
    {
        $mutant = new Mutant('escaped', 'GreaterThanOrEqualTo', 'src/X.php', 26, "@@ @@\n-a\n+b\n");
        $key = MutantKey::of($mutant);
        $store = new VerdictStore($this->dir . '/verdicts.json');
        $store->save([$key => new MutantVerdict($key, 'equivalent', 'same output at the cap', 'GreaterThanOrEqualTo', 26)]);

        $file = $this->dir . '/T.php';
        file_put_contents($file, 'ORIGINAL');
        $llm = $this->counter();
        $runner = new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                return new RunResult(true, 'OK');
            }
        };
        $mutation = new class ($mutant) implements MutationRunner {
            public function __construct(private Mutant $mutant) {}

            public function run(string $testFile): MutationReport
            {
                return new MutationReport(4, 3, 1, 0, 0, 0, 75.0, 100, [$this->mutant]);
            }
        };

        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
        $result = (new TestImprover($llm, $runner, $mutation, store: $store))->improve($class, $file);

        $this->assertSame(0, $llm->calls);
        $this->assertCount(1, $result->equivalent);
        $this->assertSame([], $result->unresolved);
        $this->assertSame(100.0, $result->msiExcludingClaimedEquivalent());
    }
}
