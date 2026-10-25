<?php

namespace App\Currency\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Period\CalendarRange;
use App\Currency\DTO\ExchangeRateRequest;
use App\Currency\DTO\ExchangeRatesOverviewResponse;
use App\Currency\Service\UserExchangeRates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** The exchange rates, in the user's own currency, and the user's own corrections. */
#[Route('/api/exchange-rates')]
class ExchangeRateController extends AbstractController
{
    use ResolvesCurrentUser;

    public function __construct(private readonly UserExchangeRates $rates)
    {
    }

    #[Route('', name: 'api_exchange_rates_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getCurrentUser();

        return $this->json(new ExchangeRatesOverviewResponse($user->getCurrency(), $this->rates->currenciesFor($user)));
    }

    #[Route('/{currency}', name: 'api_exchange_rates_days', methods: ['GET'], requirements: ['currency' => '[A-Za-z]{3}'])]
    public function days(string $currency, Request $request): JsonResponse
    {
        [$from, $to] = CalendarRange::orThisMonth($request->query->get('from'), $request->query->get('to'), $this->getCurrentUser());

        return $this->json($this->rates->days($this->getCurrentUser(), $currency, $from, $to));
    }

    #[Route('/{currency}/{date}', name: 'api_exchange_rates_set', methods: ['PUT'], requirements: ['currency' => '[A-Za-z]{3}', 'date' => '\\d{4}-\\d{2}-\\d{2}'])]
    public function set(string $currency, string $date, #[MapRequestPayload] ExchangeRateRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $day = new \DateTimeImmutable($date);
        $this->rates->set($user, $currency, $day, $request->rate);

        return $this->json($this->rates->days($user, $currency, $day, $day)[0] ?? null);
    }

    #[Route('/{currency}/{date}', name: 'api_exchange_rates_revert', methods: ['DELETE'], requirements: ['currency' => '[A-Za-z]{3}', 'date' => '\\d{4}-\\d{2}-\\d{2}'])]
    public function revert(string $currency, string $date): Response
    {
        $this->rates->revertToShared($this->getCurrentUser(), $currency, new \DateTimeImmutable($date));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
