<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

final readonly class GenerationResult
{
    /** @param list<string> $removedFailing تست‌هایی که با کد مخالف ماندند و حذف شدند */
    public function __construct(
        public bool $passed,
        public int $attempts,
        public string $testFile,
        public string $lastOutput,
        public array $removedFailing = [],
    ) {}
}
