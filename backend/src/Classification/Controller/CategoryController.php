<?php

namespace App\Classification\Controller;

use App\Classification\DTO\NameNotesRequest;
use App\Classification\DTO\NameNotesResponse;
use App\Classification\Service\ClassificationDeletionService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Core\Pagination\PaginatedResponse;
use App\Core\Pagination\PaginationParams;
use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/categories')]
class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClassificationDeletionService $deletionService,
    ) {
    }

    #[Route('', name: 'api_categories_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $params = PaginationParams::fromRequest($request, $user);

        [$categories, $total] = $this->categoryRepository->findByUserPaginated($user, $params);

        $items = array_map(
            static fn ($category) => NameNotesResponse::fromCategory($category),
            $categories
        );

        return $this->json(PaginatedResponse::create($items, $total, $params));
    }

    #[Route('/{id}', name: 'api_categories_get', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function get(string $id): JsonResponse
    {
        return $this->json(NameNotesResponse::fromCategory($this->findOwned($id)));
    }

    #[Route('', name: 'api_categories_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] NameNotesRequest $request): JsonResponse
    {
        $category = new Category($this->getCurrentUser(), $request->name);
        $category->setNotes($request->notes);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->json(NameNotesResponse::fromCategory($category), 201);
    }

    #[Route('/{id}', name: 'api_categories_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function update(string $id, #[MapRequestPayload] NameNotesRequest $request): JsonResponse
    {
        $category = $this->findOwned($id);
        $category->setName($request->name);
        $category->setNotes($request->notes);

        $this->entityManager->flush();

        return $this->json(NameNotesResponse::fromCategory($category));
    }

    /**
     * How many transactions use this category, for the confirmation dialog.
     */
    #[Route('/{id}/deletion-preview', name: 'api_categories_deletion_preview', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function deletionPreview(string $id): JsonResponse
    {
        return $this->json($this->deletionService->previewCategory($this->findOwned($id)));
    }

    /**
     * Marks the category as deleted and takes it off the transactions using it.
     */
    #[Route('/{id}', name: 'api_categories_delete', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        $this->deletionService->deleteCategory($this->findOwned($id));

        return $this->json(null, 204);
    }

    private function findOwned(string $id): Category
    {
        $category = $this->categoryRepository->findOneByUserAndId($this->getCurrentUser(), $id);

        if ($category === null) {
            throw ApiException::notFound(ErrorCode::CategoryNotFound);
        }

        return $category;
    }

    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::CategoryNotFound);
        }

        return $user;
    }
}
