<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use Js199069\Mutagent\Analysis\ClassInfo;

final class PromptBuilder
{
    public function __construct(
        private string $testNamespace = 'Tests\\Generated',
        private ?string $phpunitVersion = null,
        private RepairHints $hints = new RepairHints(),
    ) {}

    public function testClassName(ClassInfo $class): string
    {
        return $class->shortName . 'GeneratedTest';
    }

    /** @return list<array{role: string, content: string}> */
    public function initial(ClassInfo $class): array
    {
        $methods = implode("\n", array_map(fn ($m) => '- ' . $m->signature, $class->publicMethods));
        $version = $this->phpunitVersion !== null ? "PHPUnit {$this->phpunitVersion}" : 'a modern PHPUnit (10 or later)';

        $system = <<<TXT
        You are a senior PHP engineer who writes precise PHPUnit tests.
        The code under test is the source of truth: tests must pass against it as it is.
        Your goal is to catch regressions: if anyone changes the logic, at least one test should fail.
        TXT;

        $user = <<<TXT
        Write tests for this class, for {$version}.

        Class: {$class->fqcn}
        Public methods:
        {$methods}

        Source:
        ```php
        {$class->source}
        ```

        Requirements:
        - Namespace: {$this->testNamespace}
        - Class: final class {$this->testClassName($class)} extends \PHPUnit\Framework\TestCase
        - Use PHP attributes, never docblock annotations (PHPUnit 10+ ignores @dataProvider, @test, @covers).
          For data providers: #[\PHPUnit\Framework\Attributes\DataProvider('name')] and a public static provider method.
        - Test only public behaviour. Use exact expected values with assertSame.
        - Compute every expected value by tracing the source step by step, including rounding and integer casts.
        - Cover boundaries (just below, at, and just above each threshold), every branch, and every exception (expectException).
        - Do not use mocks unless the constructor requires collaborators.
        - Return only the complete PHP file, in a single ```php code block.
        TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /** @return array{role: string, content: string} */
    public function fix(string $phpunitOutput): array
    {
        $output = mb_substr(self::stable($phpunitOutput), 0, 4000);
        $hints = $this->hints->for($phpunitOutput);
        $hintText = $hints === [] ? '' : "\nLikely cause:\n- " . implode("\n- ", $hints) . "\n";

        return [
            'role' => 'user',
            'content' => <<<TXT
            The tests did not pass. PHPUnit output:
            ````
            {$output}
            ````
            {$hintText}
            The code under test is correct by definition: fix the tests, not the code.
            Return the complete corrected PHP file, in a single ```php code block.
            TXT,
        ];
    }

    /**
     * خط‌هایی از خروجی PHPUnit که هر بار عوض می‌شوند (seed تصادفی، زمان، حافظه، مسیر پیکربندی) حذف می‌شوند:
     * هم برای مدل بی‌فایده‌اند، هم نمی‌گذارند پیام تعمیر از کش بیاید.
     */
    public static function stable(string $phpunitOutput): string
    {
        $text = str_replace("\r\n", "\n", $phpunitOutput);
        $text = (string) preg_replace('/^(PHPUnit \d.*by Sebastian Bergmann.*|Runtime:.*|Configuration:.*|Random Seed:.*|Time: .*Memory:.*)\n?/m', '', $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
