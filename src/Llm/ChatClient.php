<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Llm;

interface ChatClient
{
    /** @param list<array{role: string, content: string}> $messages */
    public function complete(array $messages): string;
}
