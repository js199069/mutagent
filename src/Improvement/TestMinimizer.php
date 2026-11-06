<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Editing\TestMethodEditor;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Runner\TestRunner;
use RuntimeException;

/**
 * تست‌هایی که هیچ mutantی نکشته‌اند را پیدا می‌کند. «بی‌اثر برای mutation» یعنی «بی‌ارزش» نیست؛ پس پیش‌فرض فقط گزارش است.
 * حذف واقعی فقط اگر فایل سبز بماند و Infection همان تعداد mutant را بکشد؛ وگرنه برمی‌گردد.
 */
final class TestMinimizer
{
    public function __construct(
        private TestRunner $runner,
        private MutationRunner $mutation,
        private TestMethodEditor $editor = new TestMethodEditor(),
    ) {}

    /** @return array{0: list<string>, 1: ?string} */
    public function redundant(string $testFile, MutationReport $report): array
    {
        $methods = $this->editor->testMethods((string) file_get_contents($testFile));

        $killers = [];
        foreach ($report->mutants as $mutant) {
            if ($mutant->status === 'killed') {
                foreach ($mutant->failedTests as $test) {
                    $killers[$test] = true;
                }
            }
        }

        if ($killers === []) {
            return [[], 'no kill attribution in the Infection log'];
        }

        $unused = array_values(array_diff(array_keys($methods), array_keys($killers)));
        if ($unused === []) {
            return [[], 'every test kills at least one mutant'];
        }
        if (count($unused) === count($methods)) {
            return [[], 'refusing to treat every test as redundant'];
        }

        return [$unused, null];
    }

    public function minimize(string $testFile, MutationReport $report): MinimizationResult
    {
        [$unused, $note] = $this->redundant($testFile, $report);
        if ($unused === []) {
            return new MinimizationResult([], $report, $note);
        }

        $code = (string) file_get_contents($testFile);
        file_put_contents($testFile, $this->editor->remove($code, $unused));

        if (! $this->runner->run($testFile)->passed) {
            file_put_contents($testFile, $code);

            return new MinimizationResult([], $report, 'minimized file does not pass; reverted');
        }

        try {
            $after = $this->mutation->run($testFile);
        } catch (RuntimeException) {
            file_put_contents($testFile, $code);

            return new MinimizationResult([], $report, 'Infection rejected the minimized file; reverted');
        }

        if ($after->killed < $report->killed) {
            file_put_contents($testFile, $code);

            return new MinimizationResult([], $report, sprintf('removing tests lost %d kill(s); reverted', $report->killed - $after->killed));
        }

        return new MinimizationResult($unused, $after);
    }

    /** @return array<string, array{int, int}> */
    public function testMethods(string $code): array
    {
        return $this->editor->testMethods($code);
    }
}
