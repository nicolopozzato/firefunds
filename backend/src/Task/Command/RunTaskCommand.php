<?php

namespace App\Task\Command;

use App\Entity\TaskRunStatus;
use App\Entity\TaskTrigger;
use App\Task\TaskRunner;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** A scheduled task now, recorded like any other run (Administration → Scheduled tasks). */
#[AsCommand(name: 'app:tasks:run', description: 'Run a scheduled task now (prices, rates, backup, cleanup), or list them')]
class RunTaskCommand extends Command
{
    public function __construct(private readonly TaskRunner $runner)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'The task; none to list them')] ?string $task = null): int
    {
        if ($task === null) {
            foreach ($this->runner->tasks() as $key => $scheduled) {
                $io->writeln(\sprintf('%-10s %-20s next %s', $key, $scheduled->cron(), $this->runner->nextRunAt($scheduled)->format('Y-m-d H:i T')));
            }

            return Command::SUCCESS;
        }

        $run = $this->runner->run($task, TaskTrigger::Manual);
        $io->writeln(json_encode($run->getDetails(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '');
        if ($run->getError() !== null) {
            $io->writeln($run->getError());
        }
        match ($run->getStatus()) {
            TaskRunStatus::Success => $io->success('Done.'),
            TaskRunStatus::Partial => $io->warning('Partly done.'),
            default => $io->error('Failed.'),
        };

        return $run->getStatus() === TaskRunStatus::Failed ? Command::FAILURE : Command::SUCCESS;
    }
}
