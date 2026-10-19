<?php

namespace App\Investment\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Period\CalendarRange;
use App\Entity\User;
use App\Investment\Service\PortfolioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/investments')]
class PortfolioController extends AbstractController
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
    ) {
    }

    /**
     * Every investment account at once, positions added up per instrument:
     * the data behind Investments -> Overview. `from`/`to` (Y-m-d) bound the
     * history and the monthly flows; the totals are always as of today.
     */
    #[Route('/portfolio', name: 'api_investments_portfolio', methods: ['GET'])]
    public function portfolio(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::UserNotFound);
        }

        [$from, $to] = CalendarRange::orThisMonth($request->query->get('from'), $request->query->get('to'), $user);

        return $this->json($this->portfolioService->forUser($user, $from, $to));
    }
}
