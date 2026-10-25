<?php

namespace App\Auth\EventListener;

use App\Core\Exception\ErrorCode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Right password, disabled user (UserChecker): the login answers 403 with
 * its code instead of the JWT handler's 401, so the app can say why. A
 * request with a token of a disabled user stays a plain 401, which ends the
 * session.
 */
#[AsEventListener]
class DisabledUserLoginListener
{
    public function __invoke(LoginFailureEvent $event): void
    {
        // Symfony hides account status errors behind a plain "bad credentials"
        // (expose_security_errors); the password was right, so it can be told.
        $exception = $event->getException();
        $disabled = $exception instanceof DisabledException || $exception->getPrevious() instanceof DisabledException;
        if ('login' !== $event->getFirewallName() || !$disabled) {
            return;
        }

        // Same shape as the ExceptionListener's errors.
        $event->setResponse(new JsonResponse(
            [
                'error' => true,
                'message' => ErrorCode::AccountDisabled->message(),
                'status' => Response::HTTP_FORBIDDEN,
                'code' => ErrorCode::AccountDisabled->value,
                'params' => [],
            ],
            Response::HTTP_FORBIDDEN,
        ));
    }
}
