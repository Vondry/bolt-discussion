<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Service;

use Bolt\Extension\ExtensionRegistry;
use BoltDiscussion\Service\DiscussionConfig;
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
}
