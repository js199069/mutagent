<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Infection\MutationReport;

final readonly class MinimizationResult
{
    /** @param list<string> $removed */
    public function __construct(
        public array $removed,
        public MutationReport $report,
        public ?string $note = null,
    ) {}
}
