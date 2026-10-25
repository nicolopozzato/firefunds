<?php

namespace App\Account\Controller;

use App\Account\DTO\CurrencyResponse;
use App\Repository\CurrencyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/currencies')]
class CurrencyController extends AbstractController
{
    public function __construct(
        private readonly CurrencyRepository $currencyRepository,
    ) {
    }

    #[Route('', name: 'api_currencies_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $currencies = $this->currencyRepository->findAllOrdered();

        return $this->json(array_map(
            static fn ($currency) => CurrencyResponse::fromEntity($currency),
            $currencies
        ));
    }
}
