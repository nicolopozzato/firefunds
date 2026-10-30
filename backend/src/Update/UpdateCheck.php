<?php

namespace App\Update;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether a newer FireFunds is out: the `updates` task asks GitHub twice a
 * day and keeps the answer in `setting`, so the app only reads it. Off in
 * Administration → Server settings: GitHub is never asked and nothing shows.
 * The version running is the image's (APP_VERSION); a build that isn't a
 * release ("dev") never has an update.
 */
class UpdateCheck
{
    public function __construct(
        private readonly GitHubReleases $releases,
        private readonly SettingRepository $settings,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'APP_VERSION')]
        private readonly string $currentVersion,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getBoolValue(Setting::KEY_UPDATE_CHECK_ENABLED, true);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set(Setting::KEY_UPDATE_CHECK_ENABLED, $enabled ? 'true' : 'false');
    }

    /** Asks GitHub and keeps what it says; null while there is no release at all. */
    public function check(): ?Release
    {
        $latest = $this->releases->latest();
        $this->settings->set(Setting::KEY_LATEST_RELEASE, json_encode([
            'release' => $latest?->toArray(),
            'checkedAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR));

        return $latest;
    }

    public function status(): UpdateStatus
    {
        $enabled = $this->isEnabled();
        $stored = $enabled ? json_decode($this->settings->getValue(Setting::KEY_LATEST_RELEASE, 'null'), true) : null;
        $latest = \is_array($stored['release'] ?? null) ? Release::fromArray($stored['release']) : null;

        return new UpdateStatus(
            $enabled,
            $this->currentVersion,
            $latest,
            $latest !== null && self::isNewer($latest->version, $this->currentVersion),
            \is_array($stored) ? ($stored['checkedAt'] ?? null) : null,
        );
    }

    public static function isNewer(string $latest, string $current): bool
    {
        $release = '/^\d+\.\d+\.\d+$/';

        return preg_match($release, $latest) === 1 && preg_match($release, $current) === 1
            && version_compare($latest, $current, '>');
    }
}
