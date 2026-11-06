<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

interface MutationRunner
{
    public function run(string $testFile): MutationReport;
}
