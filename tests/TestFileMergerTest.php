<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Editing\TestFileMerger;
use Js199069\Mutagent\Improvement\TestImprover;
use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\RunResult;
use Js199069\Mutagent\Runner\TestRunner;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class TestFileMergerTest extends TestCase
{
    private const FILE = <<<'PHP'
    <?php

    namespace Tests\Generated;

    use PHPUnit\Framework\TestCase;

    final class XGeneratedTest extends TestCase
    {
        public function testOne(): void
        {
            $this->assertSame(1, 1);
        }

        public function testTwo(): void
        {
            $this->assertSame(2, 2);
        }
    }

    PHP;

    public function test_new_methods_are_appended_and_imports_added_once(): void
    {
        $snippet = "use PHPUnit\\Framework\\Attributes\\DataProvider;\nuse PHPUnit\\Framework\\TestCase;\n\npublic function testThree(): void\n{\n    \$this->assertSame(3, 3);\n}";

        $merged = (new TestFileMerger())->merge(self::FILE, $snippet);

        $this->assertStringContainsString('function testOne', $merged);
        $this->assertStringContainsString('function testThree', $merged);
        $this->assertSame(1, substr_count($merged, 'use PHPUnit\Framework\TestCase;'));
        $this->assertStringContainsString('use PHPUnit\Framework\Attributes\DataProvider;', $merged);
        $this->assertNotNull((new ParserFactory())->createForHostVersion()->parse($merged));
    }

    public function test_a_method_with_an_existing_name_replaces_it(): void
    {
        $merged = (new TestFileMerger())->merge(self::FILE, "    public function testTwo(): void\n    {\n        \$this->assertSame(22, 22);\n    }");

        $this->assertSame(1, substr_count($merged, 'function testTwo'));
        $this->assertStringContainsString('assertSame(22, 22)', $merged);
        $this->assertStringNotContainsString('assertSame(2, 2)', $merged);
    }

    public function test_a_full_class_in_the_response_replaces_the_file(): void
    {
        $response = "```php\n<?php\n\nfinal class Other {}\n```";

        $this->assertSame("<?php\n\nfinal class Other {}\n", (new TestFileMerger())->apply(self::FILE, $response));
    }

    public function test_broken_snippet_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);

        (new TestFileMerger())->merge(self::FILE, 'public function testBroken( { ');
    }

    public function test_improver_merges_a_methods_only_reply(): void
    {
        $file = sys_get_temp_dir() . '/mutagent-merge-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, self::FILE);
        $mutant = new Mutant('escaped', 'Plus', 'src/X.php', 1, "@@ @@\n-a\n+b\n");

        $llm = new class implements ChatClient {
            public function complete(array $messages): string
            {
                return "```json\n[{\"mutant\": 1, \"verdict\": \"kill\"}]\n```\n```php\n    public function testKillsPlus(): void\n    {\n        \$this->assertSame(5, 5);\n    }\n```";
            }
        };
        $runner = new class implements TestRunner {
            public function run(string $testFile): RunResult
            {
                return new RunResult(true, 'OK');
            }
        };
        $mutation = new class ($mutant) implements MutationRunner {
            private int $calls = 0;

            public function __construct(private Mutant $mutant) {}

            public function run(string $testFile): MutationReport
            {
                return ++$this->calls === 1
                    ? new MutationReport(2, 1, 1, 0, 0, 0, 50.0, 100, [$this->mutant])
                    : new MutationReport(2, 2, 0, 0, 0, 0, 100.0, 100, []);
            }
        };

        $class = (new ClassAnalyzer())->analyze(__DIR__ . '/Fixtures/ShippingCalculator.php');
        $result = (new TestImprover($llm, $runner, $mutation))->improve($class, $file, rounds: 1);

        $code = (string) file_get_contents($file);
        $this->assertStringContainsString('function testOne', $code);
        $this->assertStringContainsString('function testKillsPlus', $code);
        $this->assertSame(100.0, $result->final->msi);

        @unlink($file);
    }
}
