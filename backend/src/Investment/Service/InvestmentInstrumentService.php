<?php

namespace App\Investment\Service;

use App\Entity\InvestmentInstrument;
use App\Entity\User;
use App\Investment\DTO\InvestmentInstrumentRequest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creating and editing instruments. A new one gets yesterday's close right
 * away, so its chart isn't empty until the scheduled fetch.
 */
final readonly class InvestmentInstrumentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InvestmentPriceService $priceService,
        private LoggerInterface $logger,
    ) {
    }

    public function create(User $user, InvestmentInstrumentRequest $request): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument($user, $request->name, $request->getInstrumentType());
        $this->apply($instrument, $request);

        $this->entityManager->persist($instrument);
        $this->entityManager->flush();

        try {
            $this->priceService->seedInitialPrice($instrument);
        } catch (\Throwable $e) {
            // Best-effort: the scheduled fetch picks this instrument up regardless.
            $this->logger->warning('Failed to seed initial price for instrument {id}: {error}', [
                'id' => (string) $instrument->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $instrument;
    }

    public function update(InvestmentInstrument $instrument, InvestmentInstrumentRequest $request): InvestmentInstrument
    {
        $instrument->setName($request->name);
        $instrument->setType($request->getInstrumentType());
        $this->apply($instrument, $request);

        $this->entityManager->flush();

        return $instrument;
    }

    private function apply(InvestmentInstrument $instrument, InvestmentInstrumentRequest $request): void
    {
        $instrument->setTicker($request->ticker);
        $instrument->setYahooTicker($request->yahooTicker);
        $instrument->setIsin($request->isin);
        // Empty: the user's own rate applies.
        $instrument->setCapitalGainsTaxRate($request->capitalGainsTaxRate !== null && $request->capitalGainsTaxRate !== '' ? $request->capitalGainsTaxRate : null);
    }
}
