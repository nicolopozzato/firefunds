<?php

namespace App\Draft\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Draft\DTO\AcceptDraftRequest;
use App\Draft\DTO\CreateDraftRequest;
use App\Draft\DTO\DraftResponse;
use App\Draft\Service\DraftService;
use App\Entity\ApiToken;
use App\Entity\Draft;
use App\Repository\DraftRepository;
use App\Transaction\DTO\TransactionResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** Drafts ("Da rivedere"): made by other programs, reviewed by the user. */
#[Route('/api/drafts')]
class DraftController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly DraftService $drafts,
        private readonly DraftRepository $repository,
    ) {
    }

    #[Route('', name: 'api_drafts_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map($this->toResponse(...), $this->repository->findPending($this->getCurrentUser())));
    }

    /**
     * 201 with the draft: waiting for the user (`pending`), or already dealt
     * with (`matched`: an identical transaction, or a recurring one recorded).
     */
    #[Route('', name: 'api_drafts_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateDraftRequest $request, Request $http): JsonResponse
    {
        $token = $http->attributes->get('_api_token');
        $draft = $this->drafts->create($this->getCurrentUser(), $request, 'api', $token instanceof ApiToken ? $token->getName() : null);

        return $this->json($this->toResponse($draft), 201);
    }

    #[Route('/{id}', name: 'api_drafts_get', methods: ['GET'], requirements: self::ID)]
    public function get(string $id): JsonResponse
    {
        return $this->json($this->toResponse($this->findOwned($id)));
    }

    #[Route('/{id}/accept', name: 'api_drafts_accept', methods: ['POST'], requirements: self::ID)]
    public function accept(string $id, #[MapRequestPayload] AcceptDraftRequest $request): JsonResponse
    {
        return $this->json(array_map(TransactionResponse::fromEntity(...), $this->drafts->accept($this->findOwned($id), $request)));
    }

    #[Route('/{id}/link', name: 'api_drafts_link', methods: ['POST'], requirements: self::ID)]
    public function link(string $id, Request $request): JsonResponse
    {
        $this->drafts->link($this->findOwned($id), (string) ($request->toArray()['transactionId'] ?? ''));

        return new JsonResponse(null, 204);
    }

    #[Route('/{id}/discard', name: 'api_drafts_discard', methods: ['POST'], requirements: self::ID)]
    public function discard(string $id): JsonResponse
    {
        $this->drafts->discard($this->findOwned($id));

        return new JsonResponse(null, 204);
    }

    private function toResponse(Draft $draft): DraftResponse
    {
        return DraftResponse::fromEntity($draft, $this->drafts->currency($draft));
    }

    private function findOwned(string $id): Draft
    {
        return $this->repository->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::DraftNotFound);
    }
}
