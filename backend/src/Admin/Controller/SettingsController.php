<?php

namespace App\Admin\Controller;

use App\Admin\DTO\SettingsResponse;
use App\Admin\DTO\UpdateSettingsRequest;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }

    #[Route('', name: 'api_admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json(new SettingsResponse(
            registrationEnabled: $this->settingsService->isRegistrationEnabled(),
        ));
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] UpdateSettingsRequest $request): JsonResponse
    {
        if ($request->registrationEnabled) {
            $this->settingsService->enableRegistration();
        } else {
            $this->settingsService->disableRegistration();
        }

        return $this->json(new SettingsResponse(
            registrationEnabled: $this->settingsService->isRegistrationEnabled(),
        ));
    }
}
