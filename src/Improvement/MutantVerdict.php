<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

final readonly class MutantVerdict
{
    public function __construct(
        public string $key,
        public string $verdict,
        public string $reason,
        public string $mutator = '',
        public int $line = 0,
    ) {}

    public function isEquivalent(): bool
    {
        return $this->verdict === 'equivalent';
    }
}
