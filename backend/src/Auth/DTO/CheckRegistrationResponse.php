<?php

namespace App\Auth\DTO;

final readonly class CheckRegistrationResponse
{
    public function __construct(
        public bool $registrationAllowed,
        public bool $hasUsers,
    ) {
    }
}
