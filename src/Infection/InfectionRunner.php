<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Infection;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Infection را فقط با یک فایل تست و فقط روی یک کلاس اجرا می‌کند، با پیکربندی جدا در .mutagent/
 * تا تست‌های دیگر پروژه، سنجش تست تولیدشده را آلوده نکنند.
 */
final class InfectionRunner implements MutationRunner
{
    private const LOG = 'mutagent-infection-log.json';

    public function __construct(
        private string $projectDir,
        private string $classFile,
        private string $phpBinary = PHP_BINARY,
        private int $threads = 4,
        private int $timeout = 900,
    ) {}

    public function run(string $testFile): MutationReport
    {
        $dir = $this->writeConfig($testFile);
        $candidates = [$dir . '/' . self::LOG, $this->root() . self::LOG];
        foreach ($candidates as $old) {
            @unlink($old);
        }

        $process = new Process(
            [
                $this->phpBinary, 'vendor/bin/infection',
                '--filter=' . $this->relative($this->classFile),
                '--with-uncovered',
                '--configuration=.mutagent/infection.json5',
                '--threads=' . $this->threads,
                '--no-progress',
                '--no-interaction',
            ],
            $this->projectDir,
            ['XDEBUG_MODE' => 'coverage'],
            timeout: $this->timeout,
        );
        $process->run();

        foreach ($candidates as $log) {
            if (is_file($log)) {
                return (new InfectionLogReader())->fromFile($log);
            }
        }

        // بخش مفید خطا (خروجی PHPUnit) در انتهاست
        $output = $process->getOutput() . $process->getErrorOutput();
        throw new RuntimeException("Infection did not produce a log. Output (tail):\n" . mb_substr($output, -4000));
    }

    public function writeConfig(string $testFile): string
    {
        $dir = $this->root() . '.mutagent';
        @mkdir($dir, 0777, true);
        $test = $this->relative($testFile);
        $class = $this->relative($this->classFile);

        file_put_contents($dir . '/phpunit.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="../vendor/autoload.php" colors="false">
            <testsuites>
                <testsuite name="mutagent">
                    <file>../{$test}</file>
                </testsuite>
            </testsuites>
            <source>
                <include>
                    <file>../{$class}</file>
                </include>
            </source>
        </phpunit>
        XML);

        $config = [
            'source' => ['directories' => ['../' . dirname($class)]],
            'phpUnit' => ['configDir' => '.'],
            'logs' => ['json' => self::LOG],
            'tmpDir' => 'tmp',
            'mutators' => ['@default' => true],
        ];
        file_put_contents($dir . '/infection.json5', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }

    public function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root());

        return str_starts_with(strtolower($path), strtolower($root)) ? substr($path, strlen($root)) : ltrim($path, '/');
    }

    private function root(): string
    {
        return rtrim($this->projectDir, '/\\') . '/';
    }
}
