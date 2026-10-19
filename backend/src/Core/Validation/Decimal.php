<?php

namespace App\Core\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * A number written as the database stores it: digits, an optional dot and at
 * most `scale` decimals, no more than `precision` digits in all, maybe a
 * leading minus. The sign is left to Positive/PositiveOrZero, which compare
 * loosely ("NaN" and "abc" pass them): every number field needs this too.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Decimal extends Constraint
{
    public function __construct(
        public int $precision = 19,
        public int $scale = 4,
        public string $message = 'This value is not a valid number with at most {{ scale }} decimals.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
