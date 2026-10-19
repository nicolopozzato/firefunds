<?php

namespace App\Dashboard\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Account\Service\AccountService;
use App\Core\Period\CalendarRange;
use App\Dashboard\DTO\DashboardAccountsRequest;
use App\Dashboard\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/dashboard')]
class DashboardController extends AbstractController
{
    use ResolvesCurrentUser;

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
        [$from, $to] = CalendarRange::orThisMonth($request->query->get('from'), $request->query->get('to'), $this->getCurrentUser());

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
}
