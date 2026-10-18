<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * A bridge call failed; carries the bridge error code and details for the UI.
 */
class BridgeException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        private string $bridgeCode = 'BRIDGE_ERROR',
        private array $details = [],
        private int $httpStatus = 500,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getBridgeCode(): string
    {
        return $this->bridgeCode;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Whether the same call can succeed later without changing anything: the one retryable verdict is
     * "the service did not answer at all".
     *
     * The classification is a predicate on the code rather than a subclass hierarchy, because the code
     * is already the wire contract (the bridge sends `{"error": code}` and this client re-raises it) and
     * the taxonomy has exactly one home - the mapping in {@see \OCA\Pages\Controller\BaseController::translate()}.
     * A subclass rebuilt from the same string would add a second source of truth, not a new fact.
     *
     * `UNREACHABLE` is retryable: the request never got an answer, so nothing was decided and the same
     * payload may be sent again. Everything else is a verdict - `NOT_CONFIGURED`,
     * `PROTOCOL_MISMATCH`, `BAD_URL`, `NO_CODE`, `BAD_CODE`, `PAIR_FAILED`, `LOCAL_ACCESS_BLOCKED`,
     * `BAD_RESPONSE`, `MIRROR_DELETE_GUARD` and every code the bridge itself reports - and repeating
     * the same call would fail the same way.
     */
    public function isRetryable(): bool
    {
        return $this->bridgeCode === 'UNREACHABLE';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'error' => $this->bridgeCode,
            'message' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
