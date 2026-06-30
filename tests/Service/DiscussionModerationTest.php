<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Service;

use Bolt\Utils\ThumbnailHelper;
use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Repository\DiscussionReactionRepository;
use Bolt\Discussion\Service\DiscussionConfig;
use Bolt\Discussion\Service\DiscussionManager;
use Bolt\Discussion\Service\SpamChecker;
use Bolt\Discussion\Service\VisitorTokenProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Admin-facing moderation actions used by the backend controller and the
 * frontend delete button.
 */
class DiscussionModerationTest extends TestCase
{
    private function manager(
        EntityManagerInterface $em,
        ?DiscussionCommentRepository $comments = null,
        ?DiscussionReactionRepository $reactions = null,
    ): DiscussionManager {
        return new DiscussionManager(
            $em,
            $comments ?? $this->createMock(DiscussionCommentRepository::class),
            $reactions ?? $this->createMock(DiscussionReactionRepository::class),
            $this->createMock(DiscussionConfig::class),
            $this->createMock(SpamChecker::class),
            $this->createMock(VisitorTokenProvider::class),
            $this->createMock(ThumbnailHelper::class),
            'test-ip-hash-key',
        );
    }

    private function comment(int $id, ?DiscussionComment $parent = null): DiscussionComment
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        if ($parent !== null) {
            $comment->setParent($parent);
        }
        (new ReflectionProperty(DiscussionComment::class, 'id'))->setValue($comment, $id);

        return $comment;
    }

    public function testDeleteSoftDeletesAndFlushes(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em)->deleteComment($comment);

        self::assertSame(CommentStatus::Deleted, $comment->getStatus());
    }

    public function testDeletingRootCascadesToRepliesSoNoneIsOrphaned(): void
    {
        $root = $this->comment(1);
        $replyA = $this->comment(2, $root);
        $replyB = $this->comment(3, $root);

        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->expects(self::once())->method('findReplies')->with($root)->willReturn([$replyA, $replyB]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em, $comments)->deleteComment($root);

        self::assertSame(CommentStatus::Deleted, $root->getStatus());
        self::assertSame(CommentStatus::Deleted, $replyA->getStatus(), 'Replies are deleted with their parent.');
        self::assertSame(CommentStatus::Deleted, $replyB->getStatus());
    }

    public function testDeletingRootPurgesReactionsForTheWholeThread(): void
    {
        $root = $this->comment(1);
        $reply = $this->comment(2, $root);

        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->method('findReplies')->with($root)->willReturn([$reply]);

        $reactions = $this->createMock(DiscussionReactionRepository::class);
        $reactions->expects(self::once())->method('deleteForComments')->with([1, 2]);

        $this->manager($this->createMock(EntityManagerInterface::class), $comments, $reactions)->deleteComment($root);
    }

    public function testDeletingReplyDoesNotLookUpFurtherReplies(): void
    {
        $root = $this->comment(1);
        $reply = $this->comment(2, $root);

        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->expects(self::never())->method('findReplies');

        $reactions = $this->createMock(DiscussionReactionRepository::class);
        $reactions->expects(self::once())->method('deleteForComments')->with([2]);

        $this->manager($this->createMock(EntityManagerInterface::class), $comments, $reactions)->deleteComment($reply);

        self::assertSame(CommentStatus::Deleted, $reply->getStatus());
        self::assertSame(CommentStatus::Published, $root->getStatus(), 'Deleting a reply leaves its parent untouched.');
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
