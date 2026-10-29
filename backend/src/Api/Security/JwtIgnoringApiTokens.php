<?php

namespace App\Api\Security;

use App\Entity\ApiToken;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * The JWT authenticator takes any Bearer token and answers 401 when it isn't
 * a JWT, before another authenticator is asked: personal access tokens
 * (`ff_…`, ApiTokenAuthenticator) are not its business.
 */
#[AsDecorator('lexik_jwt_authentication.extractor.chain_extractor')]
final class JwtIgnoringApiTokens implements TokenExtractorInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly TokenExtractorInterface $inner,
    ) {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);

        return \is_string($token) && str_starts_with($token, ApiToken::PREFIX) ? false : $token;
    }
}
