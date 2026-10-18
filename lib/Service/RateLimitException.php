<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * The caller is writing too fast (comments). A separate type because it is the only failure that says
 * when the same request may be made again, and the caller deserves to be told the wait.
 */
class RateLimitException extends \RuntimeException
{
    /**
     * @param int $retryAfter seconds until the same request can succeed
     */
    public function __construct(string $message, private int $retryAfter)
    {
        parent::__construct($message, 429);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
