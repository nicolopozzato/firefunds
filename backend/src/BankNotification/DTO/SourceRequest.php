<?php

namespace App\BankNotification\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SourceRequest
{
    /**
     * @param list<array{kind: string, pattern: string}> $rules
     */
    public function __construct(
        #[Assert\Uuid]
        public ?string $accountId = null,

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Collection(fields: [
            'kind' => [new Assert\Choice(choices: ['out', 'in', 'ignore'])],
            'pattern' => [new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 500)],
        ])])]
        public array $rules = [],
    ) {
    }
}
