<?php

namespace App\Account\DTO;

use App\Core\Validation\DateValue;
use App\Core\Validation\Decimal;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * An account's other changes (AccountAdjustment) as sent by its form: a list
 * of `{id?, date, amount, description?}`, the amount signed. A violation's
 * field is e.g. `adjustments[0][amount]`.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class AdjustmentList extends Compound
{
    /** Plenty for a hand-kept list, and a bound on what one request writes. */
    public const MAX = 500;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Count(max: self::MAX),
            new Assert\All([
                new Assert\Collection(fields: [
                    'id' => new Assert\Optional([new Assert\Type('string'), new Assert\Uuid()]),
                    'date' => [new Assert\NotBlank(), new DateValue()],
                    'amount' => [new Assert\NotBlank(), new Decimal()],
                    'description' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
                ]),
            ]),
        ];
    }
}
