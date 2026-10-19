<?php

namespace App\Investment\Command;

use App\Investment\Service\InvestmentPriceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:investments:fetch-prices',
    description: 'Fetches missing daily closing prices for every investment instrument, backfilling any gap since the last recorded price.',
)]
class FetchInvestmentPricesCommand extends Command
{
    public function __construct(
        private readonly InvestmentPriceService $priceService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->priceService->updateAllInstruments();

        $io->success('Investment prices updated.');

        return Command::SUCCESS;
    }
}
