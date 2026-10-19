<?php

namespace App\Dashboard\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DashboardAccountsRequest
{
    public function __construct(
        /** The asset accounts to show; every other one of the user's asset accounts is hidden. */
        #[Assert\All([new Assert\Uuid()])]
        public array $visibleAccountIds = [],
    ) {
    }
}
