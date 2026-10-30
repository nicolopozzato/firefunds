<?php

namespace App\Update\Controller;

use App\Update\UpdateCheck;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Whether a newer FireFunds is out, as last checked: for the administrators' notice. */
#[IsGranted('ROLE_ADMIN')]
class UpdateController extends AbstractController
{
    public function __construct(private readonly UpdateCheck $updates)
    {
    }

    #[Route('/api/admin/update', name: 'api_admin_update', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json($this->updates->status());
    }
}
