<?php

namespace App\Repository;

use App\Core\Pagination\PaginationParams;
use App\Core\Search\SearchQuery;
use App\Core\Pagination\Paginator;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * @return Tag[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['name' => 'ASC']);
    }

    /**
     * @return array{0: Tag[], 1: int}
     */
    public function findByUserPaginated(User $user, PaginationParams $params): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC');

        return Paginator::paginate($qb, $params);
    }

    public function findOneByUserAndId(User $user, string $id): ?Tag
    {
        // Not a UUID is not anything of theirs (and would fail the conversion).
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['user' => $user, 'id' => $id]);
    }

    /** The user's tag with that name, ignoring case (the oldest, if several). */
    public function findOneByUserAndName(User $user, string $name): ?Tag
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('LOWER(c.name) = LOWER(:name)')
            ->setParameter('user', $user)
            ->setParameter('name', $name)
            ->orderBy('c.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The search box's suggestions: names matching what is typed.
     *
     * @return array{id: string, name: string}[]
     */
    public function searchByName(User $user, SearchQuery $search, int $limit): array
    {
        [$condition, $params] = $search->toSql('unaccent(name)', 'tag');

        return $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT CAST(id AS text) AS id, name FROM tag
                WHERE user_id = :userId AND deleted_at IS NULL AND {$condition}
                ORDER BY name
                LIMIT :limit
                SQL,
            $params + ['userId' => $user->getId()->toRfc4122(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }
}
