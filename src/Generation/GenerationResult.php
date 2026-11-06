<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

final readonly class GenerationResult
{
    public function __construct(
        public bool $passed,
        public int $attempts,
        public string $testFile,
        public string $lastOutput,
    ) {}
}
