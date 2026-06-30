<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Entity;

use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Entity\DiscussionReaction;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Entity\User;
use PHPUnit\Framework\TestCase;

class DiscussionEntityTest extends TestCase
{
    public function testCommentAccessorsAndLifecycleTouch(): void
    {
        $user = $this->createMock(User::class);
        $parent = (new DiscussionComment())
            ->setReference('demo')
            ->setAuthorName('Parent')
            ->setBody('Root')
            ->setStatus(CommentStatus::Published);

        $comment = (new DiscussionComment())
            ->setReference('demo')
            ->setParent($parent)
            ->setAuthor($user)
            ->setAuthorName('Tester')
            ->setAuthorEmail('tester@example.test')
            ->setBody('Hello')
            ->setStatus(CommentStatus::Pending)
            ->setIpHash('hash');

        $createdAt = $comment->getCreatedAt();
        $oldUpdatedAt = $comment->getUpdatedAt();
        $this->setDate($comment, 'updatedAt', '2026-06-23 10:00:00');
        $comment->touch();

        self::assertNull($comment->getId());
        self::assertSame('demo', $comment->getReference());
        self::assertSame($parent, $comment->getParent());
        self::assertTrue($comment->isReply());
        self::assertSame([], $comment->getReplies()->toArray());
        self::assertSame($user, $comment->getAuthor());
        self::assertSame('Tester', $comment->getAuthorName());
        self::assertSame('tester@example.test', $comment->getAuthorEmail());
        self::assertSame('Hello', $comment->getBody());
        self::assertSame(CommentStatus::Pending, $comment->getStatus());
        self::assertSame($createdAt, $comment->getCreatedAt());
        self::assertNotSame($oldUpdatedAt, $comment->getUpdatedAt());
        self::assertSame('hash', $comment->getIpHash());
        self::assertSame([], $comment->getReactions()->toArray());
    }

    public function testReactionAccessors(): void
    {
        $comment = (new DiscussionComment())
            ->setReference('demo')
            ->setAuthorName('Tester')
            ->setBody('Hello')
            ->setStatus(CommentStatus::Published);

        $reaction = (new DiscussionReaction())
            ->setComment($comment)
            ->setEmoji('👍')
            ->setVisitorToken('anon:abc')
            ->setIpHash('hash');

        self::assertNull($reaction->getId());
        self::assertSame($comment, $reaction->getComment());
        self::assertSame('👍', $reaction->getEmoji());
        self::assertSame('anon:abc', $reaction->getVisitorToken());
        self::assertSame('hash', $reaction->getIpHash());
        self::assertNotNull($reaction->getCreatedAt());
    }

    private function setDate(object $entity, string $property, string $value): void
    {
        $reflection = new \ReflectionProperty($entity, $property);
        $reflection->setValue($entity, new \DateTimeImmutable($value));
    }
}
