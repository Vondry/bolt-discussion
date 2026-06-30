<?php

declare(strict_types=1);

namespace Bolt\Discussion\Service;

use Bolt\Discussion\Extension;
use Bolt\Extension\ExtensionRegistry;

/**
 * Typed accessor over the extension's config (config/extensions/bolt-discussion.yaml),
 * with sane fallbacks so services never have to deal with missing keys.
 */
class DiscussionConfig
{
    private const DEFAULT_PAGE_SIZE = 10;

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(
        private readonly ExtensionRegistry $registry
    ) {
    }

    public function moderation(): string
    {
        return (string) $this->get('moderation', 'auto');
    }

    public function isQueueModeration(): bool
    {
        return $this->moderation() === 'queue';
    }

    public function pollInterval(): int
    {
        return (int) $this->get('poll_interval', 10000);
    }

    public function pageSize(): int
    {
        return $this->clampPageSize((int) $this->get('page_size', self::DEFAULT_PAGE_SIZE));
    }

    /**
     * Clamp a requested page size to a sane range so a client can't ask for an
     * unbounded number of comments in one response.
     */
    public function clampPageSize(int $requested): int
    {
        if ($requested < 1) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min($requested, 100);
    }

    public function reactionsEnabled(): bool
    {
        return (bool) $this->get('reactions_enabled', true);
    }

    /**
     * @return string[]
     */
    public function reactions(): array
    {
        $reactions = $this->get('reactions', ['👍', '❤️', '😂', '🎉', '😮']);

        return is_array($reactions) ? array_values(array_map(strval(...), $reactions)) : [];
    }

    public function isAllowedReaction(string $emoji): bool
    {
        return in_array($emoji, $this->reactions(), true);
    }

    public function repliesEnabled(): bool
    {
        return (bool) $this->get('replies_enabled', true);
    }

    public function maxLength(): int
    {
        return (int) $this->get('max_length', 2000);
    }

    public function requireName(): bool
    {
        return (bool) $this->get('require_name', true);
    }

    public function spamRegex(): string
    {
        return (string) $this->get('spam_regex', '');
    }

    public function rateLimitSeconds(): int
    {
        return (int) $this->get('rate_limit_seconds', 10);
    }

    public function reactionRateLimit(): int
    {
        return (int) $this->get('reaction_rate_limit', 20);
    }

    public function reactionRateLimitSeconds(): int
    {
        return (int) $this->get('reaction_rate_limit_seconds', 60);
    }

    private function get(string $key, mixed $default): mixed
    {
        if ($this->config === null) {
            $extension = $this->registry->getExtension(Extension::class);
            $this->config = $extension instanceof Extension ? $extension->getConfig()->toArray() : [];
        }

        return $this->config[$key] ?? $default;
    }
}
