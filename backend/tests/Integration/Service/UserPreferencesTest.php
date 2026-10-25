<?php

namespace App\Tests\Integration\Service;

use App\Auth\DTO\UpdatePreferencesRequest;
use App\Auth\DTO\UserResponse;
use App\Auth\Service\UserService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserPreferencesTest extends KernelTestCase
{
    public function testNumberFormatDefaultsToItalianAndCanBeChanged(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setFirstName('Prefs');
        $user->setLastName('Test');
        $user->setEmail(\sprintf('prefs-test-%s@example.test', uniqid()));
        $user->setPassword('hashed');
        $entityManager->persist($user);
        $entityManager->flush();

        self::assertSame('it-IT', UserResponse::fromEntity($user)->numberFormat);

        $container->get(UserService::class)->updatePreferences($user, new UpdatePreferencesRequest('en', 'system', 50, 'Europe/Rome', 'en-US'));
        $entityManager->clear();

        $reloaded = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertSame('en-US', $reloaded->getNumberFormat());
        self::assertSame('en', $reloaded->getLocale());
    }

    public function testNumberFormatMustBeOneOfTheSupportedOnes(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        foreach (UpdatePreferencesRequest::NUMBER_FORMATS as $numberFormat) {
            self::assertCount(0, $validator->validate($this->request($numberFormat)), $numberFormat);
        }

        foreach (['', 'xx-XX', 'it'] as $numberFormat) {
            $violations = $validator->validate($this->request($numberFormat));

            self::assertGreaterThan(0, \count($violations), var_export($numberFormat, true));
            self::assertSame('numberFormat', $violations[0]->getPropertyPath());
        }
    }

    private function request(string $numberFormat): UpdatePreferencesRequest
    {
        return new UpdatePreferencesRequest(
            locale: 'it',
            theme: 'system',
            pageSize: 50,
            timezone: 'Europe/Rome',
            numberFormat: $numberFormat,
        );
    }
}
