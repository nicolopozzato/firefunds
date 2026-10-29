<?php

namespace App\CsvImport\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ImportPresetRequest
{
    /**
     * @param array<string, mixed> $settings how the app reads the file (its own format)
     * @param list<string>         $headers  the file's first row, to recognise the bank's next file
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\Uuid]
        public ?string $accountId = null,

        #[Assert\Count(max: 50)]
        public array $settings = [],

        #[Assert\Count(max: 200)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 255)])]
        public array $headers = [],
    ) {
    }
}
