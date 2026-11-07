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
 *
 * Decided on the route the router matched, never on the path: the router
 * decodes the path (/api/%74okens is /api/tokens), a pattern on the raw
 * path would let such a request through.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class ApiTokenScopeListener
{
    private const NEVER = '#^(api_tokens_|api_admin_|api_push_|api_auth_change_password$)#';
    private const DRAFTS = [
        'api_drafts_create',
        'api_bank_notifications_receive',
        'api_accounts_list',
        'api_accounts_get',
        'api_categories_list',
        'api_tags_list',
        'api_auth_me',
        'api_openapi',
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
        $route = $event->getRequest()->attributes->get('_route');

        $allowed = \is_string($route) && !preg_match(self::NEVER, $route) && match ($apiToken->getScope()) {
            ApiToken::SCOPE_FULL => true,
            ApiToken::SCOPE_DRAFTS => \in_array($route, self::DRAFTS, true),
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
