<?php

namespace App\Auth\DTO;

final readonly class LoginResponse
{
    public function __construct(
        public string $token,
        public string $refresh_token,
    ) {
    }
}
