<?php

namespace App\Admin\Controller;

use App\Admin\DTO\AdminUserResponse;
use App\Admin\DTO\CreateUserRequest;
use App\Admin\DTO\SetPasswordRequest;
use App\Admin\DTO\UpdateUserRequest;
use App\Admin\Service\UserAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UsersController extends AbstractController
{
    public function __construct(
        private readonly UserAdminService $users,
    ) {
    }

    #[Route('', name: 'api_admin_users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(AdminUserResponse::fromEntity(...), $this->users->list()));
    }

    #[Route('', name: 'api_admin_users_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateUserRequest $request): JsonResponse
    {
        $user = $this->users->create(
            $request->firstName,
            $request->lastName,
            $request->email,
            $request->password,
            $request->isAdmin,
            $request->mustChangePassword,
        );

        return $this->json(AdminUserResponse::fromEntity($user), 201);
    }

    #[Route('/{id}', name: 'api_admin_users_update', methods: ['PUT'])]
    public function update(string $id, #[MapRequestPayload] UpdateUserRequest $request): JsonResponse
    {
        $user = $this->users->update(
            $this->users->get($id),
            $request->firstName,
            $request->lastName,
            $request->email,
            $request->isAdmin,
        );

        return $this->json(AdminUserResponse::fromEntity($user));
    }

    #[Route('/{id}/password', name: 'api_admin_users_password', methods: ['PUT'])]
    public function setPassword(string $id, #[MapRequestPayload] SetPasswordRequest $request): JsonResponse
    {
        $user = $this->users->setPassword($this->users->get($id), $request->password, $request->mustChangePassword);

        return $this->json(AdminUserResponse::fromEntity($user));
    }

    #[Route('/{id}/disable', name: 'api_admin_users_disable', methods: ['POST'])]
    public function disable(string $id): JsonResponse
    {
        return $this->json(AdminUserResponse::fromEntity($this->users->disable($this->users->get($id))));
    }

    #[Route('/{id}/enable', name: 'api_admin_users_enable', methods: ['POST'])]
    public function enable(string $id): JsonResponse
    {
        return $this->json(AdminUserResponse::fromEntity($this->users->enable($this->users->get($id))));
    }
}
