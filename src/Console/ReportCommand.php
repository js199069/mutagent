<?php

declare(strict_types=1);

namespace Js199069\Mutagent\Console;

use Js199069\Mutagent\Infection\InfectionLogReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'report', description: 'Summarize an Infection JSON log: MSI and escaped mutants')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('log', InputArgument::OPTIONAL, 'Path to the Infection JSON log', 'infection-log.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = (new InfectionLogReader())->fromFile((string) $input->getArgument('log'));

        $io->definitionList(
            ['Mutants' => (string) $report->total],
            ['Killed' => (string) $report->killed],
            ['Escaped' => (string) $report->escaped],
            ['Mutation code coverage' => $report->mutationCodeCoverage . '%'],
            ['MSI' => $report->msi . '%'],
        );

        foreach ($report->escapedMutants() as $i => $mutant) {
            $io->section(sprintf('%d) %s — %s:%d', $i + 1, $mutant->mutator, basename($mutant->filePath), $mutant->line));
            $io->writeln($mutant->changedLines());
        }

        return Command::SUCCESS;
    }
}
