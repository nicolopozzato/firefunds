<?php

namespace App\Search\Controller;

use App\Core\Search\SearchQuery;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\InvestmentInstrumentRepository;
use App\Search\DTO\SearchSuggestionsResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The search box. The transactions found are listed by
 * `GET /api/transactions?q=…`; this only gives the shortcuts shown while
 * typing.
 */
#[Route('/api/search')]
class SearchController extends AbstractController
{
    private const int SUGGESTIONS_PER_GROUP = 5;

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly InvestmentInstrumentRepository $instrumentRepository,
    ) {
    }

    #[Route('/suggestions', name: 'api_search_suggestions', methods: ['GET'])]
    public function suggestions(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $search = SearchQuery::parse($request->query->get('q'));
        if ($search === null) {
            return $this->json(new SearchSuggestionsResponse(accounts: [], instruments: []));
        }

        return $this->json(new SearchSuggestionsResponse(
            accounts: $this->accountRepository->searchByName($user, $search, self::SUGGESTIONS_PER_GROUP),
            instruments: $this->instrumentRepository->search($user, $search, self::SUGGESTIONS_PER_GROUP),
        ));
    }
}
