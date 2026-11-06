<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

final readonly class MutationReport
{
    /** @param list<Mutant> $mutants */
    public function __construct(
        public int $total,
        public int $killed,
        public int $escaped,
        public int $notCovered,
        public int $errors,
        public int $timeouts,
        public float $msi,
        public float $mutationCodeCoverage,
        public array $mutants,
    ) {}

    /** @return list<Mutant> */
    public function escapedMutants(): array
    {
        return array_values(array_filter($this->mutants, fn (Mutant $m) => $m->status === 'escaped'));
    }
}
