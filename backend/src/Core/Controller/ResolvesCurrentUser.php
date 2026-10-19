<?php

namespace App\Core\Controller;

use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;

/**
 * The logged-in user, for controllers behind the `api` firewall: there is
 * always one there, this only gives it its type.
 */
trait ResolvesCurrentUser
{
    private function getCurrentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw ApiException::notFound(ErrorCode::UserNotFound);
        }

        return $user;
    }
}
