<?php

namespace App\CsvImport\Controller;

use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\CsvImport\DTO\CommitImportRequest;
use App\CsvImport\DTO\ImportPresetRequest;
use App\CsvImport\DTO\ImportRowsRequest;
use App\CsvImport\Service\CsvImportService;
use App\CsvImport\Service\ImportUndo;
use App\Entity\ImportBatch;
use App\Entity\ImportPreset;
use App\Repository\ImportBatchRepository;
use App\Repository\ImportPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** Importing a file (read in the browser): presets per bank, the preview's checks, the import, undoing one. */
#[Route('/api/import')]
class CsvImportController extends AbstractController
{
    use ResolvesCurrentUser;

    private const ID = ['id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly CsvImportService $import,
        private readonly ImportPresetRepository $presets,
        private readonly ImportBatchRepository $batches,
        private readonly ImportUndo $undo,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** For each row: an identical transaction already there, a recurring occurrence it is, the other side and category it likely has. */
    #[Route('/preview', name: 'api_import_preview', methods: ['POST'])]
    public function preview(#[MapRequestPayload] ImportRowsRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();

        return $this->json($this->import->preview($user, $request->rows));
    }

    #[Route('/commit', name: 'api_import_commit', methods: ['POST'])]
    public function commit(#[MapRequestPayload] CommitImportRequest $request): JsonResponse
    {
        return $this->json(self::batchToArray($this->import->commit($this->getCurrentUser(), $request)), 201);
    }

    /** The latest imports, newest first, to undo one. */
    #[Route('/batches', name: 'api_import_batches', methods: ['GET'])]
    public function batches(): JsonResponse
    {
        return $this->json(array_map(self::batchToArray(...), $this->batches->findLatestByUser($this->getCurrentUser(), 10)));
    }

    #[Route('/batches/{id}/undo', name: 'api_import_batches_undo', methods: ['POST'], requirements: self::ID)]
    public function undo(string $id): JsonResponse
    {
        $batch = $this->batches->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::ImportBatchNotFound);

        return $this->json($this->undo->undo($batch));
    }

    #[Route('/presets', name: 'api_import_presets', methods: ['GET'])]
    public function presets(): JsonResponse
    {
        return $this->json(array_map(self::toArray(...), $this->presets->findByUser($this->getCurrentUser())));
    }

    #[Route('/presets', name: 'api_import_presets_create', methods: ['POST'])]
    public function createPreset(#[MapRequestPayload] ImportPresetRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $preset = new ImportPreset($user, trim($request->name), $request->accountId !== null ? $this->import->ownedAssetAccount($user, $request->accountId) : null, $request->settings, array_values($request->headers));
        $this->entityManager->persist($preset);
        $this->entityManager->flush();

        return $this->json(self::toArray($preset), 201);
    }

    #[Route('/presets/{id}', name: 'api_import_presets_update', methods: ['PUT'], requirements: self::ID)]
    public function updatePreset(string $id, #[MapRequestPayload] ImportPresetRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        $preset = $this->findPreset($id);
        $preset->update(trim($request->name), $request->accountId !== null ? $this->import->ownedAssetAccount($user, $request->accountId) : null, $request->settings, array_values($request->headers));
        $this->entityManager->flush();

        return $this->json(self::toArray($preset));
    }

    #[Route('/presets/{id}', name: 'api_import_presets_delete', methods: ['DELETE'], requirements: self::ID)]
    public function deletePreset(string $id): JsonResponse
    {
        $this->findPreset($id)->markDeleted();
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private static function batchToArray(ImportBatch $batch): array
    {
        return [
            'id' => (string) $batch->getId(),
            'fileName' => $batch->getFileName(),
            'createdAt' => $batch->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'transactions' => $batch->getTransactionCount(),
            'adjustments' => $batch->getAdjustmentCount(),
            'createdAccounts' => \count($batch->getCreatedAccountIds()),
            'createdCategories' => \count($batch->getCreatedCategoryIds()),
            'createdTags' => \count($batch->getCreatedTagIds()),
            'undoneAt' => $batch->getUndoneAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function toArray(ImportPreset $preset): array
    {
        return [
            'id' => (string) $preset->getId(),
            'name' => $preset->getName(),
            'accountId' => $preset->getAccount()?->getId()->toRfc4122(),
            'settings' => $preset->getSettings(),
            'headers' => $preset->getHeaders(),
            'updatedAt' => $preset->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function findPreset(string $id): ImportPreset
    {
        return $this->presets->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::ImportPresetNotFound);
    }
}
