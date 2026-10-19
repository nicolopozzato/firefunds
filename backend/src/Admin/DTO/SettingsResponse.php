<?php

namespace App\Admin\DTO;

final readonly class SettingsResponse
{
    public function __construct(
        public bool $registrationEnabled,
    ) {
    }
}
