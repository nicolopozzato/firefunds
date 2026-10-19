<?php

namespace App\Dashboard\Controller;

use App\Account\Service\AccountService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Dashboard\DTO\DashboardAccountsRequest;
use App\Dashboard\Service\DashboardService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly AccountService $accountService,
    ) {
    }

    /**
     * Everything on the dashboard for the period `from`-`to` (Y-m-d, both
     * included; the current month by default).
     */
    #[Route('', name: 'api_dashboard', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        [$from, $to] = $this->parseDateRange($request);

        return $this->json($this->dashboardService->forUser($this->getCurrentUser(), $from, $to));
    }

    /**
     * Which asset accounts the dashboard shows: the ones listed, every other
     * one is hidden.
     */
    #[Route('/accounts', name: 'api_dashboard_accounts', methods: ['PUT'])]
    public function updateAccounts(#[MapRequestPayload] DashboardAccountsRequest $request): JsonResponse
    {
        $this->accountService->setDashboardAccounts($this->getCurrentUser(), $request->visibleAccountIds);

        return $this->json(null, 204);
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::UserNotFound);
        }

        return $user;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        try {
            $range = [
                $from !== null ? new \DateTimeImmutable($from) : new \DateTimeImmutable('first day of this month'),
                $to !== null ? new \DateTimeImmutable($to) : new \DateTimeImmutable('last day of this month'),
            ];
        } catch (\Exception) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }

        if ($range[0]->format('Y-m-d') > $range[1]->format('Y-m-d')) {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }

        return $range;
    }
}
