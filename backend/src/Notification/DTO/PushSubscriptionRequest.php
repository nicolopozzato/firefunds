<?php

namespace App\Notification\DTO;

use App\Core\Validation\ViolationCode;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What the browser's PushManager gives for this device, and its name in the
 * user's list. The endpoint is a push service's: always https, and never
 * reached on a private address (PushSender).
 */
final readonly class PushSubscriptionRequest
{
    /**
     * @param array{p256dh?: string, auth?: string} $keys
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['https'])]
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

    /**
     * The keys are what the browser made: a P-256 public key (65 bytes,
     * uncompressed) and a 16-byte secret, base64url. Anything else could never
     * be encrypted to, and would only fail when a notification is sent.
     */
    #[Assert\Callback]
    public function validateKeys(ExecutionContextInterface $context): void
    {
        $publicKey = self::decode($this->keys['p256dh'] ?? '');
        if ($publicKey !== null && (\strlen($publicKey) !== 65 || $publicKey[0] !== "\x04")) {
            $context->buildViolation('Not a push public key.')->atPath('keys[p256dh]')->setCode(ViolationCode::INVALID)->addViolation();
        }
        $secret = self::decode($this->keys['auth'] ?? '');
        if ($secret !== null && \strlen($secret) !== 16) {
            $context->buildViolation('Not a push secret.')->atPath('keys[auth]')->setCode(ViolationCode::INVALID)->addViolation();
        }
    }

    /** base64url without padding, as PushSubscription.toJSON() gives it; null when blank (NotBlank says so). */
    private static function decode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? '' : $bytes;
    }
}
