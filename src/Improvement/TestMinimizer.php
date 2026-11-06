<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Runner\TestRunner;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * تست‌هایی که هیچ mutantی نکشته‌اند، حذف می‌شوند؛ فقط اگر فایل کوچک‌شده سبز بماند
 * و Infection همان تعداد mutant را بکشد. وگرنه همه‌چیز برمی‌گردد.
 */
final class TestMinimizer
{
    public function __construct(
        private TestRunner $runner,
        private MutationRunner $mutation,
    ) {}

    public function minimize(string $testFile, MutationReport $report): MinimizationResult
    {
        $code = (string) file_get_contents($testFile);
        $methods = $this->testMethods($code);

        $killers = [];
        foreach ($report->mutants as $mutant) {
            if ($mutant->status === 'killed') {
                foreach ($mutant->failedTests as $test) {
                    $killers[$test] = true;
                }
            }
        }

        if ($killers === []) {
            return new MinimizationResult([], $report, 'no kill attribution in the Infection log');
        }

        $unused = array_values(array_diff(array_keys($methods), array_keys($killers)));
        if ($unused === []) {
            return new MinimizationResult([], $report, 'every test kills at least one mutant');
        }
        if (count($unused) === count($methods)) {
            return new MinimizationResult([], $report, 'refusing to remove every test');
        }

        file_put_contents($testFile, $this->remove($code, array_intersect_key($methods, array_flip($unused))));

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

    /** @return array<string, array{int, int}> نام متد تست ← [خط شروع، خط پایان]، با attributeها و توضیح بالایش */
    public function testMethods(string $code): array
    {
        $ast = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $class = (new NodeFinder())->findFirstInstanceOf($ast, Class_::class);
        if (! $class instanceof Class_) {
            return [];
        }

        $methods = [];
        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();
            if (! $method->isPublic() || ! (str_starts_with($name, 'test') || $this->hasTestAttribute($method))) {
                continue;
            }

            $start = $method->getStartLine();
            foreach ($method->attrGroups as $group) {
                $start = min($start, $group->getStartLine());
            }
            if ($doc = $method->getDocComment()) {
                $start = min($start, $doc->getStartLine());
            }

            $methods[$name] = [$start, $method->getEndLine()];
        }

        return $methods;
    }

    private function hasTestAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }

    /** حذف بر اساس شماره‌ی خط، تا قالب‌بندی بقیه‌ی فایل دست نخورد */
    private function remove(string $code, array $ranges): string
    {
        $lines = preg_split('/\R/', $code);
        uasort($ranges, fn ($a, $b) => $b[0] <=> $a[0]);

        foreach ($ranges as [$start, $end]) {
            array_splice($lines, $start - 1, $end - $start + 1);
        }

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    }
}
