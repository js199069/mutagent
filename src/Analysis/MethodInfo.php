<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Analysis;

final readonly class MethodInfo
{
    public function __construct(
        public string $name,
        public string $signature,
    ) {}
}
