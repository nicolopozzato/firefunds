<?php

namespace App\Auth\Controller;

use App\Auth\DTO\ChangePasswordRequest;
use App\Auth\DTO\CheckRegistrationResponse;
use App\Auth\DTO\RegisterRequest;
use App\Auth\DTO\RegisterResponse;
use App\Auth\DTO\UpdatePreferencesRequest;
use App\Auth\DTO\UpdateProfileRequest;
use App\Auth\DTO\UserResponse;
use App\Auth\Service\UserService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use App\Entity\UserTheme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
    ) {
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        // This endpoint is handled by the JSON login authenticator
        // If we reach here, authentication was successful
        return $this->json(['message' => 'Login successful']);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(
        #[MapRequestPayload] RegisterRequest $request
    ): JsonResponse {
        // Refusals (registration disabled, email taken, invalid user) are
        // exceptions the ExceptionListener answers with their code.
        $isFirstUser = $this->userService->isFirstUser();

        $user = $this->userService->createUser(
            firstName: $request->firstName,
            lastName: $request->lastName,
            email: $request->email,
            plainPassword: $request->password
        );

        $response = new RegisterResponse(
            message: 'User registered successfully',
            user: UserResponse::fromEntity($user),
            isFirstUser: $isFirstUser,
        );

        return $this->json($response, 201);
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        return $this->json(UserResponse::fromEntity($user));
    }

    #[Route('/check-registration', name: 'api_auth_check_registration', methods: ['GET'])]
    public function checkRegistration(): JsonResponse
    {
        $response = new CheckRegistrationResponse(
            registrationAllowed: $this->userService->canRegister(),
            hasUsers: !$this->userService->isFirstUser(),
        );

        return $this->json($response);
    }

    #[Route('/me', name: 'api_auth_update_profile', methods: ['PUT', 'PATCH'])]
    public function updateProfile(#[MapRequestPayload] UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->userService->updateProfile(
            $this->getCurrentUser(),
            $request->firstName,
            $request->lastName,
            $request->email,
        );

        return $this->json(UserResponse::fromEntity($user));
    }

    #[Route('/me/password', name: 'api_auth_change_password', methods: ['PUT'])]
    public function changePassword(#[MapRequestPayload] ChangePasswordRequest $request): JsonResponse
    {
        $this->userService->changePassword($this->getCurrentUser(), $request->currentPassword, $request->newPassword);

        return $this->json(['message' => 'Password updated successfully']);
    }

    #[Route('/me/preferences', name: 'api_auth_update_preferences', methods: ['PUT'])]
    public function updatePreferences(#[MapRequestPayload] UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $this->userService->updatePreferences(
            $this->getCurrentUser(),
            $request->locale,
            UserTheme::from($request->theme),
            $request->pageSize,
            $request->timezone,
            $request->numberFormat,
            $request->capitalGainsTaxRate,
        );

        return $this->json(UserResponse::fromEntity($user));
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
