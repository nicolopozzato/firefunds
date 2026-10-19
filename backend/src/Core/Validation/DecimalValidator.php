<?php

namespace App\Core\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DecimalValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Decimal) {
            throw new UnexpectedTypeException($constraint, Decimal::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        $integerDigits = $constraint->precision - $constraint->scale;
        $pattern = \sprintf('/^-?\d{1,%d}(\.\d{1,%d})?$/', $integerDigits, $constraint->scale);

        if (!\is_string($value) || 1 !== preg_match($pattern, $value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ scale }}', (string) $constraint->scale)
                ->setCode(ViolationCode::INVALID_NUMBER)
                ->addViolation();
        }
    }
}
