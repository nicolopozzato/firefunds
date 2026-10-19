<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Pagination\Paginator;
use App\Core\Search\SearchQuery;
use App\Entity\InvestmentInstrument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InvestmentInstrument>
 */
class InvestmentInstrumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentInstrument::class);
    }

    /**
     * @return array{0: InvestmentInstrument[], 1: int}
     */
    public function findByUserPaginated(User $user, PaginationParams $params): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.name', 'ASC');

        return Paginator::paginate($qb, $params);
    }

    /**
     * The user's instruments whose name, ticker or ISIN matches the search,
     * by name: the shortcuts under the search box.
     *
     * @return array<array{id: string, name: string, ticker: ?string, type: string}>
     */
    public function search(User $user, SearchQuery $search, int $limit): array
    {
        [$condition, $params] = $search->toSql("unaccent(concat_ws(chr(31), name, ticker, isin))", 'instrument');

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT CAST(id AS text) AS id, name, ticker, type FROM investment_instrument
                WHERE user_id = :userId AND deleted_at IS NULL AND {$condition}
                ORDER BY name
                LIMIT :limit
                SQL,
            $params + ['userId' => $user->getId()->toRfc4122(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    public function findOneByUserAndId(User $user, string $id): ?InvestmentInstrument
    {
        // Not a UUID is not anything of theirs (and would fail the conversion).
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }
}
