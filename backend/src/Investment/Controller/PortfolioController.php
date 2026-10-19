<?php

namespace App\Investment\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
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

        [$from, $to] = $this->parseDateRange($request);

        return $this->json($this->portfolioService->forUser($user, $from, $to));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        try {
            return [
                $from !== null ? new \DateTimeImmutable($from) : new \DateTimeImmutable('first day of this month'),
                $to !== null ? new \DateTimeImmutable($to) : new \DateTimeImmutable('last day of this month'),
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
    }
}
