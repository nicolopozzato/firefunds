<?php

namespace App\Backup\Controller;

use App\Backup\FirstStartRestore;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Before the first account exists: start from a backup instead (the login
 * page offers it). Public, like registering the first user, and refused as
 * soon as there is one.
 */
#[Route('/api/setup')]
class FirstStartRestoreController extends AbstractController
{
    public function __construct(private readonly FirstStartRestore $firstStart)
    {
    }

    #[Route('/restore', name: 'api_setup_restore', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        $file = $request->files->get('backup');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new ApiException(ErrorCode::BackupUnreadable);
        }

        $check = $this->firstStart->restore($file->getPathname());

        return $this->json([
            'appVersion' => $check->manifest->appVersion,
            'createdAt' => $check->manifest->createdAt,
        ]);
    }
}
