<?php

declare(strict_types=1);

namespace Bolt\Discussion\Service;

use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Entity\DiscussionReaction;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Exception\ValidationException;
use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Repository\DiscussionReactionRepository;
use Bolt\Utils\ThumbnailHelper;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Orchestrates reading and writing discussions: validation, one-level threading,
 * moderation/spam decisions, reactions, and serialization for the JSON API.
 */
class DiscussionManager
{
    private const POLL_BATCH_SIZE = 100;
    private const REFERENCE_PATTERN = '/\A[A-Za-z0-9_.:-]{1,191}\z/D';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiscussionCommentRepository $comments,
        private readonly DiscussionReactionRepository $reactions,
        private readonly DiscussionConfig $config,
        private readonly SpamChecker $spamChecker,
        private readonly VisitorTokenProvider $visitor,
        private readonly ThumbnailHelper $thumbnails,
        #[Autowire(param: 'kernel.secret')]
        private readonly string $ipHashKey,
    ) {
    }

    /**
     * @param array{body?: string, authorName?: string, parentId?: int|string|null, website?: string} $input
     * @return array{status: string, comment: array<string, mixed>|null}
     */
    public function createComment(string $reference, array $input, Request $request): array
    {
        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            throw new ValidationException('Invalid discussion reference.');
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            throw new ValidationException('Comment cannot be empty.');
        }
        if (mb_strlen($body) > $this->config->maxLength()) {
            throw new ValidationException('Comment is too long (max %max% characters).', ['%max%' => $this->config->maxLength()]);
        }

        $user = $this->visitor->getUser();
        $authorName = trim((string) ($input['authorName'] ?? ''));
        if ($user !== null) {
            $authorName = $authorName !== '' ? $authorName : ($user->getDisplayName() ?: $user->getUserIdentifier());
        } elseif ($authorName === '' && $this->config->requireName()) {
            throw new ValidationException('Please provide your name.');
        } elseif ($authorName === '') {
            $authorName = 'Anonymous';
        }

        // A filled honeypot is an almost-certain bot. Drop it without writing to
        // the database (so it can't be used to flood storage) and without tipping
        // the bot off — return a plausible "published" response with no payload.
        if ($this->spamChecker->isHoneypotTripped($input['website'] ?? null)) {
            return [
                'status' => CommentStatus::Published->value,
                'comment' => null,
            ];
        }

        $parent = $this->resolveParent($input['parentId'] ?? null, $reference);

        $ipHash = $this->hashIp($request->getClientIp());

        // Decide the moderation status.
        if ($user !== null) {
            $status = CommentStatus::Published;
        } else {
            // Rate-limit every anonymous write — including regex-flagged spam — so
            // a single IP cannot flood the table regardless of content.
            if ($this->spamChecker->isRateLimited($ipHash)) {
                throw new ValidationException('You are posting too quickly. Please wait a moment and try again.');
            }

            if ($this->spamChecker->matchesSpamRegex($body)) {
                $status = CommentStatus::Spam;
            } else {
                $status = $this->config->isQueueModeration() ? CommentStatus::Pending : CommentStatus::Published;
            }
        }

        $comment = (new DiscussionComment())
            ->setReference($reference)
            ->setParent($parent)
            ->setAuthor($user)
            ->setAuthorName(mb_substr($authorName, 0, 191))
            ->setBody($body)
            ->setStatus($status)
            ->setIpHash($ipHash);

        $this->em->persist($comment);
        $this->em->flush();

        // Spam is accepted silently (no hint to bots) but never returned.
        $visible = $status !== CommentStatus::Spam;

        return [
            'status' => $status->value,
            'comment' => $visible ? $this->serializeComment($comment, []) : null,
        ];
    }

    /**
     * Delete a comment and, when it is a root, its whole thread. Replies are
     * deleted alongside their parent so none is left orphaned, and every
     * affected comment's reactions are purged so no orphaned reaction data
     * remains.
     */
    public function deleteComment(DiscussionComment $comment): void
    {
        $comment->setStatus(CommentStatus::Deleted);

        $affectedIds = [(int) $comment->getId()];

        if (! $comment->isReply()) {
            foreach ($this->comments->findReplies($comment) as $reply) {
                $reply->setStatus(CommentStatus::Deleted);
                $affectedIds[] = (int) $reply->getId();
            }
        }

        $this->reactions->deleteForComments($affectedIds);

        $this->em->flush();
    }

    public function setStatus(DiscussionComment $comment, CommentStatus $status): void
    {
        $comment->setStatus($status);
        $this->em->flush();
    }

    /**
     * Flat, serialized list of visible comments for a discussion. The client
     * assembles the one-level tree and merges polling results by id.
     *
     * @return array{comments: array<int, array<string, mixed>>, lastId: int, serverTime: string}
     */
    public function getThread(string $reference, ?int $sinceId, bool $includePending): array
    {
        $entities = $this->comments->findThread($reference, $sinceId, $includePending, self::POLL_BATCH_SIZE);
        $ids = array_map(static fn (DiscussionComment $c): int => (int) $c->getId(), $entities);
        $summary = $this->reactions->summaryFor($ids, $this->visitor->getToken());

        $comments = [];
        $lastId = $sinceId ?? 0;
        foreach ($entities as $entity) {
            $comments[] = $this->serializeComment($entity, $summary[(int) $entity->getId()] ?? []);
            $lastId = max($lastId, (int) $entity->getId());
        }

        return [
            'comments' => $comments,
            'lastId' => $lastId,
            'serverTime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * A cursor-paginated page of root comments (newest first) together with all
     * their replies. Pass $beforeId (the oldest root id already loaded) to fetch
     * the next, older page via "Load more".
     *
     * @return array{comments: array<int, array<string, mixed>>, lastId: int, hasMore: bool, nextBefore: int|null, serverTime: string}
     */
    public function getPage(string $reference, ?int $beforeId, ?int $perPage, bool $includePending): array
    {
        $perPage = $this->config->clampPageSize($perPage ?? $this->config->pageSize());

        // Fetch one extra root to detect whether older pages exist.
        $roots = $this->comments->findRootsPage($reference, $beforeId, $perPage + 1, $includePending);
        $hasMore = count($roots) > $perPage;
        if ($hasMore) {
            $roots = array_slice($roots, 0, $perPage);
        }

        $rootIds = array_map(static fn (DiscussionComment $c): int => (int) $c->getId(), $roots);
        $replies = $this->comments->findRepliesForRoots($rootIds, $includePending);

        $entities = array_merge($roots, $replies);
        $ids = array_map(static fn (DiscussionComment $c): int => (int) $c->getId(), $entities);
        $summary = $this->reactions->summaryFor($ids, $this->visitor->getToken());

        $comments = [];
        $lastId = 0;
        foreach ($entities as $entity) {
            $comments[] = $this->serializeComment($entity, $summary[(int) $entity->getId()] ?? []);
            $lastId = max($lastId, (int) $entity->getId());
        }

        return [
            'comments' => $comments,
            'lastId' => $lastId,
            'hasMore' => $hasMore,
            // Oldest root id in this page; the cursor for the next "Load more".
            'nextBefore' => $rootIds === [] ? null : min($rootIds),
            'serverTime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Visibility and reaction snapshots for already-loaded comments. Missing
     * IDs represent comments that were deleted, marked as spam, or are otherwise
     * no longer visible to the current visitor.
     *
     * @param int[] $commentIds
     * @return array{
     *     removedCommentIds: int[],
     *     reactionUpdates: array<int, array{
     *         commentId: int,
     *         reactions: array<int, array{emoji: string, count: int, mine: bool}>
     *     }>
     * }
     */
    public function getPollUpdates(string $reference, array $commentIds, bool $includePending): array
    {
        $entities = $this->comments->findVisibleByIds($reference, $commentIds, $includePending);
        $visibleIds = array_map(static fn (DiscussionComment $comment): int => (int) $comment->getId(), $entities);
        $summary = $this->reactions->summaryFor($visibleIds, $this->visitor->getToken());

        $updates = [];
        foreach ($visibleIds as $id) {
            $updates[] = [
                'commentId' => $id,
                'reactions' => $this->serializeReactions($summary[$id] ?? []),
            ];
        }

        return [
            'removedCommentIds' => array_values(array_diff($commentIds, $visibleIds)),
            'reactionUpdates' => $updates,
        ];
    }

    /**
     * Toggle a reaction for the current visitor. Returns the new aggregate.
     *
     * @return array{commentId: int, emoji: string, count: int, mine: bool}
     */
    public function toggleReaction(DiscussionComment $comment, string $emoji, Request $request): array
    {
        if (! $this->config->reactionsEnabled()) {
            throw new ValidationException('Reactions are disabled.');
        }
        if (! $this->config->isAllowedReaction($emoji)) {
            throw new ValidationException('Unknown reaction.');
        }

        $token = $this->visitor->getToken();
        $existing = $this->reactions->findOneFor((int) $comment->getId(), $emoji, $token);

        if ($existing !== null) {
            $this->em->remove($existing);
            $mine = false;
        } else {
            $ipHash = $this->hashIp($request->getClientIp());

            // Only anonymous additions can inflate counts (logged-in users are
            // capped at one reaction per emoji per comment), so throttle those.
            if (! $this->visitor->isLoggedIn() && $this->spamChecker->isReactionFlooding($ipHash)) {
                throw new ValidationException('You are reacting too quickly. Please slow down and try again.');
            }

            $reaction = (new DiscussionReaction())
                ->setComment($comment)
                ->setEmoji($emoji)
                ->setVisitorToken($token)
                ->setIpHash($ipHash);
            $this->em->persist($reaction);
            $mine = true;
        }

        $this->em->flush();

        $summary = $this->reactions->summaryFor([(int) $comment->getId()], $token);
        $count = $summary[(int) $comment->getId()][$emoji]['count'] ?? 0;

        return [
            'commentId' => (int) $comment->getId(),
            'emoji' => $emoji,
            'count' => $count,
            'mine' => $mine,
        ];
    }

    private function resolveParent(int|string|null $parentId, string $reference): ?DiscussionComment
    {
        if ($parentId === null || $parentId === '' || (int) $parentId === 0) {
            return null;
        }

        if (! $this->config->repliesEnabled()) {
            throw new ValidationException('Replies are disabled.');
        }

        $parent = $this->comments->find((int) $parentId);
        if ($parent === null || $parent->getReference() !== $reference) {
            throw new ValidationException('The comment you are replying to no longer exists.');
        }
        if ($parent->isReply()) {
            throw new ValidationException('Replies cannot be nested deeper than one level.');
        }
        if ($parent->getStatus() !== CommentStatus::Published) {
            throw new ValidationException('You cannot reply to this comment.');
        }

        return $parent;
    }

    /**
     * @param array<string, array{count: int, mine: bool}> $reactionSummary
     * @return array<string, mixed>
     */
    private function serializeComment(DiscussionComment $comment, array $reactionSummary): array
    {
        $data = [
            'id' => (int) $comment->getId(),
            'parentId' => $comment->getParent()?->getId(),
            'author' => $comment->getAuthorName(),
            'authenticated' => $comment->getAuthor() !== null,
            'body' => $comment->getBody(),
            'status' => $comment->getStatus()->value,
            'createdAt' => $comment->getCreatedAt()->format(DateTimeInterface::ATOM),
            'reactions' => $this->serializeReactions($reactionSummary),
        ];

        // Include avatar URL if the user has one configured. Uses Bolt's
        // thumbnail pipeline (the same /thumbs/… route Bolt renders avatars
        // with), so it works regardless of how asset packages are configured.
        $author = $comment->getAuthor();
        $avatar = $author?->getAvatar();
        if ($avatar !== null && $avatar !== '') {
            $data['avatarUrl'] = $this->thumbnails->path($avatar, 64, 64, null, null, 'crop');
        }

        return $data;
    }

    /**
     * @param array<string, array{count: int, mine: bool}> $reactionSummary
     * @return array<int, array{emoji: string, count: int, mine: bool}>
     */
    private function serializeReactions(array $reactionSummary): array
    {
        $reactions = [];
        foreach ($reactionSummary as $emoji => $data) {
            $reactions[] = [
                'emoji' => $emoji,
                'count' => $data['count'],
                'mine' => $data['mine'],
            ];
        }

        return $reactions;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', 'bolt-discussion|' . $ip, $this->ipHashKey);
    }
}
