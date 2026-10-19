<?php

namespace App\Core\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when deleting something that other data still depends on, and the
 * caller hasn't said what should happen to that data: a 409 Conflict.
 */
class InUseException extends ApiException
{
    /**
     * @param array<string, string|int> $params
     */
    public function __construct(ErrorCode $errorCode, array $params = [])
    {
        parent::__construct($errorCode, $params, Response::HTTP_CONFLICT);
    }
}
