<?php

namespace App\Tests\Integration\Service;

use App\Transaction\DTO\CreateTransactionRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateTransactionRequestValidationTest extends KernelTestCase
{
    /**
     * The description is the row title in every transaction list, so a money
     * transaction can't be saved without one (investment trades fall back to
     * the instrument name and keep it optional).
     */
    public function testDescriptionIsRequired(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);

        foreach ([null, '', '   '] as $description) {
            $violations = $validator->validate($this->request($description));

            self::assertCount(1, $violations, \sprintf('description %s', var_export($description, true)));
            self::assertSame('description', $violations[0]->getPropertyPath());
        }

        self::assertCount(0, $validator->validate($this->request('Spesa al supermercato')));
    }

    private function request(?string $description): CreateTransactionRequest
    {
        return new CreateTransactionRequest(
            description: $description,
            sourceAccountId: '01a0e360-2fbc-7f26-879e-3c4120ac1237',
            destinationAccountId: '01a0e360-2fbc-7f26-879e-3c4120ac1238',
            occurredAt: '2026-09-30T10:00:00+02:00',
            amount: '10.00',
        );
    }
}
