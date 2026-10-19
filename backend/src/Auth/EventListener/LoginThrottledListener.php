<?php

namespace App\Auth\EventListener;

use App\Core\Exception\ErrorCode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Too many failed logins (login_throttling in security.yaml): answers 429 with
 * the minutes to wait instead of the JWT handler's 401, so the client can tell
 * it apart from wrong credentials.
 */
#[AsEventListener]
class LoginThrottledListener
{
    public function __invoke(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $minutes = $exception->getMessageData()['%count%'];

        // Same shape as the ExceptionListener's errors.
        $event->setResponse(new JsonResponse(
            [
                'error' => true,
                'message' => ErrorCode::TooManyLoginAttempts->message(['minutes' => $minutes]),
                'status' => Response::HTTP_TOO_MANY_REQUESTS,
                'code' => ErrorCode::TooManyLoginAttempts->value,
                'params' => ['minutes' => $minutes],
            ],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => $minutes * 60],
        ));
    }
}
