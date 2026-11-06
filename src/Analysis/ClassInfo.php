<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Analysis;

final readonly class ClassInfo
{
    /** @param list<MethodInfo> $publicMethods */
    public function __construct(
        public string $fqcn,
        public string $shortName,
        public string $filePath,
        public string $source,
        public array $publicMethods,
    ) {}
}
