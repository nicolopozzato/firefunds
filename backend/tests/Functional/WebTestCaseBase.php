<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

/**
 * Base class for functional tests that works around Symfony 8.1 test configuration issues.
 * Ensures the kernel is properly configured before creating the test client.
 *
 * Requests go through the real `api` firewall, exactly as in production: use
 * createAuthenticatedClient(), or createUser() + authenticate(), for endpoints
 * that need a logged-in user.
 */
abstract class WebTestCaseBase extends BaseWebTestCase
{
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        // Ensure previous kernel is shutdown
        static::ensureKernelShutdown();

        // Create client (this will boot the kernel internally)
        return parent::createClient($options, $server);
    }

    /**
     * A client logged in as a brand-new user. Use createClient() + createUser()
     * + authenticate() instead when the test needs the user itself.
     */
    protected static function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        static::authenticate($client, static::createUser());

        return $client;
    }

    /**
     * Persists a user with a unique email. Needs a booted kernel, so call it
     * after createClient(). Rolled back after the test like any other data.
     */
    protected static function createUser(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setFirstName('Functional');
        $user->setLastName('Test');
        $user->setEmail(\sprintf('functional-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * Sends a real JWT for $user on every following request of $client, the
     * same Authorization header the frontend sends after logging in.
     */
    protected static function authenticate(KernelBrowser $client, User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }
}
