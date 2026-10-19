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
    ) {
    }

    public static function fromCategory(Category $category): self
    {
        return new self(
            id: (string) $category->getId(),
            name: $category->getName(),
            notes: $category->getNotes(),
            createdAt: $category->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $category->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public static function fromTag(Tag $tag): self
    {
        return new self(
            id: (string) $tag->getId(),
            name: $tag->getName(),
            notes: $tag->getNotes(),
            createdAt: $tag->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $tag->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
