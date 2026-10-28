<?php

namespace App\Notification\Push;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Minishlink\WebPush\VAPID;

/**
 * The server's own Web Push identity (VAPID): a key pair made the first time
 * it is needed and kept in the settings (so a backup keeps the devices
 * working), and the address it gives the push services, the origin the
 * first device subscribed from. Nothing for whoever installs it to set up.
 */
class VapidKeys
{
    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function publicKey(): string
    {
        $public = $this->settings->getValue(Setting::KEY_PUSH_PUBLIC_KEY);
        if ($public !== '') {
            return $public;
        }
        $keys = VAPID::createVapidKeys();
        $this->settings->set(Setting::KEY_PUSH_PRIVATE_KEY, $keys['privateKey']);
        $this->settings->set(Setting::KEY_PUSH_PUBLIC_KEY, $keys['publicKey']);

        return $keys['publicKey'];
    }

    /** @return array{subject: string, publicKey: string, privateKey: string} */
    public function auth(): array
    {
        return [
            'subject' => $this->settings->getValue(Setting::KEY_PUSH_SUBJECT, 'mailto:firefunds@localhost'),
            'publicKey' => $this->publicKey(),
            'privateKey' => $this->settings->getValue(Setting::KEY_PUSH_PRIVATE_KEY),
        ];
    }

    /** Remembered once: the push services want an https address or a mailto. */
    public function rememberOrigin(string $origin): void
    {
        if (str_starts_with($origin, 'https://') && $this->settings->getValue(Setting::KEY_PUSH_SUBJECT) === '') {
            $this->settings->set(Setting::KEY_PUSH_SUBJECT, $origin);
        }
    }
}
