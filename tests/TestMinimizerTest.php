<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Improvement\TestMinimizer;
use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class TestMinimizerTest extends TestCase
{
    private const CODE = <<<'PHP'
    <?php

    namespace Tests\Generated;

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class XGeneratedTest extends TestCase
    {
        public function testA(): void
        {
            $this->assertTrue(true);
        }

        /** extra docs */
        #[DataProvider('cases')]
        public function testB(int $x): void
        {
            $this->assertSame($x, $x);
        }

        public static function cases(): array
        {
            return [[1], [2]];
        }

        public function testC(): void
        {
            $this->assertTrue(true);
        }
    }
    PHP;

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/mutagent-min-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->file, self::CODE);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function report(int $killed, array $killers): MutationReport
    {
        $mutants = [];
        foreach ($killers as $i => $test) {
            $mutants[] = new Mutant('killed', 'Plus', 'src/X.php', $i + 1, "@@ @@\n-a\n+b\n", [$test]);
        }

        return new MutationReport($killed, $killed, 0, 0, 0, 0, 100.0, 100, $mutants);
    }

    private function runner(): TestRunner
    {
        return new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                return new RunResult(true, 'OK');
            }
        };
    }

    private function mutation(MutationReport $after): MutationRunner
    {
        return new class ($after) implements MutationRunner {
            public function __construct(private MutationReport $after) {}

            public function run(string $testFile): MutationReport
            {
                return $this->after;
            }
        };
    }

    public function test_finds_test_methods_with_their_attributes_and_docs(): void
    {
        $methods = (new TestMinimizer($this->runner(), $this->mutation($this->report(0, []))))->testMethods(self::CODE);

        $this->assertSame(['testA', 'testB', 'testC'], array_keys($methods));
        $this->assertSame(15, $methods['testB'][0], 'testB starts at its doc comment');
    }

    public function test_removes_tests_that_kill_nothing_when_kills_are_kept(): void
    {
        $before = $this->report(2, ['testA', 'testB']);

        $result = (new TestMinimizer($this->runner(), $this->mutation($before)))->minimize($this->file, $before);

        $code = (string) file_get_contents($this->file);
        $this->assertSame(['testC'], $result->removed);
        $this->assertStringNotContainsString('testC', $code);
        $this->assertStringContainsString('function cases', $code, 'data providers stay');
        $this->assertNotNull((new ParserFactory())->createForHostVersion()->parse($code), 'still valid PHP');
    }

    public function test_reverts_when_a_kill_is_lost(): void
    {
        $before = $this->report(2, ['testA', 'testB']);

        $result = (new TestMinimizer($this->runner(), $this->mutation($this->report(1, ['testA']))))->minimize($this->file, $before);

        $this->assertSame([], $result->removed);
        $this->assertSame(self::CODE, file_get_contents($this->file));
    }

    public function test_does_nothing_without_kill_attribution(): void
    {
        $before = new MutationReport(2, 2, 0, 0, 0, 0, 100.0, 100, [new Mutant('killed', 'Plus', 'src/X.php', 1, '')]);

        $result = (new TestMinimizer($this->runner(), $this->mutation($before)))->minimize($this->file, $before);

        $this->assertSame([], $result->removed);
        $this->assertSame(self::CODE, file_get_contents($this->file));
    }
}
