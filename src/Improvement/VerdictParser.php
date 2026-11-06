<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutantKey;

/** خواندن تصمیم مدل برای هر mutant از بلوک ```json. mutantی که تصمیمش نیامده، «kill» حساب می‌شود. */
final class VerdictParser
{
    /**
     * @param list<Mutant> $targets
     * @return array<string, MutantVerdict>
     */
    public function parse(string $response, array $targets): array
    {
        $verdicts = [];

        if (preg_match('/```json[ \t]*\R(.*?)```/s', $response, $m)) {
            $rows = json_decode(trim($m[1]), true);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $index = (int) ($row['mutant'] ?? 0) - 1;
                if (! is_array($row) || ! isset($targets[$index])) {
                    continue;
                }
                $mutant = $targets[$index];
                $key = MutantKey::of($mutant);
                $verdict = ($row['verdict'] ?? '') === 'equivalent' ? 'equivalent' : 'kill';
                $verdicts[$key] = new MutantVerdict($key, $verdict, trim((string) ($row['reason'] ?? '')), $mutant->mutator, $mutant->line);
            }
        }

        foreach ($targets as $mutant) {
            $key = MutantKey::of($mutant);
            $verdicts[$key] ??= new MutantVerdict($key, 'kill', '', $mutant->mutator, $mutant->line);
        }

        return $verdicts;
    }
}
