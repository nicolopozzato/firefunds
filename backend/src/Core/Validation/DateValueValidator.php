<?php

namespace App\Core\Validation;

use App\Core\Period\CalendarRange;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DateValueValidator extends ConstraintValidator
{
    private const string DATE = '(\d{4})-(\d{2})-(\d{2})';
    private const string TIME = '[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?';

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DateValue) {
            throw new UnexpectedTypeException($constraint, DateValue::class);
        }
        if (null === $value || '' === $value) {
            return;
        }

        if (!$this->isValid($value, $constraint->withTime)) {
            $this->context->buildViolation($constraint->message)
                ->setCode(ViolationCode::INVALID_DATE)
                ->addViolation();
        }
    }

    private function isValid(mixed $value, bool $withTime): bool
    {
        $pattern = '/^'.self::DATE.($withTime ? '('.self::TIME.')?' : '').'$/';

        if (!\is_string($value) || 1 !== preg_match($pattern, $value, $match)) {
            return false;
        }

        [, $year, $month, $day] = $match;

        // checkdate() rejects 2026-02-31, which the parser would roll over to March.
        if (!checkdate((int) $month, (int) $day, (int) $year) || !CalendarRange::isSupportedYear((int) $year)) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
        } catch (\Exception) {
            return false;
        }

        return true;
    }
}
