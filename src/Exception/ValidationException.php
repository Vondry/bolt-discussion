<?php

declare(strict_types=1);

namespace BoltDiscussion\Exception;

use RuntimeException;

/**
 * Thrown when a submission fails validation. The message is a translation key
 * (the English source string, domain "bolt_discussion"); optional parameters are
 * substituted when the API translates it for the 422 response.
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        string $message,
        private readonly array $parameters = []
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, string|int>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
