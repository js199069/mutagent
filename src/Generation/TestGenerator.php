<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Editing\TestMethodEditor;
use Js199069\Mutagent\Llm\ChatClient;
use Js199069\Mutagent\Runner\TestRunner;
use RuntimeException;

/**
 * بنویس، اجرا کن، اگر قرمز شد خروجی PHPUnit را به مدل برگردان؛ تا maxAttempts بار.
 * اگر در آخر فقط چند تست با کد مخالف‌اند، همان‌ها حذف می‌شوند و بقیه می‌مانند (کد مرجع است).
 * اگر نشد، فایل با پسوند .failed کنار گذاشته می‌شود تا تست‌های پروژه را خراب نکند.
 */
final class TestGenerator
{
    public function __construct(
        private ChatClient $llm,
        private TestRunner $runner,
        private PromptBuilder $prompts = new PromptBuilder(),
        private CodeExtractor $extractor = new CodeExtractor(),
        private TestMethodEditor $editor = new TestMethodEditor(),
        private float $maxSalvageShare = 0.3,
    ) {}

    public function generate(ClassInfo $class, string $testFile, int $maxAttempts = 3, ?callable $onAttempt = null): GenerationResult
    {
        $messages = $this->prompts->initial($class);
        $lastOutput = '';
        $wroteCode = false;
        @mkdir(dirname($testFile), 0777, true);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->llm->complete($messages);

            try {
                $code = $this->extractor->extract($response);
            } catch (RuntimeException $e) {
                $lastOutput = $e->getMessage();
                $messages[] = ['role' => 'assistant', 'content' => $response];
                $messages[] = $this->prompts->fix($lastOutput);
                $onAttempt && $onAttempt($attempt, false, $lastOutput);

                continue;
            }

            file_put_contents($testFile, $code);
            $wroteCode = true;
            $result = $this->runner->run($testFile);
            $lastOutput = $result->output;
            $onAttempt && $onAttempt($attempt, $result->passed, $lastOutput);

            if ($result->passed) {
                return new GenerationResult(true, $attempt, $testFile, $lastOutput);
            }

            $messages[] = ['role' => 'assistant', 'content' => $response];
            $messages[] = $this->prompts->fix($lastOutput);
        }

        if ($wroteCode && is_file($testFile)) {
            $salvaged = $this->salvage($testFile, $lastOutput);
            if ($salvaged !== null) {
                return new GenerationResult(true, $maxAttempts, $testFile, $salvaged['output'], $salvaged['removed']);
            }
            rename($testFile, $testFile . '.failed');
        }

        return new GenerationResult(false, $maxAttempts, $testFile . '.failed', $lastOutput);
    }

    /** @return array{removed: list<string>, output: string}|null */
    private function salvage(string $testFile, string $phpunitOutput): ?array
    {
        $failing = TestMethodEditor::failingTests($phpunitOutput);
        $code = (string) file_get_contents($testFile);
        $methods = $this->editor->testMethods($code);

        $failing = array_values(array_intersect($failing, array_keys($methods)));
        $limit = max(1, (int) floor($this->maxSalvageShare * count($methods)));
        if ($failing === [] || count($failing) > $limit || count($failing) >= count($methods)) {
            return null;
        }

        file_put_contents($testFile, $this->editor->remove($code, $failing));
        $result = $this->runner->run($testFile);

        if (! $result->passed) {
            file_put_contents($testFile, $code);

            return null;
        }

        return ['removed' => $failing, 'output' => $result->output];
    }
}
