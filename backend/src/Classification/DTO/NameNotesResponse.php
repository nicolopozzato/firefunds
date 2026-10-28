<?php

namespace App\Classification\DTO;

use App\Entity\Category;
use App\Entity\Tag;

final readonly class NameNotesResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
        /** The day of the latest (non-deleted) transaction it is set on, in lists; null when none or not asked. */
        public ?string $lastTransactionAt = null,
    ) {
    }

    public static function fromCategory(Category $category, ?string $lastTransactionAt = null): self
    {
        return new self(
            id: (string) $category->getId(),
            name: $category->getName(),
            notes: $category->getNotes(),
            createdAt: $category->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $category->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            lastTransactionAt: $lastTransactionAt,
        );
    }

    public static function fromTag(Tag $tag, ?string $lastTransactionAt = null): self
    {
        return new self(
            id: (string) $tag->getId(),
            name: $tag->getName(),
            notes: $tag->getNotes(),
            createdAt: $tag->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $tag->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            lastTransactionAt: $lastTransactionAt,
        );
    }
}
