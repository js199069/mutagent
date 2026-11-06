<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Infection\InfectionLogReader;
use Js199069\Mutagent\Llm\Usage;
use PHPUnit\Framework\TestCase;

final class KillAttributionTest extends TestCase
{
    public function test_failed_test_names_are_read_from_process_output(): void
    {
        $report = (new InfectionLogReader())->fromArray([
            'stats' => ['killedCount' => 1],
            'killed' => [[
                'mutator' => ['mutatorName' => 'Plus', 'originalFilePath' => 'src/X.php', 'originalStartLine' => 3],
                'diff' => '',
                'processOutput' => "There was 1 failure:\r\n\r\n1) Tests\\Generated\\XGeneratedTest::testCost with data set #2 (1.5, 'tehran')\r\nFailed asserting",
            ]],
        ]);

        $this->assertSame(['testCost'], $report->mutants[0]->failedTests);
    }

    public function test_usage_is_summed_over_requests(): void
    {
        $usage = new Usage();
        $usage->add(['prompt_tokens' => 100, 'completion_tokens' => 20]);
        $usage->add(['prompt_tokens' => 50, 'completion_tokens' => 5]);

        $this->assertSame('LLM: 2 request(s), 150 prompt + 25 completion tokens', $usage->summary());
    }
}
