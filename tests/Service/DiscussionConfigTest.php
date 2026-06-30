<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Service;

use Bolt\Discussion\Extension;
use Bolt\Discussion\Service\DiscussionConfig;
use Bolt\Extension\ExtensionRegistry;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class DiscussionConfigTest extends TestCase
{
    public function testDefaultAndInvalidPageSizesUseTen(): void
    {
        $registry = $this->createMock(ExtensionRegistry::class);
        $registry->method('getExtension')->willReturn(null);
        $config = new DiscussionConfig($registry);

        self::assertSame(10, $config->pageSize());
        self::assertSame(10, $config->clampPageSize(0));
        self::assertSame(10, $config->clampPageSize(-1));
    }

    public function testPageSizeStillClampsAtOneHundred(): void
    {
        $config = new DiscussionConfig($this->createMock(ExtensionRegistry::class));

        self::assertSame(100, $config->clampPageSize(101));
    }

    public function testReactionRateLimitDefaults(): void
    {
        $registry = $this->createMock(ExtensionRegistry::class);
        $registry->method('getExtension')->willReturn(null);
        $config = new DiscussionConfig($registry);

        self::assertSame(20, $config->reactionRateLimit());
        self::assertSame(60, $config->reactionRateLimitSeconds());
    }

    public function testConfiguredValuesAreReturnedWithTypedFallbacks(): void
    {
        $extension = $this->createMock(Extension::class);
        $extension->method('getConfig')->willReturn(new Collection([
            'moderation' => 'queue',
            'poll_interval' => 2500,
            'page_size' => 25,
            'reactions_enabled' => false,
            'reactions' => ['ok', 7],
            'replies_enabled' => false,
            'max_length' => 123,
            'require_name' => false,
            'spam_regex' => '/spam/i',
            'rate_limit_seconds' => 42,
            'reaction_rate_limit' => 3,
            'reaction_rate_limit_seconds' => 9,
        ]));

        $registry = $this->createMock(ExtensionRegistry::class);
        $registry->method('getExtension')->with(Extension::class)->willReturn($extension);

        $config = new DiscussionConfig($registry);

        self::assertSame('queue', $config->moderation());
        self::assertTrue($config->isQueueModeration());
        self::assertSame(2500, $config->pollInterval());
        self::assertSame(25, $config->pageSize());
        self::assertFalse($config->reactionsEnabled());
        self::assertSame(['ok', '7'], $config->reactions());
        self::assertTrue($config->isAllowedReaction('ok'));
        self::assertFalse($config->isAllowedReaction('missing'));
        self::assertFalse($config->repliesEnabled());
        self::assertSame(123, $config->maxLength());
        self::assertFalse($config->requireName());
        self::assertSame('/spam/i', $config->spamRegex());
        self::assertSame(42, $config->rateLimitSeconds());
        self::assertSame(3, $config->reactionRateLimit());
        self::assertSame(9, $config->reactionRateLimitSeconds());
    }

    public function testNonArrayReactionsConfigReturnsEmptyList(): void
    {
        $extension = $this->createMock(Extension::class);
        $extension->method('getConfig')->willReturn(new Collection(['reactions' => '👍']));

        $registry = $this->createMock(ExtensionRegistry::class);
        $registry->method('getExtension')->willReturn($extension);

        self::assertSame([], (new DiscussionConfig($registry))->reactions());
    }
}
