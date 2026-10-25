<?php

namespace App\Entity;

use App\Repository\CurrencyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currency')]
#[ORM\UniqueConstraint(name: 'uniq_currency_code', columns: ['code'])]
/**
 * A currency of the fixed ISO 4217 list (a migration fills it): read-only, no
 * one adds, disables or deletes one. Which currencies matter is decided by
 * what uses them (accounts, instruments, the user's preferred one).
 */
class Currency
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 3)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 3)]
    private string $code;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    private string $symbol;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $decimalPlaces;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $code,
        string $name,
        string $symbol,
        int $decimalPlaces = 2,
    ) {
        $this->id = Uuid::v7();
        $this->code = strtoupper($code);
        $this->name = $name;
        $this->symbol = $symbol;
        $this->decimalPlaces = $decimalPlaces;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }


    public function getName(): string
    {
        return $this->name;
    }


    public function getSymbol(): string
    {
        return $this->symbol;
    }


    public function getDecimalPlaces(): int
    {
        return $this->decimalPlaces;
    }
}
