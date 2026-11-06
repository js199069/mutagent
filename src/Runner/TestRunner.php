<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Runner;

interface TestRunner
{
    public function run(string $testFile): RunResult;
}
