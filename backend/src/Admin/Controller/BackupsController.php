<?php

namespace App\Admin\Controller;

use App\Admin\DTO\BackupSettingsRequest;
use App\Admin\DTO\BackupsResponse;
use App\Backup\BackupService;
use App\Backup\BackupSettings;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/backups')]
#[IsGranted('ROLE_ADMIN')]
class BackupsController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', name: 'api_admin_backups_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->overview();
    }

    #[Route('/settings', name: 'api_admin_backups_settings', methods: ['PUT'])]
    public function updateSettings(#[MapRequestPayload] BackupSettingsRequest $request): JsonResponse
    {
        $this->settings->saveBackup(new BackupSettings($request->enabled, $request->intervalHours, $request->retentionDays));

        return $this->overview();
    }

    #[Route('', name: 'api_admin_backups_create', methods: ['POST'])]
    public function create(): JsonResponse
    {
        $this->backups->create(BackupService::TRIGGER_MANUAL);

        return $this->overview(201);
    }

    #[Route('/{name}/download', name: 'api_admin_backups_download', methods: ['GET'])]
    public function download(string $name): BinaryFileResponse
    {
        $file = $this->backups->find($name) ?? throw ApiException::notFound(ErrorCode::BackupNotFound);

        $response = $this->file($file->path, $file->name, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        // Set here: guessing it would need symfony/mime.
        $response->headers->set('Content-Type', 'application/x-tar');

        return $response;
    }

    private function overview(int $status = 200): JsonResponse
    {
        return $this->json(BackupsResponse::of($this->settings->getBackup(), $this->backups, $this->backups->list()), $status);
    }
}
