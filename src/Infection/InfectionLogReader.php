<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

use RuntimeException;

/** خواندن گزارش JSON اینفکشن (کلید logs.json در infection.json5). */
final class InfectionLogReader
{
    /** گروه‌هایی که در گزارش آمده‌اند؛ گروهی که نباشد، خالی حساب می‌شود */
    private const STATUSES = ['killed', 'escaped', 'uncovered', 'errored', 'syntaxErrors', 'timeouted', 'ignored'];

    public function fromFile(string $path): MutationReport
    {
        if (! is_file($path)) {
            throw new RuntimeException("Infection log not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $this->fromArray($data);
    }

    public function fromArray(array $data): MutationReport
    {
        $stats = $data['stats'] ?? [];
        $mutants = [];

        foreach (self::STATUSES as $status) {
            foreach ($data[$status] ?? [] as $item) {
                $mutator = $item['mutator'] ?? [];
                $mutants[] = new Mutant(
                    status: $status,
                    mutator: (string) ($mutator['mutatorName'] ?? ''),
                    filePath: (string) ($mutator['originalFilePath'] ?? ''),
                    line: (int) ($mutator['originalStartLine'] ?? 0),
                    // پایان خط ویندوز در diff، خروجی را شلوغ می‌کند
                    diff: str_replace("\r\n", "\n", (string) ($item['diff'] ?? '')),
                    failedTests: $this->failedTests((string) ($item['processOutput'] ?? '')),
                );
            }
        }

        return new MutationReport(
            total: (int) ($stats['totalMutantsCount'] ?? count($mutants)),
            killed: (int) ($stats['killedCount'] ?? 0),
            escaped: (int) ($stats['escapedCount'] ?? 0),
            notCovered: (int) ($stats['notCoveredCount'] ?? 0),
            errors: (int) ($stats['errorCount'] ?? 0),
            timeouts: (int) ($stats['timeOutCount'] ?? 0),
            msi: (float) ($stats['msi'] ?? 0),
            mutationCodeCoverage: (float) ($stats['mutationCodeCoverage'] ?? 0),
            mutants: $mutants,
        );
    }

    /** «1) Tests\Generated\FooTest::testBar with data set #2» ← testBar */
    private function failedTests(string $processOutput): array
    {
        preg_match_all('/^\d+\) \S+::(\w+)/m', str_replace("\r\n", "\n", $processOutput), $m);

        return array_values(array_unique($m[1]));
    }
}
