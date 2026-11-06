<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Tests;

use Js199069\Mutagent\Improvement\VerdictParser;
use Js199069\Mutagent\Infection\Mutant;
use Js199069\Mutagent\Infection\MutantKey;
use PHPUnit\Framework\TestCase;

final class VerdictParserTest extends TestCase
{
    private function mutant(int $line): Mutant
    {
        return new Mutant('escaped', 'Plus', 'src/X.php', $line, "@@ @@\n-a{$line}\n+b{$line}\n");
    }

    public function test_maps_verdicts_by_mutant_number(): void
    {
        $targets = [$this->mutant(1), $this->mutant(2)];
        $response = "```json\n[{\"mutant\": 1, \"verdict\": \"equivalent\", \"reason\": \"same output\"}, {\"mutant\": 2, \"verdict\": \"kill\"}]\n```";

        $verdicts = (new VerdictParser())->parse($response, $targets);

        $this->assertTrue($verdicts[MutantKey::of($targets[0])]->isEquivalent());
        $this->assertSame('same output', $verdicts[MutantKey::of($targets[0])]->reason);
        $this->assertFalse($verdicts[MutantKey::of($targets[1])]->isEquivalent());
    }

    public function test_missing_or_invalid_json_means_kill(): void
    {
        $targets = [$this->mutant(1)];

        $verdicts = (new VerdictParser())->parse('no json here', $targets);

        $this->assertFalse($verdicts[MutantKey::of($targets[0])]->isEquivalent());
    }
}
