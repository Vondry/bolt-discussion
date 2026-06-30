<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Repository;

use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Entity\DiscussionReaction;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Repository\DiscussionReactionRepository;
use Bolt\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;

class DiscussionRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;
    private DiscussionCommentRepository $comments;
    private DiscussionReactionRepository $reactions;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([
            \dirname(__DIR__, 2) . '/src/Entity',
            \dirname(__DIR__, 2) . '/vendor/bolt/core/src/Entity',
        ], true);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('BoltDiscussionTestProxies');

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(DiscussionComment::class),
            $this->em->getClassMetadata(DiscussionReaction::class),
        ]);

        $registry = new class($this->em) implements ManagerRegistry {
            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function getConnection(string|null $name = null): object
            {
                return $this->em->getConnection();
            }

            public function getConnections(): array
            {
                return ['default' => $this->em->getConnection()];
            }

            public function getConnectionNames(): array
            {
                return ['default' => 'default'];
            }

            public function getDefaultManagerName(): string
            {
                return 'default';
            }

            public function getManager(string|null $name = null): ObjectManager
            {
                return $this->em;
            }

            public function getManagers(): array
            {
                return ['default' => $this->em];
            }

            public function resetManager(string|null $name = null): ObjectManager
            {
                return $this->em;
            }

            public function getManagerNames(): array
            {
                return ['default' => 'default'];
            }

            public function getRepository(string $persistentObject, string|null $persistentManagerName = null): ObjectRepository
            {
                return $this->em->getRepository($persistentObject);
            }

            public function getManagerForClass(string $class): ?ObjectManager
            {
                return $this->em->getMetadataFactory()->isTransient($class) ? null : $this->em;
            }
        };

        $this->comments = new DiscussionCommentRepository($registry);
        $this->reactions = new DiscussionReactionRepository($registry);
    }

    public function testCommentQueriesApplyVisibilityReferencePaginationAndAdminRules(): void
    {
        $rootOld = $this->comment('demo', 'Old root', '2026-06-23 10:00:00');
        $rootNew = $this->comment('demo', 'New root', '2026-06-23 11:00:00');
        $pending = $this->comment('demo', 'Pending root', '2026-06-23 12:00:00', CommentStatus::Pending);
        $spam = $this->comment('demo', 'Spam root', '2026-06-23 13:00:00', CommentStatus::Spam);
        $deleted = $this->comment('demo', 'Deleted root', '2026-06-23 14:00:00', CommentStatus::Deleted);
        $other = $this->comment('other', 'Other root', '2026-06-23 15:00:00');
        $reply = $this->comment('demo', 'Reply', '2026-06-23 11:30:00', CommentStatus::Published, $rootNew);

        $this->flush($rootOld, $rootNew, $pending, $spam, $deleted, $other, $reply);

        self::assertSame(
            [$rootOld->getId(), $rootNew->getId(), $reply->getId()],
            $this->ids($this->comments->findThread('demo'))
        );
        self::assertSame(
            [$reply->getId(), $pending->getId()],
            $this->ids($this->comments->findThread('demo', $rootNew->getId(), true))
        );

        self::assertSame(
            [$rootNew->getId(), $rootOld->getId()],
            $this->ids($this->comments->findRootsPage('demo', null, 2))
        );
        self::assertSame(
            [$rootOld->getId()],
            $this->ids($this->comments->findRootsPage('demo', $rootNew->getId(), 10))
        );
        self::assertSame([$reply->getId()], $this->ids($this->comments->findRepliesForRoots([(int) $rootNew->getId()])));
        self::assertSame([], $this->comments->findRepliesForRoots([]));

        self::assertSame(
            [$rootNew->getId()],
            $this->ids($this->comments->findVisibleByIds('demo', [(int) $rootNew->getId(), (int) $pending->getId()]))
        );
        self::assertSame(
            $this->sortedIds($rootNew, $pending),
            $this->sortedIds(...$this->comments->findVisibleByIds('demo', [(int) $rootNew->getId(), (int) $pending->getId()], true))
        );
        self::assertSame([], $this->comments->findVisibleByIds('demo', []));

        self::assertTrue($this->comments->hasRecentFromIp('hash-demo', new DateTimeImmutable('2026-06-23 09:00:00')));
        self::assertFalse($this->comments->hasRecentFromIp('missing', new DateTimeImmutable('2026-06-23 09:00:00')));
        self::assertSame([$reply->getId()], $this->ids($this->comments->findReplies($rootNew)));
        self::assertSame(3, $this->comments->countPublished('demo'));

        $overview = $this->comments->findReferencesOverview();
        self::assertSame('other', $overview[0]['reference']);
        self::assertSame(1, $overview[0]['total']);
        self::assertSame('demo', $overview[1]['reference']);
        self::assertSame(5, $overview[1]['total']);
        self::assertSame(1, $overview[1]['pending']);

        $admin = $this->comments->findForAdmin('demo');
        self::assertSame(
            [$spam->getId(), $pending->getId(), $reply->getId(), $rootNew->getId(), $rootOld->getId()],
            $this->ids($admin)
        );
    }

    public function testReactionQueriesSummarizeDeduplicateAndDeleteRows(): void
    {
        $comment = $this->comment('demo', 'Root', '2026-06-23 10:00:00');
        $otherComment = $this->comment('demo', 'Other', '2026-06-23 11:00:00');
        $this->flush($comment, $otherComment);

        $mine = $this->reaction($comment, '👍', 'anon:mine', 'ip-a');
        $other = $this->reaction($comment, '👍', 'anon:other', 'ip-a');
        $heart = $this->reaction($comment, '❤️', 'anon:other', 'ip-b');
        $ignored = $this->reaction($otherComment, '👍', 'anon:other', 'ip-c');
        $this->flush($mine, $other, $heart, $ignored);

        self::assertNull($this->reactions->findOneFor((int) $comment->getId(), '😂', 'anon:mine'));
        self::assertSame($mine->getId(), $this->reactions->findOneFor((int) $comment->getId(), '👍', 'anon:mine')?->getId());
        self::assertSame(2, $this->reactions->countRecentFromIp('ip-a', new DateTimeImmutable('-1 hour')));
        self::assertSame(0, $this->reactions->countRecentFromIp('missing', new DateTimeImmutable('-1 hour')));
        self::assertSame([], $this->reactions->summaryFor([], 'anon:mine'));

        $summary = $this->reactions->summaryFor([(int) $comment->getId()], 'anon:mine');
        ksort($summary[(int) $comment->getId()]);
        self::assertSame([
            (int) $comment->getId() => [
                '❤️' => ['count' => 1, 'mine' => false],
                '👍' => ['count' => 2, 'mine' => true],
            ],
        ], $summary);

        $this->reactions->deleteForComments([]);
        self::assertSame(2, $this->reactions->countRecentFromIp('ip-a', new DateTimeImmutable('-1 hour')));

        $this->reactions->deleteForComments([(int) $comment->getId()]);
        self::assertSame([], $this->reactions->summaryFor([(int) $comment->getId()], 'anon:mine'));
        self::assertSame(1, $this->reactions->countRecentFromIp('ip-c', new DateTimeImmutable('-1 hour')));
    }

    public function testReferencesOverviewKeepsAlreadyConvertedDateInstances(): void
    {
        $registry = $this->registry();
        $em = $this->em;
        $last = new DateTimeImmutable('2026-06-23 10:00:00');
        $repository = new class($registry, $em, $last) extends DiscussionCommentRepository {
            public function __construct(
                ManagerRegistry $registry,
                private readonly EntityManagerInterface $em,
                private readonly DateTimeImmutable $last,
            ) {
                parent::__construct($registry);
            }

            public function createQueryBuilder(string $alias, string|null $indexBy = null): QueryBuilder
            {
                $rows = [[
                    'reference' => 'demo',
                    'total' => 1,
                    'pending' => 0,
                    'last' => $this->last,
                ]];

                return new class($this->em, $rows) extends QueryBuilder {
                    /**
                     * @param array<int, array<string, mixed>> $rows
                     */
                    public function __construct(
                        private readonly EntityManagerInterface $entityManager,
                        private readonly array $rows,
                    ) {
                        parent::__construct($entityManager);
                    }

                    public function getQuery(): Query
                    {
                        return new class($this->entityManager, $this->rows) extends Query {
                            /**
                             * @param array<int, array<string, mixed>> $rows
                             */
                            public function __construct(EntityManagerInterface $em, private readonly array $rows)
                            {
                                parent::__construct($em);
                            }

                            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
                            {
                                return $this->rows;
                            }
                        };
                    }
                };
            }
        };

        $overview = $repository->findReferencesOverview();

        self::assertSame($last, $overview[0]['last']);
    }

    private function comment(
        string $reference,
        string $body,
        string $createdAt,
        CommentStatus $status = CommentStatus::Published,
        ?DiscussionComment $parent = null,
    ): DiscussionComment {
        $comment = (new DiscussionComment())
            ->setReference($reference)
            ->setAuthorName('Tester')
            ->setBody($body)
            ->setStatus($status)
            ->setParent($parent)
            ->setIpHash($reference === 'demo' ? 'hash-demo' : 'hash-other');

        $this->setDate($comment, 'createdAt', $createdAt);
        $this->setDate($comment, 'updatedAt', $createdAt);

        return $comment;
    }

    private function reaction(DiscussionComment $comment, string $emoji, string $token, string $ipHash): DiscussionReaction
    {
        return (new DiscussionReaction())
            ->setComment($comment)
            ->setEmoji($emoji)
            ->setVisitorToken($token)
            ->setIpHash($ipHash);
    }

    private function flush(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    private function registry(): ManagerRegistry
    {
        return new class($this->em) implements ManagerRegistry {
            public function __construct(private readonly EntityManagerInterface $em)
            {
            }

            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            public function getConnection(string|null $name = null): object
            {
                return $this->em->getConnection();
            }

            public function getConnections(): array
            {
                return ['default' => $this->em->getConnection()];
            }

            public function getConnectionNames(): array
            {
                return ['default' => 'default'];
            }

            public function getDefaultManagerName(): string
            {
                return 'default';
            }

            public function getManager(string|null $name = null): ObjectManager
            {
                return $this->em;
            }

            public function getManagers(): array
            {
                return ['default' => $this->em];
            }

            public function resetManager(string|null $name = null): ObjectManager
            {
                return $this->em;
            }

            public function getManagerNames(): array
            {
                return ['default' => 'default'];
            }

            public function getRepository(string $persistentObject, string|null $persistentManagerName = null): ObjectRepository
            {
                return $this->em->getRepository($persistentObject);
            }

            public function getManagerForClass(string $class): ?ObjectManager
            {
                return $this->em->getMetadataFactory()->isTransient($class) ? null : $this->em;
            }
        };
    }

    /**
     * @param object[] $entities
     * @return int[]
     */
    private function ids(array $entities): array
    {
        return array_map(static fn (object $entity): int => (int) $entity->getId(), $entities);
    }

    private function sortedIds(object ...$entities): array
    {
        $ids = $this->ids($entities);
        sort($ids);

        return $ids;
    }

    private function setDate(object $entity, string $property, string $value): void
    {
        $reflection = new \ReflectionProperty($entity, $property);
        $reflection->setValue($entity, new DateTimeImmutable($value));
    }
}
