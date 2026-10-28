<?php

namespace App\Notification\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** What the browser's PushManager gives for this device, and its name in the user's list. */
final readonly class PushSubscriptionRequest
{
    /**
     * @param array{p256dh?: string, auth?: string} $keys
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        #[Assert\Length(max: 2000)]
        public string $endpoint,

        #[Assert\Collection(fields: [
            'p256dh' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            'auth' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
        ], allowExtraFields: true)]
        public array $keys,

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $label,
    ) {
    }
}
