<?php

namespace App\Auth\Controller;

use App\Repository\UserRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class RefreshTokenController extends AbstractController
{
    public function __construct(
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserRepository $userRepository
    ) {
    }

    #[Route('/api/auth/refresh', name: 'api_refresh_token', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $refreshTokenString = $data['refresh_token'] ?? null;
        
        if (null === $refreshTokenString) {
            throw new BadRequestHttpException('Refresh token is missing');
        }

        $refreshToken = $this->refreshTokenManager->get($refreshTokenString);

        if (null === $refreshToken || !$refreshToken->isValid()) {
            throw new AuthenticationException('Invalid refresh token');
        }

        $username = $refreshToken->getUsername();
        $user = $this->userRepository->findByEmail($username);

        if (null === $user) {
            throw new AuthenticationException('User not found');
        }

        $token = $this->jwtManager->create($user);

        return $this->json([
            'token' => $token,
            'refresh_token' => $refreshTokenString,
        ]);
    }
}
