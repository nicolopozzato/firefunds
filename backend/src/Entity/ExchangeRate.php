<?php

namespace App\Entity;

use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a currency was worth on one day, against the euro: `rate` units of the
 * currency for 1 EUR (1 EUR = 1.0850 USD). The euro is only the internal
 * pivot: any other pair is a division (CurrencyConverter). Facts, not
 * anybody's data, so one row per currency and day for all users, filled in
 * for every calendar day (carried over weekends and holidays, like prices);
 * what a user changes is kept apart, in ExchangeRateOverride.
 */
#[ORM\Entity(repositoryClass: ExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rate')]
#[ORM\UniqueConstraint(name: 'uniq_exchange_rate_day', columns: ['currency', 'date'])]
class ExchangeRate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $rate;

    #[ORM\Column(length: 10, enumType: ExchangeRateSource::class)]
    private ExchangeRateSource $source;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $currency, \DateTimeImmutable $date, string $rate, ExchangeRateSource $source)
    {
        $this->id = Uuid::v7();
        $this->currency = strtoupper($currency);
        $this->date = $date;
        $this->rate = $rate;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function getSource(): ExchangeRateSource
    {
        return $this->source;
    }
}
