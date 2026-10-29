<?php

namespace App\Api\DTO;

use App\Entity\ApiToken;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateApiTokenRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100)]
        public string $name,

        #[Assert\Choice(choices: ApiToken::SCOPES)]
        public string $scope = ApiToken::SCOPE_FULL,
    ) {
    }
}
