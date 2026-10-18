<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * ETag optimistic-lock conflict: the file was changed elsewhere (expected, not a bridge failure).
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
