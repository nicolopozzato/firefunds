<?php

namespace App\Api\Security;

use App\Entity\ApiToken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Holds a request made with a personal access token to the token's scope.
 * Never through a token: tokens themselves, administration, push devices,
 * the password. `drafts`: creating drafts (also from the phone's
 * notifications) and reading what fills them.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class ApiTokenScopeListener
{
    private const NEVER = '#^/api/(tokens|admin|push|auth/me/password)(/|$)#';
    private const DRAFTS = [
        'POST' => '#^/api/(drafts|bank-notifications)$#',
        'GET' => '#^/api/(accounts/[^/]+|categories|tags|auth/me)$#',
    ];

    public function __construct(private Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $token = $this->security->getToken();
        if (!$event->isMainRequest() || $token === null || !$token->hasAttribute('api_token')) {
            return;
        }
        /** @var ApiToken $apiToken */
        $apiToken = $token->getAttribute('api_token');
        $path = $event->getRequest()->getPathInfo();
        $method = $event->getRequest()->getMethod();

        $allowed = !preg_match(self::NEVER, $path) && match ($apiToken->getScope()) {
            ApiToken::SCOPE_FULL => true,
            ApiToken::SCOPE_DRAFTS => isset(self::DRAFTS[$method]) && preg_match(self::DRAFTS[$method], $path) === 1,
            default => false,
        };
        if (!$allowed) {
            $event->setResponse(new JsonResponse(
                ['code' => 'api_token_scope', 'message' => 'This API token may not do this.'],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }
}
