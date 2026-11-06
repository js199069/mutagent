<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

/**
 * تصمیم‌های «هم‌ارز» بین اجراها؛ mutantی که یک بار هم‌ارز تشخیص داده شد، دوباره به مدل فرستاده نمی‌شود.
 * اگر کد عوض شود، diff و در نتیجه کلید mutant عوض می‌شود، پس تصمیم کهنه خودبه‌خود بی‌اثر است.
 */
final class VerdictStore
{
    public function __construct(
        private string $path,
    ) {}

    /** @return array<string, MutantVerdict> */
    public function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $verdicts = [];
        foreach (json_decode((string) file_get_contents($this->path), true) ?: [] as $row) {
            $verdict = new MutantVerdict(
                (string) $row['key'], (string) $row['verdict'], (string) ($row['reason'] ?? ''),
                (string) ($row['mutator'] ?? ''), (int) ($row['line'] ?? 0),
            );
            $verdicts[$verdict->key] = $verdict;
        }

        return $verdicts;
    }

    /** @param array<string, MutantVerdict> $verdicts */
    public function save(array $verdicts): void
    {
        @mkdir(dirname($this->path), 0777, true);
        $rows = array_map(fn (MutantVerdict $v) => [
            'key' => $v->key, 'verdict' => $v->verdict, 'reason' => $v->reason, 'mutator' => $v->mutator, 'line' => $v->line,
        ], array_values($verdicts));

        file_put_contents($this->path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
