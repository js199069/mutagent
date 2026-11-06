<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

/** یک mutant از گزارش Infection. کد کامل فایل نگه داشته نمی‌شود؛ diff برای LLM کافی است. */
final readonly class Mutant
{
    /** @param list<string> $failedTests متدهای تستی که این mutant را کشتند (از خروجی PHPUnit) */
    public function __construct(
        public string $status,
        public string $mutator,
        public string $filePath,
        public int $line,
        public string $diff,
        public array $failedTests = [],
    ) {}

    /** فقط خط‌های تغییرکرده (- و +)، بدون سربرگ‌ها و هشدارهای diff */
    public function changedLines(): array
    {
        $lines = [];
        foreach (explode("\n", $this->diff) as $line) {
            if ($line === '' || str_starts_with($line, '@@') || str_starts_with($line, '#')) {
                continue;
            }
            if ($line[0] === '-' || $line[0] === '+') {
                $lines[] = rtrim($line);
            }
        }

        return $lines;
    }
}
