<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Console;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Generation\TestGenerator;
use Js199069\Mutagent\Llm\OpenAiCompatibleClient;
use Js199069\Mutagent\Runner\PhpUnitRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'generate', description: 'Write PHPUnit tests for a class with an LLM, and keep fixing them until they pass')]
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('class-file', InputArgument::REQUIRED, 'Path to the class, relative to the project')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root (where vendor/bin/phpunit is)', getcwd())
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory, relative to the project', 'tests/Generated')
            ->addOption('attempts', null, InputOption::VALUE_REQUIRED, 'Maximum generate/fix attempts', '3')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model name (overrides MUTAGENT_LLM_MODEL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = rtrim((string) $input->getOption('project'), '/\\');
        $classFile = $project . DIRECTORY_SEPARATOR . $input->getArgument('class-file');

        $class = (new ClassAnalyzer())->analyze($classFile);
        $io->writeln("Class: <info>{$class->fqcn}</info> — " . count($class->publicMethods) . ' public method(s)');

        $runner = new PhpUnitRunner($project);
        $version = $runner->version();
        $io->writeln('PHPUnit: <info>' . ($version ?? 'unknown') . '</info>');

        $prompts = new PromptBuilder(phpunitVersion: $version);
        $testFile = $project . DIRECTORY_SEPARATOR . $input->getOption('out') . DIRECTORY_SEPARATOR . $prompts->testClassName($class) . '.php';

        $llm = OpenAiCompatibleClient::fromEnv($input->getOption('model'));
        $generator = new TestGenerator($llm, $runner, $prompts);

        $start = microtime(true);
        $result = $generator->generate(
            $class,
            $testFile,
            (int) $input->getOption('attempts'),
            fn (int $n, bool $passed) => $io->writeln("  attempt {$n}: " . ($passed ? '<info>passed</info>' : '<comment>failed</comment>')),
        );
        $seconds = round(microtime(true) - $start, 1);
        $io->writeln($llm->usage()->summary());

        if ($result->passed) {
            $io->success("Tests pass after {$result->attempts} attempt(s), {$seconds}s: {$result->testFile}");

            return Command::SUCCESS;
        }

        $io->error("Tests still fail after {$result->attempts} attempts. Kept for inspection: {$result->testFile}");
        $io->writeln(mb_substr($result->lastOutput, 0, 2000));

        return Command::FAILURE;
    }
}
