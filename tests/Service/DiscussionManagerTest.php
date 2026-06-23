<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Service;

use Bolt\Entity\User;
use BoltDiscussion\Entity\DiscussionComment;
use BoltDiscussion\Entity\DiscussionReaction;
use BoltDiscussion\Enum\CommentStatus;
use BoltDiscussion\Exception\ValidationException;
use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Repository\DiscussionReactionRepository;
use BoltDiscussion\Service\DiscussionConfig;
use BoltDiscussion\Service\DiscussionManager;
use BoltDiscussion\Service\SpamChecker;
use BoltDiscussion\Service\VisitorTokenProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the core user-facing rules: validation, one-level threading,
 * moderation/spam decisions, and reaction toggling.
 */
class DiscussionManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DiscussionCommentRepository&MockObject $comments;
    private DiscussionReactionRepository&MockObject $reactions;
    private DiscussionConfig&MockObject $config;
    private SpamChecker&MockObject $spamChecker;
    private VisitorTokenProvider&MockObject $visitor;
    private DiscussionManager $manager;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->comments = $this->createMock(DiscussionCommentRepository::class);
        $this->reactions = $this->createMock(DiscussionReactionRepository::class);
        $this->config = $this->createMock(DiscussionConfig::class);
        $this->spamChecker = $this->createMock(SpamChecker::class);
        $this->visitor = $this->createMock(VisitorTokenProvider::class);

        // Sensible defaults; individual tests override as needed.
        $this->config->method('maxLength')->willReturn(2000);
        $this->config->method('requireName')->willReturn(true);
        $this->config->method('repliesEnabled')->willReturn(true);
        $this->config->method('isQueueModeration')->willReturn(false);
        $this->config->method('reactionsEnabled')->willReturn(true);
        $this->config->method('isAllowedReaction')->willReturnCallback(
            static fn (string $e): bool => in_array($e, ['👍', '❤️'], true)
        );
        $this->config->method('pageSize')->willReturn(10);
        $this->config->method('clampPageSize')->willReturnCallback(
            static fn (int $n): int => $n < 1 ? 10 : min($n, 100)
        );

        $this->manager = new DiscussionManager(
            $this->em,
            $this->comments,
            $this->reactions,
            $this->config,
            $this->spamChecker,
            $this->visitor,
            'test-ip-hash-key',
        );
    }

    private function request(string $ip = '203.0.113.5'): Request
    {
        return Request::create('/discussion/api/demo', 'POST', server: ['REMOTE_ADDR' => $ip]);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $this->visitor->method('getUser')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->manager->createComment('demo', ['body' => '   ', 'authorName' => 'Jo'], $this->request());
    }

    public function testTooLongBodyIsRejected(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->config = $this->createMock(DiscussionConfig::class);

        $manager = new DiscussionManager($this->em, $this->comments, $this->reactions, $this->config, $this->spamChecker, $this->visitor, 'test-ip-hash-key');
        $this->config->method('maxLength')->willReturn(5);

        $this->expectException(ValidationException::class);
        $manager->createComment('demo', ['body' => 'way too long', 'authorName' => 'Jo'], $this->request());
    }

    public function testAnonymousMissingNameIsRejectedWhenRequired(): void
    {
        $this->visitor->method('getUser')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('name');
        $this->manager->createComment('demo', ['body' => 'Hello', 'authorName' => ''], $this->request());
    }

    public function testInvalidReferenceIsRejectedBeforePersistence(): void
    {
        $this->em->expects(self::never())->method('persist');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reference');
        $this->manager->createComment(str_repeat('a', 192), ['body' => 'Hello', 'authorName' => 'Jo'], $this->request());
    }

    public function testAnonymousAutoModerationPublishesImmediately(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(false);
        $this->spamChecker->method('isRateLimited')->willReturn(false);
        $expectedIpHash = hash_hmac('sha256', 'bolt-discussion|203.0.113.5', 'test-ip-hash-key');
        $this->em->expects(self::once())->method('persist')->with(
            self::callback(static fn (DiscussionComment $comment): bool => $comment->getIpHash() === $expectedIpHash)
        );
        $this->em->expects(self::once())->method('flush');

        $result = $this->manager->createComment('demo', ['body' => 'Hello', 'authorName' => 'Jo'], $this->request());

        self::assertSame('published', $result['status']);
        self::assertNotNull($result['comment']);
        self::assertSame('Jo', $result['comment']['author']);
    }

    public function testAnonymousQueueModerationProducesPending(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('maxLength')->willReturn(2000);
        $config->method('requireName')->willReturn(true);
        $config->method('isQueueModeration')->willReturn(true);
        $manager = new DiscussionManager($this->em, $this->comments, $this->reactions, $config, $this->spamChecker, $this->visitor, 'test-ip-hash-key');

        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(false);
        $this->spamChecker->method('isRateLimited')->willReturn(false);

        $result = $manager->createComment('demo', ['body' => 'Hello', 'authorName' => 'Jo'], $this->request());

        self::assertSame('pending', $result['status']);
    }

    public function testLoggedInUserAlwaysPublishesEvenInQueueMode(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('maxLength')->willReturn(2000);
        $config->method('isQueueModeration')->willReturn(true);
        $manager = new DiscussionManager($this->em, $this->comments, $this->reactions, $config, $this->spamChecker, $this->visitor, 'test-ip-hash-key');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getDisplayName')->willReturn('Admin');
        $this->visitor->method('getUser')->willReturn($user);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(false);

        $result = $manager->createComment('demo', ['body' => 'Hi', 'authorName' => ''], $this->request());

        self::assertSame('published', $result['status']);
        self::assertTrue($result['comment']['authenticated']);
        self::assertSame('Admin', $result['comment']['author']);
    }

    public function testHoneypotIsDroppedWithoutPersisting(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(true);
        // Honeypot trips must never reach the database (anti-flooding).
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $result = $this->manager->createComment('demo', ['body' => 'buy now', 'authorName' => 'Bot', 'website' => 'http://x'], $this->request());

        // Looks like a success to the bot, but nothing was stored or returned.
        self::assertSame('published', $result['status']);
        self::assertNull($result['comment']);
    }

    public function testRegexSpamIsStoredButNeverReturned(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('isRateLimited')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(true);
        $this->em->expects(self::once())->method('persist');

        $result = $this->manager->createComment('demo', ['body' => 'spammy', 'authorName' => 'Bot'], $this->request());

        self::assertSame('spam', $result['status']);
        self::assertNull($result['comment'], 'Spam must not be echoed back to the client.');
    }

    public function testRegexSpamIsAlsoRateLimited(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(true);
        $this->spamChecker->method('isRateLimited')->willReturn(true);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(ValidationException::class);
        $this->manager->createComment('demo', ['body' => 'spammy', 'authorName' => 'Bot'], $this->request());
    }

    public function testRateLimitedAnonymousIsRejected(): void
    {
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(false);
        $this->spamChecker->method('isRateLimited')->willReturn(true);
        $this->em->expects(self::never())->method('persist');

        $this->expectException(ValidationException::class);
        $this->manager->createComment('demo', ['body' => 'Hello', 'authorName' => 'Jo'], $this->request());
    }

    public function testReplyToRootIsAccepted(): void
    {
        $root = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $this->comments->method('find')->with(1)->willReturn($root);
        $this->visitor->method('getUser')->willReturn(null);
        $this->spamChecker->method('isHoneypotTripped')->willReturn(false);
        $this->spamChecker->method('matchesSpamRegex')->willReturn(false);
        $this->spamChecker->method('isRateLimited')->willReturn(false);

        $result = $this->manager->createComment('demo', ['body' => 'A reply', 'authorName' => 'Jo', 'parentId' => 1], $this->request());

        self::assertSame('published', $result['status']);
    }

    public function testNestedReplyIsRejected(): void
    {
        $root = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $reply = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published)->setParent($root);
        $this->comments->method('find')->with(2)->willReturn($reply);
        $this->visitor->method('getUser')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('nested');
        $this->manager->createComment('demo', ['body' => 'nope', 'authorName' => 'Jo', 'parentId' => 2], $this->request());
    }

    public function testReplyToCommentFromOtherReferenceIsRejected(): void
    {
        $root = (new DiscussionComment())->setReference('other')->setStatus(CommentStatus::Published);
        $this->comments->method('find')->with(1)->willReturn($root);
        $this->visitor->method('getUser')->willReturn(null);

        $this->expectException(ValidationException::class);
        $this->manager->createComment('demo', ['body' => 'x', 'authorName' => 'Jo', 'parentId' => 1], $this->request());
    }

    public function testToggleReactionAddsWhenAbsent(): void
    {
        $comment = (new DiscussionComment())->setReference('demo');
        $this->visitor->method('getToken')->willReturn('visitor-1');
        $this->reactions->method('findOneFor')->willReturn(null);
        $this->reactions->method('summaryFor')->willReturn([0 => ['👍' => ['count' => 1, 'mine' => true]]]);
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(DiscussionReaction::class));

        $result = $this->manager->toggleReaction($comment, '👍');

        self::assertTrue($result['mine']);
        self::assertSame(1, $result['count']);
    }

    public function testToggleReactionRemovesWhenPresent(): void
    {
        $comment = (new DiscussionComment())->setReference('demo');
        $existing = (new DiscussionReaction())->setComment($comment)->setEmoji('👍')->setVisitorToken('visitor-1');
        $this->visitor->method('getToken')->willReturn('visitor-1');
        $this->reactions->method('findOneFor')->willReturn($existing);
        $this->reactions->method('summaryFor')->willReturn([0 => []]);
        $this->em->expects(self::once())->method('remove')->with($existing);

        $result = $this->manager->toggleReaction($comment, '👍');

        self::assertFalse($result['mine']);
        self::assertSame(0, $result['count']);
    }

    public function testDisallowedReactionEmojiIsRejected(): void
    {
        $comment = (new DiscussionComment())->setReference('demo');

        $this->expectException(ValidationException::class);
        $this->manager->toggleReaction($comment, '🚀');
    }

    public function testGetPagePaginatesRootsAndIncludesReplies(): void
    {
        // perPage = 2; repository returns 3 roots (limit+1) so hasMore is true.
        $r5 = $this->comment(5);
        $r4 = $this->comment(4);
        $r3 = $this->comment(3);
        $reply = $this->comment(6, $r5);

        $this->comments->expects(self::once())
            ->method('findRootsPage')
            ->with('demo', null, 3, false)
            ->willReturn([$r5, $r4, $r3]);
        $this->comments->expects(self::once())
            ->method('findRepliesForRoots')
            ->with([5, 4], false)
            ->willReturn([$reply]);
        $this->reactions->method('summaryFor')->willReturn([]);
        $this->visitor->method('getToken')->willReturn('anon:abc');

        $page = $this->manager->getPage('demo', null, 2, false);

        self::assertTrue($page['hasMore']);
        self::assertSame(4, $page['nextBefore'], 'Cursor is the oldest root id on the page.');
        self::assertCount(3, $page['comments'], 'Two roots plus one reply.');
        self::assertSame(6, $page['lastId']);
    }

    public function testGetPageReportsNoMoreWhenPageNotFull(): void
    {
        $this->comments->method('findRootsPage')->willReturn([$this->comment(2), $this->comment(1)]);
        $this->comments->method('findRepliesForRoots')->willReturn([]);
        $this->reactions->method('summaryFor')->willReturn([]);
        $this->visitor->method('getToken')->willReturn('anon:abc');

        $page = $this->manager->getPage('demo', null, 5, false);

        self::assertFalse($page['hasMore']);
        self::assertCount(2, $page['comments']);
    }

    public function testPollingUsesBoundedBatchSize(): void
    {
        $this->comments->expects(self::once())
            ->method('findThread')
            ->with('demo', 0, false, 100)
            ->willReturn([]);
        $this->reactions->method('summaryFor')->willReturn([]);
        $this->visitor->method('getToken')->willReturn('anon:abc');

        $thread = $this->manager->getThread('demo', 0, false);

        self::assertSame([], $thread['comments']);
        self::assertSame(0, $thread['lastId']);
    }

    public function testPollUpdatesIncludeRemovedCommentsAndCompleteReactionSnapshots(): void
    {
        $first = $this->comment(3);
        $this->comments->expects(self::once())
            ->method('findVisibleByIds')
            ->with('demo', [3, 7], false)
            ->willReturn([$first]);
        $this->visitor->method('getToken')->willReturn('anon:abc');
        $this->reactions->expects(self::once())
            ->method('summaryFor')
            ->with([3], 'anon:abc')
            ->willReturn([
                3 => ['👍' => ['count' => 4, 'mine' => false]],
            ]);

        $updates = $this->manager->getPollUpdates('demo', [3, 7], false);

        self::assertSame([
            'removedCommentIds' => [7],
            'reactionUpdates' => [[
                'commentId' => 3,
                'reactions' => [['emoji' => '👍', 'count' => 4, 'mine' => false]],
            ]],
        ], $updates);
    }

    public function testModeratorPollKeepsPendingCommentsVisible(): void
    {
        $pending = $this->comment(7)->setStatus(CommentStatus::Pending);
        $this->comments->expects(self::once())
            ->method('findVisibleByIds')
            ->with('demo', [7], true)
            ->willReturn([$pending]);
        $this->visitor->method('getToken')->willReturn('user:1');
        $this->reactions->expects(self::once())
            ->method('summaryFor')
            ->with([7], 'user:1')
            ->willReturn([]);

        $updates = $this->manager->getPollUpdates('demo', [7], true);

        self::assertSame([], $updates['removedCommentIds']);
        self::assertSame([['commentId' => 7, 'reactions' => []]], $updates['reactionUpdates']);
    }

    private function comment(int $id, ?DiscussionComment $parent = null): DiscussionComment
    {
        $comment = (new DiscussionComment())
            ->setReference('demo')
            ->setAuthorName('A')
            ->setBody('b')
            ->setStatus(CommentStatus::Published);
        if ($parent !== null) {
            $comment->setParent($parent);
        }
        $property = new \ReflectionProperty(DiscussionComment::class, 'id');
        $property->setValue($comment, $id);

        return $comment;
    }
}
