<?php

namespace App\Classification\Controller;

use App\Classification\DTO\NameNotesRequest;
use App\Classification\DTO\NameNotesResponse;
use App\Classification\Service\ClassificationDeletionService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tags')]
class TagController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $tagRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassificationDeletionService $deletionService,
    ) {
    }

    #[Route('', name: 'api_tags_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $params = PaginationParams::fromRequest($request, $user);

        [$tags, $total] = $this->tagRepository->findByUserPaginated($user, $params);

        $items = array_map(
            static fn ($tag) => NameNotesResponse::fromTag($tag),
            $tags
        );

        return $this->json(PaginatedResponse::create($items, $total, $params));
    }

    #[Route('/{id}', name: 'api_tags_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(NameNotesResponse::fromTag($this->findOwned($id)));
    }

    #[Route('', name: 'api_tags_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] NameNotesRequest $request): JsonResponse
    {
        $tag = new Tag($this->getCurrentUser(), $request->name);
        $tag->setNotes($request->notes);

        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $this->json(NameNotesResponse::fromTag($tag), 201);
    }

    #[Route('/{id}', name: 'api_tags_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] NameNotesRequest $request): JsonResponse
    {
        $tag = $this->findOwned($id);
        $tag->setName($request->name);
        $tag->setNotes($request->notes);

        $this->entityManager->flush();

        return $this->json(NameNotesResponse::fromTag($tag));
    }

    /**
     * How many transactions use this tag, for the confirmation dialog.
     */
    #[Route('/{id}/deletion-preview', name: 'api_tags_deletion_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function deletionPreview(string $id): JsonResponse
    {
        return $this->json($this->deletionService->previewTag($this->findOwned($id)));
    }

    /**
     * Marks the tag as deleted and takes it off the transactions using it.
     */
    #[Route('/{id}', name: 'api_tags_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        $this->deletionService->deleteTag($this->findOwned($id));

        return $this->json(null, 204);
    }

    private function findOwned(string $id): Tag
    {
        $tag = $this->tagRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($tag === null) {
            throw ApiException::notFound(ErrorCode::TagNotFound);
        }

        return $tag;
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::TagNotFound);
        }

        return $user;
    }
}
