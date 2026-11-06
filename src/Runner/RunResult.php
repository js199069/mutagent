<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Runner;

final readonly class RunResult
{
    public function __construct(
        public bool $passed,
        public string $output,
    ) {}
}
