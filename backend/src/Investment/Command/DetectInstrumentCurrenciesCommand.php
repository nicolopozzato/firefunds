<?php

namespace App\Investment\Command;

use App\Investment\PriceProvider\QuoteCurrencyProviderInterface;
use App\Repository\CurrencyRepository;
use App\Repository\InvestmentInstrumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Instruments created before they had a currency are all in euros: asks Yahoo
 * what each one is quoted in and, with --apply, corrects the ones that differ.
 * Shows what it would do without it.
 */
#[AsCommand(name: 'app:investments:detect-currencies', description: 'Compare the instruments\' currency with the one Yahoo quotes them in (--apply to correct them)')]
class DetectInstrumentCurrenciesCommand extends Command
{
    public function __construct(
        private readonly InvestmentInstrumentRepository $instruments,
        private readonly QuoteCurrencyProviderInterface $quoteCurrency,
        private readonly CurrencyRepository $currencies,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Correct the currencies that differ')] bool $apply = false): int
    {
        $rows = [];
        foreach ($this->instruments->findAll() as $instrument) {
            if ($instrument->getQuoteSymbol() === null) {
                continue;
            }
            $detected = $this->quoteCurrency->getQuoteCurrency($instrument);
            $differs = $detected !== null && $detected !== $instrument->getCurrency() && $this->currencies->findOneByCode($detected) !== null;
            $rows[] = [$instrument->getName(), $instrument->getQuoteSymbol(), $instrument->getCurrency(), $detected ?? '?', $differs ? ($apply ? 'corrected' : 'differs') : ''];
            if ($differs && $apply) {
                $instrument->setCurrency($detected);
            }
        }
        $this->entityManager->flush();

        $io->table(['Instrument', 'Symbol', 'Now', 'Yahoo', ''], $rows);

        return Command::SUCCESS;
    }
}
