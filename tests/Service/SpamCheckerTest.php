<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Service;

use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Service\DiscussionConfig;
use Bolt\Discussion\Service\SpamChecker;
use PHPUnit\Framework\TestCase;

class SpamCheckerTest extends TestCase
{
    private function checker(DiscussionConfig $config, ?DiscussionCommentRepository $comments = null): SpamChecker
    {
        return new SpamChecker($config, $comments ?? $this->createMock(DiscussionCommentRepository::class));
    }

    public function testHoneypotDetection(): void
    {
        $checker = $this->checker($this->createMock(DiscussionConfig::class));

        self::assertTrue($checker->isHoneypotTripped('http://spam'));
        self::assertFalse($checker->isHoneypotTripped(''));
        self::assertFalse($checker->isHoneypotTripped('   '));
        self::assertFalse($checker->isHoneypotTripped(null));
    }

    public function testSpamRegexMatching(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('spamRegex')->willReturn('/casino|viagra/i');
        $checker = $this->checker($config);

        self::assertTrue($checker->matchesSpamRegex('Best CASINO deals'));
        self::assertFalse($checker->matchesSpamRegex('A normal comment'));
    }

    public function testEmptyRegexNeverMatches(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('spamRegex')->willReturn('');
        $checker = $this->checker($config);

        self::assertFalse($checker->matchesSpamRegex('anything at all'));
    }

    public function testRateLimitDisabledWhenZero(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('rateLimitSeconds')->willReturn(0);
        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->expects(self::never())->method('hasRecentFromIp');

        self::assertFalse($this->checker($config, $comments)->isRateLimited('abc'));
    }

    public function testRateLimitConsultsRepositoryWhenEnabled(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('rateLimitSeconds')->willReturn(30);
        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->expects(self::once())
            ->method('hasRecentFromIp')
            ->with('abc', self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(true);

        self::assertTrue($this->checker($config, $comments)->isRateLimited('abc'));
    }

    public function testRateLimitSkippedForNullIp(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('rateLimitSeconds')->willReturn(30);

        self::assertFalse($this->checker($config)->isRateLimited(null));
    }
}
