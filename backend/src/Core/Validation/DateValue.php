<?php

namespace App\Core\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A date (Y-m-d) or, with `withTime`, an ISO 8601 date and time, that exists
 * and lies within CalendarRange's supported years. Nothing relative ("+5
 * years", "tomorrow"), which `new \DateTimeImmutable()` would accept.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class DateValue extends Constraint
{
    public function __construct(
        public bool $withTime = false,
        public string $message = 'This value is not a valid date.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
