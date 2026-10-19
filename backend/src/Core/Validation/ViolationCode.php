<?php

namespace App\Core\Validation;

use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * The code a validation error is sent with (ExceptionListener), which the
 * frontend translates from `errors.validation.<code>`, the field from
 * `errors.fields.<propertyPath>`: a generic code from the constraint that
 * failed, or one of the app's own, set with ->setCode() in a Callback check.
 */
final class ViolationCode
{
    public const REQUIRED = 'required';
    public const TOO_SHORT = 'too_short';
    public const TOO_LONG = 'too_long';
    public const WRONG_LENGTH = 'wrong_length';
    public const MUST_BE_POSITIVE = 'must_be_positive';
    public const MUST_NOT_BE_NEGATIVE = 'must_not_be_negative';
    public const OUT_OF_RANGE = 'out_of_range';
    public const INVALID_EMAIL = 'invalid_email';
    public const INVALID_CHOICE = 'invalid_choice';
    /** A value of the wrong type, a missing or an unexpected field (payload mapping). */
    public const INVALID_TYPE = 'invalid_type';
    public const INVALID = 'invalid';

    // Set by the DTOs' Callback checks.
    public const CURRENCY_REQUIRED_FOR_ASSET = 'currency_required_for_asset';
    public const KIND_REQUIRED_FOR_ASSET = 'kind_required_for_asset';
    public const TRANSACTION_ACCOUNT_REQUIRED = 'transaction_account_required';
    public const TRANSACTION_ACCOUNTS_SAME = 'transaction_accounts_same';
    public const FOREIGN_CURRENCY_REQUIRED = 'foreign_currency_required';

    /** Placeholders the frontend texts use. */
    private const PARAMS = ['limit', 'min', 'max'];

    public static function of(ConstraintViolationInterface $violation): string
    {
        $constraint = $violation->getConstraint();
        $code = $violation->getCode();

        return match (true) {
            $constraint === null => self::INVALID_TYPE,
            $constraint instanceof Callback => $code ?? self::INVALID,
            $constraint instanceof NotBlank, $constraint instanceof NotNull => self::REQUIRED,
            $constraint instanceof Length => match ($code) {
                Length::TOO_SHORT_ERROR => self::TOO_SHORT,
                Length::TOO_LONG_ERROR => self::TOO_LONG,
                Length::NOT_EQUAL_LENGTH_ERROR => self::WRONG_LENGTH,
                default => self::INVALID,
            },
            $constraint instanceof Positive => self::MUST_BE_POSITIVE,
            $constraint instanceof PositiveOrZero => self::MUST_NOT_BE_NEGATIVE,
            $constraint instanceof Range => self::OUT_OF_RANGE,
            $constraint instanceof Email => self::INVALID_EMAIL,
            $constraint instanceof Choice => self::INVALID_CHOICE,
            default => self::INVALID,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function paramsOf(ConstraintViolationInterface $violation): array
    {
        $params = [];
        foreach ($violation->getParameters() as $placeholder => $value) {
            $name = trim($placeholder, '{} ');
            if (\in_array($name, self::PARAMS, true)) {
                $params[$name] = (string) $value;
            }
        }

        return $params;
    }
}
