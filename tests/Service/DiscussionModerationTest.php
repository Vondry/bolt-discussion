<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Service;

use BoltDiscussion\Entity\DiscussionComment;
use BoltDiscussion\Enum\CommentStatus;
use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Repository\DiscussionReactionRepository;
use BoltDiscussion\Service\DiscussionConfig;
use BoltDiscussion\Service\DiscussionManager;
use BoltDiscussion\Service\SpamChecker;
use BoltDiscussion\Service\VisitorTokenProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;

/**
 * Admin-facing moderation actions used by the backend controller and the
 * frontend delete button.
 */
class DiscussionModerationTest extends TestCase
{
    private function manager(EntityManagerInterface $em): DiscussionManager
    {
        return new DiscussionManager(
            $em,
            $this->createMock(DiscussionCommentRepository::class),
            $this->createMock(DiscussionReactionRepository::class),
            $this->createMock(DiscussionConfig::class),
            $this->createMock(SpamChecker::class),
            $this->createMock(VisitorTokenProvider::class),
            $this->createMock(Packages::class),
            'test-ip-hash-key',
        );
    }

    public function testDeleteSoftDeletesAndFlushes(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em)->deleteComment($comment);

        self::assertSame(CommentStatus::Deleted, $comment->getStatus());
    }

    public function testApprovePendingComment(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Pending);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em)->setStatus($comment, CommentStatus::Published);

        self::assertSame(CommentStatus::Published, $comment->getStatus());
    }

    public function testMarkAsSpam(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em)->setStatus($comment, CommentStatus::Spam);

        self::assertSame(CommentStatus::Spam, $comment->getStatus());
    }
}
