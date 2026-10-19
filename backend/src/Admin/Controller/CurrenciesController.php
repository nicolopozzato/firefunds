<?php

namespace App\Admin\Controller;

use App\Admin\DTO\AdminCurrencyResponse;
use App\Admin\DTO\CurrencyRequest;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Exception\InUseException;
use App\Entity\Currency;
use App\Repository\AccountRepository;
use App\Repository\CurrencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/currencies')]
#[IsGranted('ROLE_ADMIN')]
class CurrenciesController extends AbstractController
{
    public function __construct(
        private readonly CurrencyRepository $currencyRepository,
        private readonly AccountRepository $accountRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'api_admin_currencies_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $currencies = $this->currencyRepository->findAllOrdered();

        return $this->json(array_map(
            static fn ($currency) => AdminCurrencyResponse::fromEntity($currency),
            $currencies
        ));
    }

    #[Route('', name: 'api_admin_currencies_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CurrencyRequest $request): JsonResponse
    {
        if ($this->currencyRepository->findOneByCode($request->code) !== null) {
            throw new ApiException(ErrorCode::CurrencyCodeTaken);
        }

        $currency = new Currency(
            code: $request->code,
            name: $request->name,
            symbol: $request->symbol,
            decimalPlaces: $request->decimalPlaces,
            isEnabled: $request->isEnabled,
        );

        $this->entityManager->persist($currency);
        $this->entityManager->flush();

        return $this->json(AdminCurrencyResponse::fromEntity($currency), 201);
    }

    #[Route('/{id}', name: 'api_admin_currencies_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] CurrencyRequest $request): JsonResponse
    {
        $currency = $this->findOrFail($id);

        $existing = $this->currencyRepository->findOneByCode($request->code);
        if ($existing !== null && !$existing->getId()->equals($currency->getId())) {
            throw new ApiException(ErrorCode::CurrencyCodeTaken);
        }

        $currency->setCode($request->code);
        $currency->setName($request->name);
        $currency->setSymbol($request->symbol);
        $currency->setDecimalPlaces($request->decimalPlaces);
        $currency->setIsEnabled($request->isEnabled);

        $this->entityManager->flush();

        return $this->json(AdminCurrencyResponse::fromEntity($currency));
    }

    #[Route('/{id}', name: 'api_admin_currencies_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        $currency = $this->findOrFail($id);

        if ($this->accountRepository->count(['currency' => $currency]) > 0) {
            throw new InUseException(ErrorCode::CurrencyInUse);
        }

        $currency->markDeleted();
        $this->entityManager->flush();

        return $this->json(null, 204);
    }

    private function findOrFail(string $id): Currency
    {
        $currency = $this->currencyRepository->findOneById($id);

        if ($currency === null) {
            throw ApiException::notFound(ErrorCode::CurrencyNotFound);
        }

        return $currency;
    }
}
