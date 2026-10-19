<?php

namespace App\Core\EventListener;

use App\Core\Exception\ApiException;
use App\Core\Validation\ViolationCode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: 'kernel.exception')]
class ExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        // Only handle API requests
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $statusCode = 500;
        $message = 'An error occurred';
        $data = [];

        // Handle different exception types
        if ($exception instanceof ApiException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
            $data['code'] = $exception->errorCode->value;
            $data['params'] = $exception->params;
        } elseif ($exception instanceof ValidationFailedException
            || $exception->getPrevious() instanceof ValidationFailedException) {
            // Thrown as is (e.g. validating an entity: 400), or wrapped by
            // #[MapRequestPayload] in its own HttpException (422).
            $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 400;
            $message = 'Validation failed';
            $validation = $exception instanceof ValidationFailedException ? $exception : $exception->getPrevious();
            $data['violations'] = [];
            foreach ($validation->getViolations() as $violation) {
                $data['violations'][] = [
                    'propertyPath' => $violation->getPropertyPath(),
                    'code' => ViolationCode::of($violation),
                    'params' => ViolationCode::paramsOf($violation),
                    'message' => $violation->getMessage(),
                ];
            }
        } elseif ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        } elseif ($exception instanceof AuthenticationException) {
            $statusCode = 401;
            $message = $exception->getMessage() ?: 'Invalid credentials';
        }

        // `message` is English, for logs and API clients: the app shows `code`
        // (ErrorCode) or the violations' codes (ViolationCode), translated.
        $data = [
            'error' => true,
            'message' => $message,
            'status' => $statusCode,
        ] + $data;

        // In dev mode, add debug information
        if ($_ENV['APP_ENV'] === 'dev') {
            $data['debug'] = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $response = new JsonResponse($data, $statusCode);
        $event->setResponse($response);
    }
}
