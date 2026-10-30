<?php

namespace App\Admin\DTO;

final readonly class UpdateSettingsRequest
{
    public function __construct(
        public bool $registrationEnabled,
        /** Left out: stays as it is. */
        public ?bool $updateCheckEnabled = null,
    ) {
    }
}
