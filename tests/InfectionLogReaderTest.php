<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Infection\InfectionLogReader;
use Js199069\Mutagent\Infection\MutationReport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InfectionLogReaderTest extends TestCase
{
    private function report(): MutationReport
    {
        return (new InfectionLogReader())->fromFile(__DIR__ . '/Fixtures/infection-log.json');
    }

    public function test_reads_stats(): void
    {
        $report = $this->report();

        $this->assertSame(3, $report->total);
        $this->assertSame(2, $report->escaped);
        $this->assertSame(33.33, $report->msi);
        $this->assertSame(100.0, $report->mutationCodeCoverage);
    }

    public function test_reads_mutants_with_their_status(): void
    {
        $escaped = $this->report()->escapedMutants();

        $this->assertCount(2, $escaped);
        $this->assertSame('LessThanOrEqualTo', $escaped[0]->mutator);
        $this->assertSame(9, $escaped[0]->line);
        $this->assertCount(3, $this->report()->mutants);
    }

    public function test_changed_lines_skip_headers_warnings_and_crlf(): void
    {
        $escaped = $this->report()->escapedMutants();

        $this->assertSame(
            ['-        if ($weightKg <= 0) {', '+        if ($weightKg < 0) {'],
            $escaped[0]->changedLines(),
        );
        $this->assertSame(
            ['-        $cost = $base + $extraKg * 10000;', '+        $cost = $base - $extraKg * 10000;'],
            $escaped[1]->changedLines(),
        );
    }

    public function test_missing_groups_are_treated_as_empty(): void
    {
        $report = (new InfectionLogReader())->fromArray(['stats' => ['msi' => 0]]);

        $this->assertSame([], $report->mutants);
    }

    public function test_missing_file_fails_clearly(): void
    {
        $this->expectException(RuntimeException::class);

        (new InfectionLogReader())->fromFile('does-not-exist.json');
    }
}
