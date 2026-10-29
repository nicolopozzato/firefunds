<?php

namespace App\Api\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * `Authorization: Bearer ff_…`: a personal access token (ApiToken) instead
 * of a login's JWT, which never starts that way (JwtIgnoringApiTokens keeps
 * the JWT authenticator off these). The token rides on the security token
 * (attribute `api_token`), so ApiTokenScopeListener can hold it to its scope.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator
{
    private const USED_PRECISION = '15 minutes';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function plainToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer '.ApiToken::PREFIX)) {
            return null;
        }

        return substr($header, \strlen('Bearer '));
    }

    public function supports(Request $request): ?bool
    {
        return self::plainToken($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $this->tokens->findUsable((string) self::plainToken($request))
            ?? throw new AuthenticationException('Unknown or revoked API token.');
        $request->attributes->set('_api_token', $apiToken);

        return new SelfValidatingPassport(new UserBadge($apiToken->getUser()->getUserIdentifier(), static fn () => $apiToken->getUser()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $apiToken = $request->attributes->get('_api_token');
        if ($apiToken instanceof ApiToken) {
            $token->setAttribute('api_token', $apiToken);
            $now = new \DateTimeImmutable();
            if ($apiToken->getLastUsedAt() === null || $apiToken->getLastUsedAt() < $now->modify('-'.self::USED_PRECISION)) {
                $this->entityManager->createQuery('UPDATE '.ApiToken::class.' t SET t.lastUsedAt = :now WHERE t.id = :id')
                    ->setParameter('now', $now)
                    ->setParameter('id', $apiToken->getId(), 'uuid')
                    ->execute();
            }
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['code' => 'invalid_api_token', 'message' => 'Unknown or revoked API token.'], Response::HTTP_UNAUTHORIZED);
    }
}
