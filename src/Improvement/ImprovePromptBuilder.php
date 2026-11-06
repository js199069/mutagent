<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Infection\Mutant;

final class ImprovePromptBuilder
{
    public function __construct(
        private ?string $phpunitVersion = null,
    ) {}

    /**
     * @param list<Mutant> $targets
     * @return list<array{role: string, content: string}>
     */
    public function messages(ClassInfo $class, string $testCode, array $targets): array
    {
        $list = [];
        foreach ($targets as $i => $mutant) {
            $list[] = sprintf("#%d (%s, line %d)\n%s", $i + 1, $mutant->mutator, $mutant->line, implode("\n", $mutant->changedLines()));
        }
        $mutants = implode("\n\n", $list);
        $version = $this->phpunitVersion !== null ? "PHPUnit {$this->phpunitVersion}" : 'a modern PHPUnit (10 or later)';

        $system = <<<TXT
        You are a senior PHP engineer who writes precise PHPUnit tests.
        The code under test is the source of truth: tests must pass against it as it is.
        TXT;

        $user = <<<TXT
        These mutants of {$class->fqcn} survive the current tests. A mutant is a small change to the source;
        a strong test suite fails for each one.

        For each mutant decide:
        - "kill": add or strengthen tests so that the original code passes and the mutated code would fail.
        - "equivalent": the change cannot alter observable behaviour for any valid input. Explain why in one sentence.
        Be honest: if you cannot find an input that distinguishes them, it is probably equivalent.
        Never write a test that passes only by asserting internals, or by expecting an error that cannot happen.

        Source:
        ```php
        {$class->source}
        ```

        Current test file ({$version}):
        ```php
        {$testCode}
        ```

        Surviving mutants:
        {$mutants}

        Reply with exactly two blocks:
        1. A ```json block: [{"mutant": 1, "verdict": "kill", "reason": "..."}, ...] covering every mutant.
        2. The complete updated test file in a single ```php block. Keep all existing tests.
           Use PHP attributes, never docblock annotations. If every mutant is equivalent, return the file unchanged.
        TXT;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
