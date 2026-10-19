<?php

namespace App\Investment\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;
use App\Entity\User;
use App\Investment\DTO\ImportPricesRequest;
use App\Investment\DTO\InvestmentInstrumentRequest;
use App\Investment\DTO\InvestmentInstrumentResponse;
use App\Investment\DTO\InvestmentLivePriceResponse;
use App\Investment\DTO\InvestmentPriceResponse;
use App\Investment\PriceProvider\PriceProviderInterface;
use App\Investment\Service\InstrumentDeletionService;
use App\Investment\Service\InvestmentPriceService;
use App\Investment\Service\PriceCalendar;
use App\Repository\InvestmentInstrumentRepository;
use App\Repository\InvestmentPriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/investment-instruments')]
class InvestmentInstrumentController extends AbstractController
{
    public function __construct(
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InstrumentDeletionService $instrumentDeletionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvestmentPriceService $priceService,
        private readonly PriceCalendar $priceCalendar,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'api_investment_instruments_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $params = PaginationParams::fromRequest($request, $user);

        [$instruments, $total] = $this->instrumentRepository->findByUserPaginated($user, $params);

        $items = array_map(
            static fn ($instrument) => InvestmentInstrumentResponse::fromEntity($instrument),
            $instruments
        );

        return $this->json(PaginatedResponse::create($items, $total, $params));
    }

    #[Route('/{id}', name: 'api_investment_instruments_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(InvestmentInstrumentResponse::fromEntity($this->findOwned($id)));
    }

    #[Route('/{id}/prices', name: 'api_investment_instruments_prices', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function prices(string $id, Request $request, InvestmentPriceRepository $priceRepository): JsonResponse
    {
        $instrument = $this->findOwned($id);
        [$from, $to] = $this->parseDateRange($request);

        $prices = $priceRepository->findByInstrumentInRange($instrument, $from, $to);

        return $this->json(array_map(
            static fn (InvestmentPrice $price) => new InvestmentPriceResponse(
                date: $price->getDate()->format('Y-m-d'),
                price: $price->getPrice(),
                originalPrice: $price->getOriginalPrice(),
            ),
            $prices
        ));
    }

    #[Route('/{id}/prices/import', name: 'api_investment_instruments_import_prices', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function importPrices(string $id, #[MapRequestPayload] ImportPricesRequest $request): JsonResponse
    {
        $instrument = $this->findOwned($id);

        try {
            $from = new \DateTimeImmutable($request->from);
            $to = new \DateTimeImmutable($request->to);
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }

        if ($from > $to) {
            throw new ApiException(ErrorCode::DateRangeInverted);
        }

        $yesterday = $this->priceCalendar->yesterday();
        if ($to > $yesterday) {
            $to = $yesterday;
        }

        $this->priceService->importRange($instrument, $from, $to);

        return $this->json(null, 202);
    }

    #[Route('/{id}/live-price', name: 'api_investment_instruments_live_price', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function livePrice(string $id, PriceProviderInterface $priceProvider): JsonResponse
    {
        $instrument = $this->findOwned($id);
        $quote = $priceProvider->getLiveQuote($instrument);

        return $this->json(new InvestmentLivePriceResponse(
            price: $quote->price,
            marketState: $quote->marketState->value,
            fetchedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ));
    }

    #[Route('', name: 'api_investment_instruments_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] InvestmentInstrumentRequest $request): JsonResponse
    {
        $instrument = new InvestmentInstrument($this->getCurrentUser(), $request->name, $request->getInstrumentType());
        $instrument->setTicker($request->ticker);
        $instrument->setYahooTicker($request->yahooTicker);
        $instrument->setIsin($request->isin);
        $instrument->setCapitalGainsTaxRate($request->capitalGainsTaxRate !== null && $request->capitalGainsTaxRate !== '' ? $request->capitalGainsTaxRate : null);

        $this->entityManager->persist($instrument);
        $this->entityManager->flush();

        try {
            $this->priceService->seedInitialPrice($instrument);
        } catch (\Throwable $e) {
            // Best-effort: the daily cron will pick this instrument up regardless.
            $this->logger->warning('Failed to seed initial price for instrument {id}: {error}', [
                'id' => (string) $instrument->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->json(InvestmentInstrumentResponse::fromEntity($instrument), 201);
    }

    #[Route('/{id}', name: 'api_investment_instruments_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] InvestmentInstrumentRequest $request): JsonResponse
    {
        $instrument = $this->findOwned($id);
        $instrument->setName($request->name);
        $instrument->setType($request->getInstrumentType());
        $instrument->setTicker($request->ticker);
        $instrument->setYahooTicker($request->yahooTicker);
        $instrument->setIsin($request->isin);
        $instrument->setCapitalGainsTaxRate($request->capitalGainsTaxRate !== null && $request->capitalGainsTaxRate !== '' ? $request->capitalGainsTaxRate : null);

        $this->entityManager->flush();

        return $this->json(InvestmentInstrumentResponse::fromEntity($instrument));
    }

    /**
     * What deleting this instrument would affect, for the confirmation dialog.
     */
    #[Route('/{id}/deletion-preview', name: 'api_investment_instruments_deletion_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function deletionPreview(string $id): JsonResponse
    {
        return $this->json($this->instrumentDeletionService->preview($this->findOwned($id)));
    }

    /**
     * Marks the instrument as deleted. If it has investment transactions,
     * `?deleteTransactions=1` must confirm deleting them too; otherwise 409.
     */
    #[Route('/{id}', name: 'api_investment_instruments_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $this->instrumentDeletionService->delete($this->findOwned($id), $request->query->getBoolean('deleteTransactions'));

        return $this->json(null, 204);
    }

    private function findOwned(string $id): InvestmentInstrument
    {
        $instrument = $this->instrumentRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($instrument === null) {
            throw ApiException::notFound(ErrorCode::InstrumentNotFound);
        }

        return $instrument;
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::InstrumentNotFound);
        }

        return $user;
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        try {
            return [
                $from !== null ? new \DateTimeImmutable($from) : null,
                $to !== null ? new \DateTimeImmutable($to) : null,
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
    }
}
