<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * A conflict the caller can resolve: an ETag optimistic-lock mismatch, an existing file or folder
 * where the request asked to create, or a review state the requested transition cannot start from.
 * Expected, and not a bridge failure: the same request may succeed after a reload or a different
 * decision.
 */
class ConflictException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message, private array $details = [])
    {
        parent::__construct($message, 409);
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['error' => 'CONFLICT', 'message' => $this->getMessage(), 'details' => $this->details];
    }
}
