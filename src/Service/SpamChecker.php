<?php

declare(strict_types=1);

namespace Bolt\Discussion\Service;

use Bolt\Discussion\Repository\DiscussionCommentRepository;
use DateTimeImmutable;

/**
 * Lightweight, dependency-free spam heuristics for anonymous submissions.
 */
class SpamChecker
{
    public function __construct(
        private readonly DiscussionConfig $config,
        private readonly DiscussionCommentRepository $comments,
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
}
