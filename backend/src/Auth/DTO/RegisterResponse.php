<?php

namespace App\Auth\DTO;

final readonly class RegisterResponse
{
    public function __construct(
        public string $message,
        public UserResponse $user,
        public bool $isFirstUser,
    ) {
    }
}
