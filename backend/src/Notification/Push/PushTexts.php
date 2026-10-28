<?php

namespace App\Notification\Push;

use App\Entity\Notification;

/**
 * What a push notification says, in the user's language: the device shows
 * it with the app closed, so the server words it (the bell's texts are the
 * app's own, in its translations; keep the two saying the same).
 */
final class PushTexts
{
    private const TEXTS = [
        'it' => [
            'recurring_missing' => ['Dovevi registrare «%description%»?', 'Era previsto per il %day% e non l\'ho trovato.'],
            'recurring_summary' => ['%count% transazioni da registrare', '%list%'],
            'task_failing' => ['Un\'attività pianificata non riesce', 'Le ultime esecuzioni di «%task%» sono fallite.'],
            'backup_overdue' => ['Backup in ritardo', 'L\'ultimo backup è del %since%.'],
            'prices_stale' => ['Prezzi fermi', '%symbols%'],
            'rates_stale' => ['Cambi fermi', '%currencies%'],
            'test' => ['FireFunds', 'Le notifiche arrivano su questo dispositivo.'],
        ],
        'en' => [
            'recurring_missing' => ['Did you pay «%description%»?', 'It was due on %day% and I couldn\'t find it.'],
            'recurring_summary' => ['%count% transactions to enter', '%list%'],
            'task_failing' => ['A scheduled task keeps failing', 'The last runs of «%task%» failed.'],
            'backup_overdue' => ['Backup overdue', 'The last backup is from %since%.'],
            'prices_stale' => ['Prices stuck', '%symbols%'],
            'rates_stale' => ['Rates stuck', '%currencies%'],
            'test' => ['FireFunds', 'Notifications reach this device.'],
        ],
    ];

    /**
     * @param array<string, string|int|float|null> $params
     * @return array{title: string, body: string}
     */
    public static function of(string $type, array $params, string $locale): array
    {
        $texts = self::TEXTS[str_starts_with($locale, 'it') ? 'it' : 'en'];
        [$title, $body] = $texts[$type] ?? ['FireFunds', ''];
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['%'.$name.'%'] = self::value($name, $value, $locale);
        }

        return ['title' => strtr($title, $replace), 'body' => strtr($body, $replace)];
    }

    /** Where the notification leads in the app. */
    public static function url(Notification $notification): string
    {
        $params = $notification->getParams();

        return match ($notification->getType()) {
            'recurring_missing' => '/transactions/recurring/'.$params['recurringId'],
            'backup_overdue' => '/admin/backups',
            'task_failing', 'prices_stale', 'rates_stale' => '/admin/tasks',
            default => '/',
        };
    }

    private static function value(string $name, string|int|float|null $value, string $locale): string
    {
        if (($name === 'day' || $name === 'since') && \is_string($value) && $value !== '') {
            $date = new \DateTimeImmutable($value);

            return str_starts_with($locale, 'it') ? $date->format('d/m/Y') : $date->format('M j, Y');
        }

        return (string) $value;
    }
}
