<?php

namespace App\Core\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error the user gets to see: ExceptionListener sends its code and params,
 * which the frontend translates (see ErrorCode). Thrown by services as well as
 * controllers; the status defaults to 400.
 */
class ApiException extends HttpException
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $params = [],
        int $statusCode = Response::HTTP_BAD_REQUEST,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $errorCode->message($params), $previous);
    }

    public static function notFound(ErrorCode $errorCode): self
    {
        return new self($errorCode, [], Response::HTTP_NOT_FOUND);
    }
}
