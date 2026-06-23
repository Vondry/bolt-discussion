<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Enum;

use BoltDiscussion\Enum\CommentStatus;
use PHPUnit\Framework\TestCase;

class CommentStatusTest extends TestCase
{
    public function testOnlyPublishedIsPubliclyVisible(): void
    {
        self::assertSame([CommentStatus::Published], CommentStatus::publicStatuses());
        self::assertNotContains(CommentStatus::Pending, CommentStatus::publicStatuses());
        self::assertNotContains(CommentStatus::Spam, CommentStatus::publicStatuses());
        self::assertNotContains(CommentStatus::Deleted, CommentStatus::publicStatuses());
    }

    public function testBackedValues(): void
    {
        self::assertSame('published', CommentStatus::Published->value);
        self::assertSame('pending', CommentStatus::Pending->value);
        self::assertSame('Pending', CommentStatus::Pending->label());
    }
}
