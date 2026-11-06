<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Runner;

use Symfony\Component\Process\Process;

/**
 * اجرای یک فایل تست با PHPUnit خود پروژه‌ی هدف.
 * با ترتیب تصادفی، چون Infection هم همین کار را می‌کند؛ تست وابسته به ترتیب باید همین‌جا رد شود.
 */
final class PhpUnitRunner implements TestRunner
{
    public function __construct(
        private string $projectDir,
        private string $phpBinary = PHP_BINARY,
        private int $timeout = 300,
    ) {}

    public function run(string $testFile): RunResult
    {
        $process = new Process(
            [$this->phpBinary, 'vendor/bin/phpunit', '--colors=never', '--order-by=random', $testFile],
            $this->projectDir,
            timeout: $this->timeout,
        );
        $process->run();

        return new RunResult($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
    }

    /** نسخه‌ی PHPUnit پروژه‌ی هدف، مثلاً «12.5»؛ اگر پیدا نشد null */
    public function version(): ?string
    {
        $process = new Process([$this->phpBinary, 'vendor/bin/phpunit', '--version'], $this->projectDir, timeout: 60);
        $process->run();

        return preg_match('/PHPUnit (\d+\.\d+)/', $process->getOutput(), $m) ? $m[1] : null;
    }
}
