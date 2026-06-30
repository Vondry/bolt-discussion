<?php

declare(strict_types=1);

namespace Bolt\Discussion\Repository;

use Bolt\Discussion\Entity\DiscussionReaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscussionReaction>
 */
class DiscussionReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscussionReaction::class);
    }

    public function findOneFor(int $commentId, string $emoji, string $visitorToken): ?DiscussionReaction
    {
        return $this->findOneBy([
            'comment' => $commentId,
            'emoji' => $emoji,
            'visitorToken' => $visitorToken,
        ]);
    }

    /**
     * Per-comment reaction summary for the given comments. Returns, keyed by
     * comment id, a map of emoji => ['count' => int, 'mine' => bool].
     *
     * @param int[] $commentIds
     * @return array<int, array<string, array{count: int, mine: bool}>>
     */
    public function summaryFor(array $commentIds, string $visitorToken): array
    {
        if ($commentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.comment) AS comment_id')
            ->addSelect('r.emoji AS emoji')
            ->addSelect('COUNT(r.id) AS total')
            ->addSelect('SUM(CASE WHEN r.visitorToken = :token THEN 1 ELSE 0 END) AS mine')
            ->andWhere('r.comment IN (:ids)')
            ->setParameter('ids', $commentIds)
            ->setParameter('token', $visitorToken)
            ->groupBy('comment_id')
            ->addGroupBy('r.emoji')
            ->getQuery()
            ->getResult();

        $summary = [];
        foreach ($rows as $row) {
            $commentId = (int) $row['comment_id'];
            $summary[$commentId][(string) $row['emoji']] = [
                'count' => (int) $row['total'],
                'mine' => ((int) $row['mine']) > 0,
            ];
        }

        return $summary;
    }
}
