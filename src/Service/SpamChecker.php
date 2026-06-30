<?php

declare(strict_types=1);

namespace Bolt\Discussion\Service;

use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Repository\DiscussionReactionRepository;
use DateTimeImmutable;

/**
 * Lightweight, dependency-free spam heuristics for anonymous submissions.
 */
class SpamChecker
{
    public function __construct(
        private readonly DiscussionConfig $config,
        private readonly DiscussionCommentRepository $comments,
        private readonly DiscussionReactionRepository $reactions,
    ) {
    }

    /**
     * A filled honeypot field is a strong bot signal.
     */
    public function isHoneypotTripped(?string $honeypotValue): bool
    {
        return is_string($honeypotValue) && trim($honeypotValue) !== '';
    }

    /**
     * Whether the body matches the configured spam regex (if any).
     */
    public function matchesSpamRegex(string $body): bool
    {
        $pattern = $this->config->spamRegex();
        if ($pattern === '') {
            return false;
        }

        return @preg_match($pattern, $body) === 1;
    }

    /**
     * Whether this hashed IP is currently rate-limited.
     */
    public function isRateLimited(?string $ipHash): bool
    {
        $seconds = $this->config->rateLimitSeconds();
        if ($seconds <= 0 || $ipHash === null) {
            return false;
        }

        $since = new DateTimeImmutable(sprintf('-%d seconds', $seconds));

        return $this->comments->hasRecentFromIp($ipHash, $since);
    }

    /**
     * Whether this hashed IP has hit the cap on new reactions for the current
     * window. Anonymous reactions are de-duplicated by a client-supplied token,
     * so this per-IP cap is what actually prevents reaction-count inflation.
     */
    public function isReactionFlooding(?string $ipHash): bool
    {
        $max = $this->config->reactionRateLimit();
        $seconds = $this->config->reactionRateLimitSeconds();
        if ($max <= 0 || $seconds <= 0 || $ipHash === null) {
            return false;
        }

        $since = new DateTimeImmutable(sprintf('-%d seconds', $seconds));

        return $this->reactions->countRecentFromIp($ipHash, $since) >= $max;
    }
}
