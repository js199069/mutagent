<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Console;

use Js199069\Mutagent\Analysis\ClassAnalyzer;
use Js199069\Mutagent\Generation\PromptBuilder;
use Js199069\Mutagent\Improvement\ImprovePromptBuilder;
use Js199069\Mutagent\Improvement\TestImprover;
use Js199069\Mutagent\Improvement\TestMinimizer;
use Js199069\Mutagent\Improvement\VerdictStore;
use Js199069\Mutagent\Infection\InfectionRunner;
use Js199069\Mutagent\Infection\MutationReport;
use Js199069\Mutagent\Llm\CachingChatClient;
use Js199069\Mutagent\Llm\OpenAiCompatibleClient;
use Js199069\Mutagent\Runner\PhpUnitRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'improve', description: 'Strengthen generated tests until they kill surviving mutants (Infection)')]
final class ImproveCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('class-file', InputArgument::REQUIRED, 'Path to the class, relative to the project')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project root', getcwd())
            ->addOption('test', null, InputOption::VALUE_REQUIRED, 'Test file (default: tests/Generated/<Class>GeneratedTest.php)')
            ->addOption('rounds', null, InputOption::VALUE_REQUIRED, 'Maximum improvement rounds (0 = only measure)', '3')
            ->addOption('minimize', null, InputOption::VALUE_NONE, 'Remove tests that kill no mutant (verified with Infection)')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Do not reuse cached LLM responses')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model name (overrides MUTAGENT_LLM_MODEL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = rtrim((string) $input->getOption('project'), '/\\');
        $classFile = $project . DIRECTORY_SEPARATOR . $input->getArgument('class-file');
        $class = (new ClassAnalyzer())->analyze($classFile);

        $runner = new PhpUnitRunner($project);
        $version = $runner->version();
        $testFile = $input->getOption('test') !== null
            ? $project . DIRECTORY_SEPARATOR . $input->getOption('test')
            : $project . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Generated' . DIRECTORY_SEPARATOR . $class->shortName . 'GeneratedTest.php';

        if (! is_file($testFile)) {
            $io->error("Test file not found: {$testFile}. Run 'mutagent generate' first.");

            return Command::FAILURE;
        }

        $io->writeln("Class: <info>{$class->fqcn}</info> — test: {$testFile}");

        $base = OpenAiCompatibleClient::fromEnv($input->getOption('model'));
        $llm = $input->getOption('no-cache') ? $base : new CachingChatClient($base, $project . '/.mutagent/llm-cache', $base->cacheNamespace());
        $mutation = new InfectionRunner($project, $classFile);
        $store = new VerdictStore($project . '/.mutagent/verdicts-' . substr(sha1(str_replace('\\', '/', $classFile)), 0, 10) . '.json');

        $improver = new TestImprover(
            llm: $llm,
            runner: $runner,
            mutation: $mutation,
            prompts: new ImprovePromptBuilder($version),
            fixPrompts: new PromptBuilder(phpunitVersion: $version),
            store: $store,
        );

        $start = microtime(true);
        $result = $improver->improve(
            $class,
            $testFile,
            (int) $input->getOption('rounds'),
            fn (int $round, MutationReport $r) => $io->writeln(sprintf(
                '  round %d: MSI %s%% — %d killed, %d escaped, %d not covered, %d mutants',
                $round, $r->msi, $r->killed, $r->escaped, $r->notCovered, $r->total,
            )),
        );

        $minimizer = new TestMinimizer($runner, $mutation);
        if ($input->getOption('minimize')) {
            $io->writeln('  minimizing...');
            $minimized = $minimizer->minimize($testFile, $result->final);
            $redundantNote = $minimized->removed !== []
                ? 'removed ' . count($minimized->removed) . ': ' . implode(', ', $minimized->removed)
                : (string) $minimized->note;
        } else {
            [$redundant, $note] = $minimizer->redundant($testFile, $result->final);
            $redundantNote = $redundant !== []
                ? count($redundant) . ' kill no mutant (kept; --minimize removes them): ' . implode(', ', $redundant)
                : (string) $note;
        }

        $seconds = round(microtime(true) - $start, 1);

        $io->definitionList(
            ['MSI before' => $result->initial->msi . '%'],
            ['MSI after' => $result->final->msi . '%'],
            ['MSI excluding claimed-equivalent' => $result->msiExcludingClaimedEquivalent() . '%'],
            ['Rounds' => (string) $result->rounds],
            ['Rejected candidates' => (string) $result->rejectedCandidates],
            ['Redundant tests' => $redundantNote],
            ['Time' => $seconds . 's'],
            ['LLM usage' => $base->usage()->summary() . ($llm instanceof CachingChatClient ? ", cache hits: {$llm->hits}" : '')],
        );

        if ($result->equivalent !== []) {
            $io->section('Claimed equivalent (review these)');
            foreach ($result->equivalent as $v) {
                $io->writeln(sprintf('- %s, line %d: %s', $v->mutator, $v->line, $v->reason ?: '(no reason given)'));
            }
        }

        if ($result->unresolved !== []) {
            $io->section('Unresolved');
            foreach ($result->unresolved as $m) {
                $io->writeln(sprintf('- %s, line %d', $m->mutator, $m->line));
                $io->writeln($m->changedLines());
            }
        }

        return Command::SUCCESS;
    }
}
