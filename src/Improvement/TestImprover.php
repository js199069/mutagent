<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Generation\CodeExtractor;
use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Infection\MutantKey;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\TestRunner;
use RuntimeException;

/**
 * حلقه‌ی mutation: mutantهای زنده ← مدل (kill یا equivalent) ← تست تازه، که باید روی کد اصلی سبز باشد ← Infection دوباره.
 * هر mutant حداکثر maxAttemptsPerMutant بار هدف قرار می‌گیرد.
 * نامزدی که قرمز است، یا Infection نمی‌پذیردش (مثلاً وابسته به ترتیب اجرا)، کنار گذاشته و فایل قبلی برگردانده می‌شود.
 */
final class TestImprover
{
    public function __construct(
        private ChatClient $llm,
        private TestRunner $runner,
        private MutationRunner $mutation,
        private ImprovePromptBuilder $prompts = new ImprovePromptBuilder(),
        private PromptBuilder $fixPrompts = new PromptBuilder(),
        private VerdictParser $parser = new VerdictParser(),
        private CodeExtractor $extractor = new CodeExtractor(),
        private int $maxAttemptsPerMutant = 2,
        private int $repairAttempts = 2,
    ) {}

    public function improve(ClassInfo $class, string $testFile, int $rounds = 3, ?callable $onRound = null): ImprovementResult
    {
        $current = (string) file_get_contents($testFile);
        $report = $this->mutation->run($testFile);
        $initial = $report;
        $onRound && $onRound(0, $report);

        $equivalent = [];
        $attempts = [];
        $roundsRun = 0;
        $rejected = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            $targets = array_values(array_filter(
                $report->escapedMutants(),
                fn ($m) => ! isset($equivalent[MutantKey::of($m)]) && ($attempts[MutantKey::of($m)] ?? 0) < $this->maxAttemptsPerMutant,
            ));
            if ($targets === []) {
                break;
            }
            $roundsRun = $round;

            $messages = $this->prompts->messages($class, $current, $targets);
            $response = $this->llm->complete($messages);

            $toKill = 0;
            foreach ($this->parser->parse($response, $targets) as $key => $verdict) {
                if ($verdict->isEquivalent()) {
                    $equivalent[$key] = $verdict;
                } else {
                    $attempts[$key] = ($attempts[$key] ?? 0) + 1;
                    $toKill++;
                }
            }

            if ($toKill > 0) {
                $candidate = $this->candidate($response, $messages, $testFile);

                if ($candidate === null) {
                    // نامزد روی کد اصلی سبز نشد؛ گزارش قبلی همچنان معتبر است
                    file_put_contents($testFile, $current);
                    $rejected++;
                } else {
                    try {
                        $report = $this->mutation->run($testFile);
                        $current = $candidate;
                    } catch (RuntimeException) {
                        // سبز بود، ولی Infection نپذیرفت (مثلاً وابستگی به ترتیب اجرا): فایل سالم قبلی برمی‌گردد
                        file_put_contents($testFile, $current);
                        $rejected++;
                    }
                }
            }

            $onRound && $onRound($round, $report);
        }

        $unresolved = array_values(array_filter(
            $report->escapedMutants(),
            fn ($m) => ! isset($equivalent[MutantKey::of($m)]),
        ));

        return new ImprovementResult($initial, $report, array_values($equivalent), $unresolved, $roundsRun, $rejected);
    }

    /** فایل تازه را می‌نویسد و تا سبز شدن روی کد اصلی تعمیر می‌کند؛ اگر نشد null */
    private function candidate(string $response, array $messages, string $testFile): ?string
    {
        for ($i = 0; $i <= $this->repairAttempts; $i++) {
            try {
                $code = $this->extractor->extract($response);
                file_put_contents($testFile, $code);
                $result = $this->runner->run($testFile);
                if ($result->passed) {
                    return $code;
                }
                $output = $result->output;
            } catch (RuntimeException $e) {
                $output = $e->getMessage();
            }

            if ($i === $this->repairAttempts) {
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $response];
            $messages[] = $this->fixPrompts->fix($output);
            $response = $this->llm->complete($messages);
        }

        return null;
    }
}
