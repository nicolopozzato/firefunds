<?php

namespace App\Admin\Controller;

use App\Admin\DTO\SettingsResponse;
use App\Admin\DTO\UpdateSettingsRequest;
use App\Service\SettingsService;
use App\Update\UpdateCheck;
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
        private readonly UpdateCheck $updates,
    ) {
    }

    #[Route('', name: 'api_admin_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json($this->response());
    }

    #[Route('', name: 'api_admin_settings_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] UpdateSettingsRequest $request): JsonResponse
    {
        if ($request->registrationEnabled) {
            $this->settingsService->enableRegistration();
        } else {
            $this->settingsService->disableRegistration();
        }
        if ($request->updateCheckEnabled !== null) {
            $this->updates->setEnabled($request->updateCheckEnabled);
        }

        return $this->json($this->response());
    }

    private function response(): SettingsResponse
    {
        return new SettingsResponse(
            registrationEnabled: $this->settingsService->isRegistrationEnabled(),
            updateCheckEnabled: $this->updates->isEnabled(),
        );
    }
}
