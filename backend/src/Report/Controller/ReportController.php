<?php

namespace App\Report\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use App\Report\DTO\NetWorthReportQuery;
use App\Report\DTO\ReportQuery;
use App\Report\Period\ReportRange;
use App\Report\Service\IncomeExpenseReportService;
use App\Report\Service\NetWorthReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/reports')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly IncomeExpenseReportService $incomeExpenseReportService,
        private readonly NetWorthReportService $netWorthReportService,
    ) {
    }

    /**
     * Income and expenses over `from`-`to` (Y-m-d, both included), a column
     * per month or per year (`by`), optionally compared with the range right
     * before or the same one a year earlier (`compare`).
     */
    #[Route('/income-expenses', name: 'api_reports_income_expenses', methods: ['GET'])]
    public function incomeExpenses(#[MapQueryString(validationFailedStatusCode: 400)] ReportQuery $query = new ReportQuery()): JsonResponse
    {
        if ($query->from === '' || $query->to === '') {
            throw new ApiException(ErrorCode::InvalidDateRange);
        }
        if ($query->from > $query->to) {
            throw new ApiException(ErrorCode::DateRangeInverted);
        }

        return $this->json($this->incomeExpenseReportService->forUser(
            $this->getCurrentUser(),
            new ReportRange(new \DateTimeImmutable($query->from), new \DateTimeImmutable($query->to)),
            $query->by,
            $query->compare !== 'none' ? $query->compare : null,
        ));
    }

    /** Every asset at the end of each month of `year` (this year by default) and of the year before. */
    #[Route('/net-worth', name: 'api_reports_net_worth', methods: ['GET'])]
    public function netWorth(#[MapQueryString(validationFailedStatusCode: 400)] NetWorthReportQuery $query = new NetWorthReportQuery()): JsonResponse
    {
        return $this->json($this->netWorthReportService->forUser($this->getCurrentUser(), $query->year));
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::UserNotFound);
        }

        return $user;
    }
}
