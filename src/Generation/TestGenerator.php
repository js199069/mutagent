<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Generation;

use Js199069\Mutagent\Analysis\ClassInfo;
use Js199069\Mutagent\Editing\Salvager;
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

            file_put_contents($testFile, str_replace("\r\n", "\n", $code));
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
            $removed = (new Salvager($this->runner))->salvage($testFile, $lastOutput);
            if ($removed !== []) {
                return new GenerationResult(true, $maxAttempts, $testFile, $lastOutput, $removed);
            }
            rename($testFile, $testFile . '.failed');
        }

        return new GenerationResult(false, $maxAttempts, $testFile . '.failed', $lastOutput);
    }
}
