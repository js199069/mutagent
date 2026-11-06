<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutationReport;

final readonly class ImprovementResult
{
    /**
     * @param list<MutantVerdict> $equivalent
     * @param list<Mutant> $unresolved
     * @param list<string> $removedAtStart تست‌هایی که Infection را در شروع متوقف می‌کردند و حذف شدند
     */
    public function __construct(
        public MutationReport $initial,
        public MutationReport $final,
        public array $equivalent,
        public array $unresolved,
        public int $rounds,
        public int $rejectedCandidates = 0,
        public array $removedAtStart = [],
    ) {}

    /** MSI وقتی mutantهایی که مدل هم‌ارز دانسته از مخرج کنار گذاشته شوند (ادعا، نه اثبات) */
    public function msiExcludingClaimedEquivalent(): float
    {
        $denominator = $this->final->total - count($this->equivalent);

        return $denominator > 0 ? round(100 * $this->final->killed / $denominator, 2) : 100.0;
    }
}
