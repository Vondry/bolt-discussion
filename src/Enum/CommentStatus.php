<?php

declare(strict_types=1);

namespace BoltDiscussion\Enum;

enum CommentStatus: string
{
    case Published = 'published';
    case Pending = 'pending';
    case Spam = 'spam';
    case Deleted = 'deleted';

    /**
     * Statuses that are visible to the public on the frontend.
     *
     * @return list<self>
     */
    public static function publicStatuses(): array
    {
        return [self::Published];
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
