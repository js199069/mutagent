<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Llm;

/** جمع مصرف یک اجرا؛ برای ستون هزینه‌ی بنچمارک */
final class Usage
{
    public int $requests = 0;

    public int $promptTokens = 0;

    public int $completionTokens = 0;

    public function add(array $usage): void
    {
        $this->requests++;
        $this->promptTokens += (int) ($usage['prompt_tokens'] ?? 0);
        $this->completionTokens += (int) ($usage['completion_tokens'] ?? 0);
    }

    public function summary(): string
    {
        return sprintf('LLM: %d request(s), %d prompt + %d completion tokens', $this->requests, $this->promptTokens, $this->completionTokens);
    }
}
