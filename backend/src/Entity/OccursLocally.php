<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * When a transaction happened, as the person wrote it: the date and time on
 * the clock where it happened (`occurred_at`, no conversion) and that place's
 * timezone (`timezone`). Its day is the written one wherever it is looked at
 * from, the way a bank statement books a payment, so every calculation reads
 * the day straight off `occurred_at`.
 *
 * Only system events (created_at, audit logs, tokens) are UTC instants.
 */
trait OccursLocally
{
    /** Wall-clock time where it happened; the PHP object's own zone means nothing. */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** IANA name (or offset) of the zone `occurredAt` was written in. */
    #[ORM\Column(length: 64)]
    private string $timezone;

    /**
     * The moment, in the zone it happened in: format('Y-m-d') is its day,
     * format(ATOM) the exact instant.
     */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->occurredAt->format('Y-m-d H:i:s.u'), new \DateTimeZone($this->timezone));
    }

    /**
     * Stores the clock time the moment shows in its own zone, and that zone:
     * pass it in the zone it happened in (TransactionMoment::parse()).
     */
    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = new \DateTimeImmutable($occurredAt->format('Y-m-d H:i:s.u'));
        $this->timezone = $occurredAt->getTimezone()->getName();

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
