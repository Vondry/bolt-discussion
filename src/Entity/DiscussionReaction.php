<?php

declare(strict_types=1);

namespace Bolt\Discussion\Entity;

use Bolt\Discussion\Repository\DiscussionReactionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscussionReactionRepository::class)]
#[ORM\Table(name: 'bolt_discussion_reaction')]
#[ORM\UniqueConstraint(name: 'bd_reaction_unique', columns: ['comment_id', 'emoji', 'visitor_token'])]
class DiscussionReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DiscussionComment::class, inversedBy: 'reactions')]
    #[ORM\JoinColumn(name: 'comment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private DiscussionComment $comment;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $emoji;

    /** Anonymous visitor cookie token, or "user:<id>" for logged-in users. */
    #[ORM\Column(name: 'visitor_token', type: Types::STRING, length: 64)]
    private string $visitorToken;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getComment(): DiscussionComment
    {
        return $this->comment;
    }

    public function setComment(DiscussionComment $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function setEmoji(string $emoji): self
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function getVisitorToken(): string
    {
        return $this->visitorToken;
    }

    public function setVisitorToken(string $visitorToken): self
    {
        $this->visitorToken = $visitorToken;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
