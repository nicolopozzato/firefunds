<?php

namespace App\Admin\Service;

use App\Auth\Service\UserService;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What an administrator does to users (Administration → Users). Nobody is
 * ever deleted: a user is disabled instead (User::$disabledAt), keeping all
 * their data, and can be turned back on. There is always at least one
 * active administrator.
 */
class UserAdminService
{
    public function __construct(
        private UserRepository $userRepository,
        private UserService $userService,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private RevokeRefreshTokenManagerInterface $refreshTokens,
    ) {
    }

    /**
     * Active users first, then the disabled ones; each by email.
     *
     * @return list<User>
     */
    public function list(): array
    {
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);
        usort($users, static fn (User $a, User $b) => $b->isActive() <=> $a->isActive());

        return $users;
    }

    public function get(string $id): User
    {
        $user = Uuid::isValid($id) ? $this->userRepository->find(Uuid::fromString($id)) : null;

        return $user ?? throw ApiException::notFound(ErrorCode::UserNotFound);
    }

    public function create(
        string $firstName,
        string $lastName,
        string $email,
        string $password,
        bool $isAdmin,
        bool $mustChangePassword,
    ): User {
        return $this->userService->addUser(
            $firstName,
            $lastName,
            $email,
            $password,
            $isAdmin ? ['ROLE_ADMIN'] : [],
            $mustChangePassword,
        );
    }

    /**
     * The email can change without the user's password, unlike on their own
     * profile: the administrator is the one fixing it. Tokens name the user
     * by id, so their sessions carry on.
     */
    public function update(User $user, string $firstName, string $lastName, string $email, bool $isAdmin): User
    {
        $existing = $this->userRepository->findByEmail($email);
        if (null !== $existing && !$existing->getId()->equals($user->getId())) {
            throw new ApiException(ErrorCode::EmailAlreadyRegistered, [], Response::HTTP_CONFLICT);
        }
        if (!$isAdmin && $this->isLastActiveAdministrator($user)) {
            throw new ApiException(ErrorCode::LastAdministrator, [], Response::HTTP_CONFLICT);
        }

        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);
        // ROLE_USER is implied (User::getRoles()), never stored.
        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']));
        $user->setRoles($isAdmin ? [...$roles, 'ROLE_ADMIN'] : $roles);

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            throw new ValidationFailedException($user, $errors);
        }

        $this->entityManager->flush();

        return $user;
    }

    /**
     * A new password chosen by the administrator. Every session of the user
     * ends once its access token runs out, as when they change it themselves.
     */
    public function setPassword(User $user, string $password, bool $mustChangePassword): User
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setMustChangePassword($mustChangePassword);
        $this->entityManager->flush();

        $this->refreshTokens->revokeAllForUser($user);

        return $user;
    }

    /**
     * Can't log in any more, and every session ends at its next request
     * (App\Auth\Security\UserChecker).
     */
    public function disable(User $user): User
    {
        if (!$user->isActive()) {
            return $user;
        }
        if ($this->isLastActiveAdministrator($user)) {
            throw new ApiException(ErrorCode::LastAdministrator, [], Response::HTTP_CONFLICT);
        }

        $user->setDisabledAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->refreshTokens->revokeAllForUser($user);

        return $user;
    }

    public function enable(User $user): User
    {
        $user->setDisabledAt(null);
        $this->entityManager->flush();

        return $user;
    }

    private function isLastActiveAdministrator(User $user): bool
    {
        if (!$user->isAdmin() || !$user->isActive()) {
            return false;
        }

        foreach ($this->userRepository->findBy(['disabledAt' => null]) as $other) {
            if ($other->isAdmin() && !$other->getId()->equals($user->getId())) {
                return false;
            }
        }

        return true;
    }
}
