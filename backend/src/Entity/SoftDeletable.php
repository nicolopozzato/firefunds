<?php

namespace App\Entity;

/**
 * An entity that is marked as deleted (`deleted_at` set) instead of being
 * removed, so it keeps its audit history. SoftDeleteFilter hides such rows
 * from every ORM query; native SQL must filter `deleted_at IS NULL` itself.
 */
interface SoftDeletable
{
    public function getDeletedAt(): ?\DateTimeImmutable;

    public function markDeleted(): void;
}
