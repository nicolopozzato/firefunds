<?php

namespace App\Investment\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Period\CalendarRange;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Entity\InvestmentInstrument;
use App\Entity\InvestmentPrice;
use App\Investment\DTO\ImportPricesRequest;
use App\Investment\DTO\InvestmentInstrumentRequest;
use App\Investment\DTO\InvestmentInstrumentResponse;
use App\Investment\DTO\InvestmentLivePriceResponse;
use App\Investment\DTO\InvestmentPriceResponse;
use App\Investment\Service\InstrumentDeletionService;
use App\Investment\Service\InvestmentInstrumentService;
use App\Investment\Service\InvestmentPriceService;
use App\Investment\Service\LiveQuoteService;
use App\Investment\Service\PriceCalendar;
use App\Repository\InvestmentInstrumentRepository;
use App\Repository\InvestmentPriceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/investment-instruments')]
class InvestmentInstrumentController extends AbstractController
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly InvestmentInstrumentRepository $instrumentRepository,
        private readonly InstrumentDeletionService $instrumentDeletionService,
        private readonly InvestmentInstrumentService $instrumentService,
        private readonly InvestmentPriceService $priceService,
        private readonly PriceCalendar $priceCalendar,
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
        [$from, $to] = CalendarRange::optional($request->query->get('from'), $request->query->get('to'));

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

        [$from, $to] = CalendarRange::checked(CalendarRange::day($request->from), CalendarRange::day($request->to));

        // Clamped before anything else is decided: a range entirely in the
        // future has nothing to import and says so.
        $yesterday = $this->priceCalendar->yesterday();
        if ($to > $yesterday) {
            $to = $yesterday;
        }
        if ($from > $to) {
            throw new ApiException(ErrorCode::DateRangeInverted);
        }

        $this->priceService->importRange($instrument, $from, $to);

        return $this->json(null, 202);
    }

    #[Route('/{id}/live-price', name: 'api_investment_instruments_live_price', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function livePrice(string $id, LiveQuoteService $liveQuotes): JsonResponse
    {
        $quote = $liveQuotes->quote($this->findOwned($id));

        return $this->json(new InvestmentLivePriceResponse(
            price: $quote->price,
            marketState: $quote->marketState->value,
            fetchedAt: $quote->fetchedAt->format(\DateTimeInterface::ATOM),
            refreshSeconds: LiveQuoteService::POLL_SECONDS,
        ));
    }

    #[Route('', name: 'api_investment_instruments_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] InvestmentInstrumentRequest $request): JsonResponse
    {
        $instrument = $this->instrumentService->create($this->getCurrentUser(), $request);

        return $this->json(InvestmentInstrumentResponse::fromEntity($instrument), 201);
    }

    #[Route('/{id}', name: 'api_investment_instruments_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] InvestmentInstrumentRequest $request): JsonResponse
    {
        $instrument = $this->instrumentService->update($this->findOwned($id), $request);

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
}
