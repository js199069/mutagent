<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Improvement;

use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Editing\Salvager;
use Js199069\Mutagent\Editing\TestFileMerger;
use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Infection\MutantKey;
use Js199069\Mutagent\Infection\MutationRunner;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\TestRunner;
use RuntimeException;

/**
 * حلقه‌ی mutation: mutantهای زنده ← مدل (kill یا equivalent) ← تست تازه، که باید روی کد اصلی سبز باشد ← Infection دوباره.
 * مدل فقط متدهای تازه را می‌فرستد و ادغام می‌شوند (صرفه‌جویی در توکن خروجی).
 * اگر Infection در شروع به‌خاطر چند تست قرمز اجرا نشود، همان‌ها حذف می‌شوند (نجات) و دوباره امتحان می‌شود.
 * هر mutant حداکثر maxAttemptsPerMutant بار هدف قرار می‌گیرد؛ نامزد قرمز یا ردشده برگردانده می‌شود.
 * تصمیم‌های «هم‌ارز» در VerdictStore می‌مانند تا اجرای بعدی دوباره برایشان توکن خرج نکند.
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
        private int $maxAttemptsPerMutant = 2,
        private int $repairAttempts = 2,
        private ?VerdictStore $store = null,
        private TestFileMerger $merger = new TestFileMerger(),
    ) {}

    public function improve(ClassInfo $class, string $testFile, int $rounds = 3, ?callable $onRound = null): ImprovementResult
    {
        $removedAtStart = [];
        try {
            $report = $this->mutation->run($testFile);
        } catch (RuntimeException $e) {
            // Infection با اولین شکست متوقف می‌شود؛ فهرست کامل تست‌های قرمز را از یک اجرای کامل PHPUnit بگیر
            $full = $this->runner->run($testFile);
            $removedAtStart = $full->passed ? [] : (new Salvager($this->runner))->salvage($testFile, $full->output);
            if ($removedAtStart === []) {
                throw $e;
            }
            $report = $this->mutation->run($testFile);
        }

        $current = (string) file_get_contents($testFile);
        $initial = $report;
        $onRound && $onRound(0, $report);

        $equivalent = $this->store?->load() ?? [];
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
            $this->store?->save($equivalent);

            if ($toKill > 0) {
                $candidate = $this->candidate($response, $messages, $testFile, $current);

                if ($candidate === null) {
                    file_put_contents($testFile, $current);
                    $rejected++;
                } else {
                    try {
                        $report = $this->mutation->run($testFile);
                        $current = $candidate;
                    } catch (RuntimeException) {
                        file_put_contents($testFile, $current);
                        $rejected++;
                    }
                }
            }

            $onRound && $onRound($round, $report);
        }

        $escapedKeys = array_map(MutantKey::of(...), $report->escapedMutants());
        $relevantEquivalent = array_values(array_filter($equivalent, fn ($v) => in_array($v->key, $escapedKeys, true)));
        $unresolved = array_values(array_filter($report->escapedMutants(), fn ($m) => ! isset($equivalent[MutantKey::of($m)])));

        return new ImprovementResult($initial, $report, $relevantEquivalent, $unresolved, $roundsRun, $rejected, $removedAtStart);
    }

    private function candidate(string $response, array $messages, string $testFile, string $current): ?string
    {
        $base = $current;

        for ($i = 0; $i <= $this->repairAttempts; $i++) {
            try {
                $code = $this->merger->apply($base, $response);
                file_put_contents($testFile, $code);
                $result = $this->runner->run($testFile);
                if ($result->passed) {
                    return $code;
                }
                $output = $result->output;
                $base = $code;
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
