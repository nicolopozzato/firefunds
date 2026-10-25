<?php

namespace App\Auth\EventListener;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A user whose password an administrator set "to be changed at the next
 * login" (User::$mustChangePassword) gets only who they are and the change
 * of password until they choose one; anything else is a 403 with its code.
 * After the firewall (priority 8), so the user is known.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class PasswordChangeRequiredListener
{
    private const ALLOWED_ROUTES = ['api_auth_me', 'api_auth_change_password'];

    public function __construct(private Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        if (\in_array($event->getRequest()->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        throw new ApiException(ErrorCode::PasswordChangeRequired, [], Response::HTTP_FORBIDDEN);
    }
}
