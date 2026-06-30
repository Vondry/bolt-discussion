<?php

declare(strict_types=1);

namespace Bolt\Discussion\Repository;

use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Enum\CommentStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscussionComment>
 */
class DiscussionCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscussionComment::class);
    }

    /**
     * All visible comments for a discussion, ordered oldest-first so the JS can
     * assemble the (one-level) tree. When $sinceId is given, only newer rows are
     * returned (used by polling). The result is capped so a polling request
     * cannot bypass normal pagination and return an unbounded thread.
     *
     * @return DiscussionComment[]
     */
    public function findThread(string $reference, ?int $sinceId = null, bool $includePending = false, int $limit = 100): array
    {
        $statuses = [CommentStatus::Published];
        if ($includePending) {
            $statuses[] = CommentStatus::Pending;
        }

        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.reference = :reference')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('reference', $reference)
            ->setParameter('statuses', $statuses)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)));

        if ($sinceId !== null) {
            $qb->andWhere('c.id > :sinceId')->setParameter('sinceId', $sinceId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A cursor-paginated page of root comments, newest first. When $beforeId is
     * given, only roots older than it are returned ("load more"). At most $limit
     * rows are returned; callers may request limit+1 to detect further pages.
     *
     * @return DiscussionComment[]
     */
    public function findRootsPage(string $reference, ?int $beforeId, int $limit, bool $includePending = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.reference = :reference')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('reference', $reference)
            ->setParameter('statuses', $this->visibleStatuses($includePending))
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($limit);

        if ($beforeId !== null) {
            $qb->andWhere('c.id < :beforeId')->setParameter('beforeId', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * All visible replies belonging to the given root comments, oldest first.
     *
     * @param int[] $rootIds
     * @return DiscussionComment[]
     */
    public function findRepliesForRoots(array $rootIds, bool $includePending = false): array
    {
        if ($rootIds === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.parent IN (:rootIds)')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('rootIds', $rootIds)
            ->setParameter('statuses', $this->visibleStatuses($includePending))
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Visible comments in the requested discussion, restricted to a bounded
     * set supplied by the polling client.
     *
     * @param int[] $ids
     * @return DiscussionComment[]
     */
    public function findVisibleByIds(string $reference, array $ids, bool $includePending = false): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.reference = :reference')
            ->andWhere('c.id IN (:ids)')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('reference', $reference)
            ->setParameter('ids', $ids)
            ->setParameter('statuses', $this->visibleStatuses($includePending))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CommentStatus[]
     */
    private function visibleStatuses(bool $includePending): array
    {
        $statuses = [CommentStatus::Published];
        if ($includePending) {
            $statuses[] = CommentStatus::Pending;
        }

        return $statuses;
    }

    /**
     * Whether a comment was posted from this hashed IP since the given time.
     * Used for basic rate-limiting of anonymous posters.
     */
    public function hasRecentFromIp(string $ipHash, DateTimeImmutable $since): bool
    {
        $count = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.ipHash = :ipHash')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('ipHash', $ipHash)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Direct replies of the given comment, regardless of status. Used when a
     * root comment is deleted so its whole thread can be removed with it.
     *
     * @return DiscussionComment[]
     */
    public function findReplies(DiscussionComment $parent): array
    {
        return $this->findBy(['parent' => $parent]);
    }

    public function countPublished(string $reference): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.reference = :reference')
            ->andWhere('c.status = :status')
            ->setParameter('reference', $reference)
            ->setParameter('status', CommentStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Distinct discussion references with comment counts, for the admin overview.
     *
     * @return array<int, array{reference: string, total: int, pending: int, last: DateTimeImmutable}>
     */
    public function findReferencesOverview(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.reference AS reference')
            ->addSelect('COUNT(c.id) AS total')
            ->addSelect('SUM(CASE WHEN c.status = :pending THEN 1 ELSE 0 END) AS pending')
            ->addSelect('MAX(c.createdAt) AS last')
            ->andWhere('c.status != :deleted')
            ->setParameter('pending', CommentStatus::Pending->value)
            ->setParameter('deleted', CommentStatus::Deleted->value)
            ->groupBy('c.reference')
            ->orderBy('last', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): array => [
            'reference' => (string) $r['reference'],
            'total' => (int) $r['total'],
            'pending' => (int) $r['pending'],
            'last' => $r['last'] instanceof DateTimeImmutable
                ? $r['last']
                : new DateTimeImmutable((string) $r['last']),
        ], $rows);
    }

    /**
     * All comments for a reference (any non-deleted status), newest first, for
     * the admin thread view.
     *
     * @return DiscussionComment[]
     */
    public function findForAdmin(string $reference): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.reference = :reference')
            ->andWhere('c.status != :deleted')
            ->setParameter('reference', $reference)
            ->setParameter('deleted', CommentStatus::Deleted)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
