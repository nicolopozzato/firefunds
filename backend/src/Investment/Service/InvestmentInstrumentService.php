<?php

namespace App\Investment\Service;

use App\Entity\InvestmentInstrument;
use App\Entity\User;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Currency\Service\ExchangeRateService;
use App\Investment\DTO\InvestmentInstrumentRequest;
use App\Investment\PriceProvider\QuoteCurrencyProviderInterface;
use App\Repository\CurrencyRepository;
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
        private QuoteCurrencyProviderInterface $quoteCurrency,
        private CurrencyRepository $currencyRepository,
        private ExchangeRateService $exchangeRates,
        private LoggerInterface $logger,
    ) {
    }

    public function create(User $user, InvestmentInstrumentRequest $request): InvestmentInstrument
    {
        $instrument = new InvestmentInstrument($user, $request->name, $request->getInstrumentType());
        $this->apply($instrument, $request);
        $instrument->setCurrency($this->currencyFor($instrument, $request->currencyCode, detect: true));

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

        $this->exchangeRates->syncForUser($user);

        return $instrument;
    }

    public function update(InvestmentInstrument $instrument, InvestmentInstrumentRequest $request): InvestmentInstrument
    {
        $instrument->setName($request->name);
        $instrument->setType($request->getInstrumentType());
        $this->apply($instrument, $request);
        $instrument->setCurrency($this->currencyFor($instrument, $request->currencyCode, detect: false));

        $this->entityManager->flush();
        $this->exchangeRates->syncForUser($instrument->getUser());

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

    /**
     * The currency the instrument is quoted in: the one chosen (it must be on
     * the list), else, for a new instrument with a symbol, what Yahoo says,
     * else the one it already has (the user's own, for a new one).
     */
    private function currencyFor(InvestmentInstrument $instrument, ?string $code, bool $detect): string
    {
        if ($code !== null && $code !== '') {
            return $this->currencyRepository->findOneByCode($code)?->getCode()
                ?? throw new ApiException(ErrorCode::CurrencyNotAvailable, ['currency' => $code]);
        }

        if ($detect && $instrument->getQuoteSymbol() !== null) {
            $detected = $this->quoteCurrency->getQuoteCurrency($instrument);
            if ($detected !== null && $this->currencyRepository->findOneByCode($detected) !== null) {
                return $detected;
            }
        }

        return $instrument->getCurrency();
    }
}
