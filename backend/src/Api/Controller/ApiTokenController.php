<?php

namespace App\Api\Controller;

use App\Api\DTO\CreateApiTokenRequest;
use App\Core\Controller\ResolvesCurrentUser;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/** The user's personal access tokens: made (shown once), listed, revoked. Never through a token itself. */
#[Route('/api/tokens')]
class ApiTokenController extends AbstractController
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'api_tokens_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(array_map(self::toArray(...), $this->tokens->findActiveByUser($this->getCurrentUser())));
    }

    /** The token itself is in this answer only: it is kept as a hash. */
    #[Route('', name: 'api_tokens_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateApiTokenRequest $request): JsonResponse
    {
        $plain = ApiToken::PREFIX.self::randomPart();
        $token = new ApiToken($this->getCurrentUser(), trim($request->name), $request->scope, $plain, $this->clock->now());
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $this->json([...self::toArray($token), 'token' => $plain], 201);
    }

    #[Route('/{id}', name: 'api_tokens_revoke', methods: ['DELETE'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function revoke(string $id): JsonResponse
    {
        $token = $this->tokens->findOneByUserAndId($this->getCurrentUser(), $id)
            ?? throw ApiException::notFound(ErrorCode::ApiTokenNotFound);
        $token->revoke($this->clock->now());
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    /** 40 characters from [0-9A-Za-z]: about 238 bits. */
    private static function randomPart(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $part = '';
        for ($i = 0; $i < 40; ++$i) {
            $part .= $alphabet[random_int(0, 61)];
        }

        return $part;
    }

    /** @return array<string, mixed> */
    private static function toArray(ApiToken $token): array
    {
        return [
            'id' => (string) $token->getId(),
            'name' => $token->getName(),
            'scope' => $token->getScope(),
            'hint' => $token->getHint(),
            'createdAt' => $token->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastUsedAt' => $token->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
