<?php

namespace App\Core\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

final class Paginator
{
    /**
     * Runs a QueryBuilder with LIMIT/OFFSET applied and returns both the page
     * of results and the total row count (ignoring the limit), via Doctrine's
     * ORM paginator so joins/fetches that multiply rows are still counted correctly.
     *
     * @return array{0: mixed[], 1: int}
     */
    public static function paginate(QueryBuilder $qb, PaginationParams $params): array
    {
        $qb->setFirstResult($params->offset())
            ->setMaxResults($params->pageSize);

        $paginator = new DoctrinePaginator($qb, fetchJoinCollection: false);

        return [iterator_to_array($paginator), $paginator->count()];
    }
}
