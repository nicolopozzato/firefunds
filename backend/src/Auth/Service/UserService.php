<?php

namespace App\Auth\Service;

use App\Auth\DTO\UpdatePreferencesRequest;
use App\Auth\DTO\UserResponse;
use App\Core\Exception\ApiException;
use App\Core\Exception\ErrorCode;
use App\Currency\Service\ExchangeRateService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\User;
use App\Entity\UserTheme;
use App\Repository\AccountRepository;
use App\Repository\CurrencyRepository;
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
        private CurrencyRepository $currencyRepository,
        private ExchangeRateService $exchangeRates,
        private AccountRepository $accountRepository,
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

        // First user is admin, and closes registration
        if ($this->isFirstUser()) {
            $user = $this->addUser($firstName, $lastName, $email, $plainPassword, ['ROLE_ADMIN']);
            $this->settingsService->disableRegistration();

            return $user;
        }

        return $this->addUser($firstName, $lastName, $email, $plainPassword, $roles);
    }

    /**
     * A new user, whether registration is open or not: registration goes
     * through createUser(), an administrator comes straight here.
     *
     * @param list<string> $roles
     *
     * @throws ApiException               if the email is taken
     * @throws ValidationFailedException if the user isn't valid
     */
    public function addUser(
        string $firstName,
        string $lastName,
        string $email,
        string $plainPassword,
        array $roles = [],
        bool $mustChangePassword = false,
    ): User {
        if ($this->emailExists($email)) {
            throw new ApiException(ErrorCode::EmailAlreadyRegistered, [], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->setRoles($roles);
        $user->setMustChangePassword($mustChangePassword);

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
     * A password an administrator set to be changed is replaced without
     * asking for it again (the user just logged in with it).
     *
     * @throws ApiException if the current password does not match, or the new one is the same
     */
    public function changePassword(User $user, string $newPassword, ?string $currentPassword = null): void
    {
        if (!$user->mustChangePassword() && !$this->passwordHasher->isPasswordValid($user, $currentPassword ?? '')) {
            throw new ApiException(ErrorCode::CurrentPasswordIncorrect);
        }
        if ($this->passwordHasher->isPasswordValid($user, $newPassword)) {
            throw new ApiException(ErrorCode::PasswordUnchanged);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $user->setMustChangePassword(false);
        $this->entityManager->flush();

        $this->refreshTokens->revokeAllForUser($user);
    }

    public function updatePreferences(User $user, UpdatePreferencesRequest $request): User
    {
        if ($request->locale !== null) {
            $user->setLocale($request->locale);
        }
        if ($request->theme !== null) {
            $user->setTheme(UserTheme::from($request->theme));
        }
        if ($request->pageSize !== null) {
            $user->setPageSize($request->pageSize);
        }
        if ($request->timezone !== null) {
            $user->setTimezone($request->timezone);
        }
        if ($request->numberFormat !== null) {
            $user->setNumberFormat($request->numberFormat);
        }
        if ($request->capitalGainsTaxRate !== null && $request->capitalGainsTaxRate !== '') {
            $user->setCapitalGainsTaxRate($request->capitalGainsTaxRate);
        }
        if ($request->currency !== null && $request->currency !== '') {
            $found = $this->currencyRepository->findOneByCode($request->currency);
            if ($found === null) {
                throw new ApiException(ErrorCode::CurrencyNotAvailable, ['currency' => $request->currency]);
            }
            $user->setCurrency($found->getCode());
        }
        if ($request->density !== null) {
            $user->setDensity($request->density);
        }
        if ($request->startPage !== null) {
            $user->setStartPage($request->startPage);
        }
        if ($request->startPeriod !== null) {
            $user->setStartPeriod($request->startPeriod);
        }
        if ($request->defaultAccountId !== null) {
            $user->setDefaultAccount($this->defaultAccount($user, $request->defaultAccountId));
        }
        if ($request->dashboardSections !== null) {
            $user->setDashboardSections(array_map(
                static fn (array $section): array => ['key' => $section['key'], 'visible' => $section['visible']],
                array_values($request->dashboardSections),
            ));
        }
        if ($request->dashboardTrendView !== null) {
            $user->setDashboardTrendView($request->dashboardTrendView);
        }
        if ($request->dashboardInvestmentUnit !== null) {
            $user->setDashboardInvestmentUnit($request->dashboardInvestmentUnit);
        }
        if ($request->pushReminders !== null) {
            $user->setPushReminders($request->pushReminders);
        }
        if ($request->pushDrafts !== null) {
            $user->setPushDrafts($request->pushDrafts);
        }
        if ($request->pushSystem !== null) {
            $user->setPushSystem($request->pushSystem);
        }
        if ($request->pushSummaryTime !== null) {
            $user->setPushSummaryTime($request->pushSummaryTime);
        }

        $this->entityManager->flush();
        // A new preferred currency may be one rates are needed for.
        $this->exchangeRates->syncForUser($user);

        return $user;
    }

    /** "" = none; otherwise one of the user's active asset accounts. */
    private function defaultAccount(User $user, string $id): ?Account
    {
        if ($id === '') {
            return null;
        }
        $account = $this->accountRepository->findOneByUserAndId($user, $id);
        if ($account === null || $account->getType() !== AccountType::Asset || !$account->isActive()) {
            throw new ApiException(ErrorCode::AccountNotFound);
        }

        return $account;
    }
}
