<?php

declare(strict_types=1);

namespace Bolt\Discussion\Entity;

use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscussionCommentRepository::class)]
#[ORM\Table(name: 'bolt_discussion_comment')]
#[ORM\Index(name: 'bd_comment_reference_idx', columns: ['reference'])]
#[ORM\Index(name: 'bd_comment_created_idx', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class DiscussionComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Arbitrary discussion key; a page may host several independent discussions. */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $reference;

    /** A reply points at its root comment. Replies are limited to one level deep. */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $replies;

    /** Set when the comment was posted by a logged-in Bolt user. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $authorName;

    /** Never serialized to the public API. */
    #[ORM\Column(type: Types::STRING, length: 191, nullable: true)]
    private ?string $authorEmail = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CommentStatus::class)]
    private CommentStatus $status = CommentStatus::Published;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** Hashed IP of the poster, for rate-limiting / moderation. Never exposed. */
    #[ORM\Column(name: 'ip_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $ipHash = null;

    /** @var Collection<int, DiscussionReaction> */
    #[ORM\OneToMany(mappedBy: 'comment', targetEntity: DiscussionReaction::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $reactions;

    public function __construct()
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->replies = new ArrayCollection();
        $this->reactions = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function isReply(): bool
    {
        return $this->parent !== null;
    }

    /**
     * @return Collection<int, self>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): self
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getAuthorEmail(): ?string
    {
        return $this->authorEmail;
    }

    public function setAuthorEmail(?string $authorEmail): self
    {
        $this->authorEmail = $authorEmail;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getStatus(): CommentStatus
    {
        return $this->status;
    }

    public function setStatus(CommentStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function setIpHash(?string $ipHash): self
    {
        $this->ipHash = $ipHash;

        return $this;
    }

    /**
     * @return Collection<int, DiscussionReaction>
     */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }
}
