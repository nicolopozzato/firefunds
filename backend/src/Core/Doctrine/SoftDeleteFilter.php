<?php

namespace App\Core\Doctrine;

use App\Entity\SoftDeletable;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Excludes soft-deleted rows from every ORM query (DQL, QueryBuilder, find*,
 * lazy-loaded associations) on entities implementing SoftDeletable. Enabled
 * globally in config/packages/doctrine.yaml. Native SQL is not affected.
 */
class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->reflClass->implementsInterface(SoftDeletable::class)) {
            return '';
        }

        return \sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }
}
