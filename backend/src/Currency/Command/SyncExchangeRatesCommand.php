<?php

namespace App\Currency\Command;

use App\Currency\Service\ExchangeRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Run by the scheduler a few times a day: fetches the exchange rates somebody needs, through yesterday. */
#[AsCommand(name: 'app:exchange-rates:sync', description: 'Fetch the exchange rates the users need, through yesterday')]
class SyncExchangeRatesCommand extends Command
{
    public function __construct(private readonly ExchangeRateService $service)
    {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $unresolved = $this->service->sync();

        if ($unresolved !== []) {
            $io->warning('No rate found for: '.implode(', ', $unresolved));

            return Command::FAILURE;
        }

        $io->success('Exchange rates up to date.');

        return Command::SUCCESS;
    }
}
