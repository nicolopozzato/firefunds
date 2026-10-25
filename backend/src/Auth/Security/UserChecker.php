<?php

namespace App\Auth\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A disabled user (User::$disabledAt) gets in nowhere: not with the password,
 * a refresh token or an access token still running, so every session ends at
 * its next request. Checked after the credentials, so a wrong password never
 * tells whether the account exists. The login answers with its code
 * (DisabledUserLoginListener).
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new DisabledException('This user has been disabled.');
        }
    }
}
