<?php

namespace App\Auth\Service;

use App\Auth\DTO\UserResponse;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Entity\User;
use App\Entity\UserTheme;
use App\Repository\UserRepository;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserService
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private SettingsService $settingsService,
        private RevokeRefreshTokenManagerInterface $refreshTokens,
    ) {
    }

    public function getUserCount(): int
    {
        return $this->userRepository->count([]);
    }

    public function isFirstUser(): bool
    {
        return $this->getUserCount() === 0;
    }

    public function canRegister(): bool
    {
        return $this->isFirstUser() || $this->settingsService->isRegistrationEnabled();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * @throws ApiException               if registration is disabled or the email is taken
     * @throws ValidationFailedException if the user isn't valid
     */
    public function createUser(
        string $firstName,
        string $lastName,
        string $email,
        string $plainPassword,
        array $roles = []
    ): User {
        // Check if registration is allowed
        if (!$this->canRegister()) {
            throw new ApiException(ErrorCode::RegistrationDisabled, [], Response::HTTP_FORBIDDEN);
        }

        // Check if email already exists
        if ($this->emailExists($email)) {
            throw new ApiException(ErrorCode::EmailAlreadyRegistered, [], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        // First user is admin
        if ($this->isFirstUser()) {
            $user->setRoles(['ROLE_ADMIN']);

            // Disable registration after first user
            $this->settingsService->disableRegistration();
        } else {
            $user->setRoles($roles);
        }

        // Validate user
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            throw new ValidationFailedException($user, $errors);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function getUserData(User $user): UserResponse
    {
        return UserResponse::fromEntity($user);
    }

    /**
     * A new email needs the current password: whoever holds a session for a
     * few minutes must not be able to take the account away for good.
     *
     * @throws ApiException if the email is already taken by another user, or
     *                      it changes and the current password doesn't match
     */
    public function updateProfile(User $user, string $firstName, string $lastName, string $email, ?string $currentPassword = null): User
    {
        if (strtolower($email) !== $user->getEmail()
            && !$this->passwordHasher->isPasswordValid($user, $currentPassword ?? '')) {
            throw new ApiException(ErrorCode::CurrentPasswordIncorrect);
        }

        $existing = $this->findByEmail($email);
        if ($existing !== null && !$existing->getId()->equals($user->getId())) {
            throw new ApiException(ErrorCode::EmailAlreadyRegistered, [], Response::HTTP_CONFLICT);
        }

        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);

        $this->entityManager->flush();

        return $user;
    }

    /**
     * Signs the user out everywhere: every refresh token is revoked, so each
     * device has to log in again with the new password once its access token
     * (lexik token_ttl) runs out. The frontend logs this one straight back in.
     *
     * @throws ApiException if the current password does not match
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new ApiException(ErrorCode::CurrentPasswordIncorrect);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        $this->refreshTokens->revokeAllForUser($user);
    }

    public function updatePreferences(
        User $user,
        string $locale,
        UserTheme $theme,
        int $pageSize,
        string $timezone,
        string $numberFormat,
        ?string $capitalGainsTaxRate = null,
    ): User {
        $user->setLocale($locale);
        $user->setTheme($theme);
        $user->setPageSize($pageSize);
        $user->setTimezone($timezone);
        $user->setNumberFormat($numberFormat);
        if ($capitalGainsTaxRate !== null && $capitalGainsTaxRate !== '') {
            $user->setCapitalGainsTaxRate($capitalGainsTaxRate);
        }

        $this->entityManager->flush();

        return $user;
    }
}
