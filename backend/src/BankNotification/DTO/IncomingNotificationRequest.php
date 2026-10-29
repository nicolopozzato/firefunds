<?php

namespace App\BankNotification\DTO;

use App\Core\Validation\DateValue;
use Symfony\Component\Validator\Constraints as Assert;

/** A notification as the Android app sends it: from which app, what it said, when. */
final readonly class IncomingNotificationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $package,

        #[Assert\NotBlank]
        #[Assert\Length(max: 120)]
        public string $appName,

        #[Assert\Length(max: 1000)]
        public string $title = '',

        #[Assert\Length(max: 4000)]
        public string $text = '',

        #[Assert\NotBlank]
        #[DateValue(withTime: true)]
        public string $postedAt = '',

        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = 'UTC',

        /** The phone's own id of it: the same one sent again is kept once. */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $key = '',
    ) {
    }
}
