<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

/**
 * A replacement of the site folder that could not be completed.
 *
 * The replacement is the one writer of content that comes from outside Nextcloud (Import and Pull):
 * it backs the folder up, empties it and writes the new tree whole. This exception carries what the
 * caller needs to answer the client without guessing:
 *
 *  - `backup` is the backup that was taken *before* the first write, so the answer can still name
 *    `appdata/pages/source-backups/…` even though the request failed;
 *  - `rollbackOk` says whether the folder was put back to that backup (`true`), whether the undo
 *    itself failed (`false`, the folder is in an unknown state - the backup is the only way back),
 *    or whether no undo was attempted (`null`).
 *
 * The message is the underlying failure's own text (a lock's path, a storage error), which is what
 * the client needs to decide whether trying again can help.
 */
class SourceReplacementException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $backup the value backup() returned for this attempt
     * @param ?bool               $rollbackOk true: restored, false: restore failed, null: not attempted
     */
    public function __construct(
        string $message,
        private array $backup = [],
        private ?bool $rollbackOk = null,
        ?\Throwable $cause = null,
    ) {
        parent::__construct($message, 0, $cause);
    }

    /** @return array<string,mixed> the backup taken before the failed replacement */
    public function getBackup(): array
    {
        return $this->backup;
    }

    /** null when no rollback was attempted, otherwise whether the folder was restored. */
    public function isRolledBack(): ?bool
    {
        return $this->rollbackOk;
    }
}
