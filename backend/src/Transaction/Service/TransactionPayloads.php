<?php

namespace App\Transaction\Service;

use App\Transaction\DTO\CreateTransactionRequest;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Several transactions in one payload (a draft's parts, a file's rows), each
 * read and checked as the transaction form's payload is (#[MapRequestPayload]
 * can't type a list of them): every violation named `<list>[i].field`,
 * answered 422 like any form error.
 */
class TransactionPayloads
{
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @param string $list the payload's field, which names the violations
     * @return list<CreateTransactionRequest>
     */
    public function read(array $parts, string $list = 'parts'): array
    {
        $requests = [];
        $violations = new ConstraintViolationList();
        foreach (array_values($parts) as $index => $part) {
            try {
                $request = $this->denormalizer->denormalize($part, CreateTransactionRequest::class);
            } catch (SerializerException $e) {
                throw new BadRequestHttpException(\sprintf('%s[%d] is not a transaction.', $list, $index), $e);
            }
            foreach ($this->validator->validate($request) as $violation) {
                $violations->add(new ConstraintViolation(
                    $violation->getMessage(),
                    $violation->getMessageTemplate(),
                    $violation->getParameters(),
                    $violation->getRoot(),
                    "{$list}[{$index}].".$violation->getPropertyPath(),
                    $violation->getInvalidValue(),
                    $violation->getPlural(),
                    $violation->getCode(),
                    $violation->getConstraint(),
                ));
            }
            $requests[] = $request;
        }
        if (\count($violations) > 0) {
            throw new HttpException(422, 'Validation failed', new ValidationFailedException($parts, $violations));
        }

        return $requests;
    }
}
