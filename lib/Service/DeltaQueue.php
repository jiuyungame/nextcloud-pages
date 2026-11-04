<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * The durable queue of author deltas the bridge did not take.
 *
 * The steady state sends one delta per file event and treats a failure as best-effort: the save is
 * already on disk, and the one-shot boot overwrite repairs whatever the bridge missed - but only on
 * the next boot. A save that lands while the bridge restarts (or while its lock window is exceeded)
 * therefore left the preview without that file until a restart or a manual Apply source.
 *
 * This queue closes that window. A failed `add`/`update`/`remove` is appended here, and the next
 * boot (or `occ pages:delta-flush`) replays it. Only the three content ops are queued on purpose:
 * `candidate`, `publish`, `discard` and `take-down` are decisions whose state the visibility
 * manifest already holds, so the review workflow can recover them without a replay, and a
 * `mirror`/`overwrite` is a whole-tree operation that the boot overwrite already repeats.
 *
 * The store is one appconfig value (`pages` / `delta_queue`), a JSON array of
 * `{op, uid, target, at, attempts}`. It is deliberately small and bounded: at most
 * {@see self::MAX_ENTRIES} entries, oldest dropped first, and a replay is capped both by entry count
 * and by wall-clock time so a dead bridge cannot hold a web request open entry after entry. Entries
 * are deduplicated by `target`: the last event for a file is the one that matters, and a retry must
 * not replay a stale `add` after a `remove`.
 *
 * **Every read-modify-write cycle takes an exclusive lock** ({@see self::LOCK_KEY}), because the
 * value is a whole-array replace: two concurrent `enqueue()` calls that both read the same queue
 * would each write back its own entry and silently drop the other, and a `settle()` racing an
 * `enqueue()` would erase the delta it just queued. `enqueue()`, `settle()` and the failure counter
 * all go through {@see self::mutate()}, which holds that lock while it reads and writes. The replay
 * itself is not under the lock (it is a network call); it settles each target through the same
 * locked write.
 *
 * **A poisoned entry is dropped instead of replayed forever.** A non-retryable refusal (the bridge
 * answered and said no) is counted on the entry; after {@see self::MAX_ATTEMPTS} such failures the
 * entry is discarded and the drop is logged. A retryable failure (the bridge did not answer) does
 * not count: that entry is kept for the next round, where a reachable bridge can take it.
 */
final class DeltaQueue
{
    /** The appconfig key (app `pages`): the JSON array of queued deltas. */
    public const KEY = 'delta_queue';

    /**
     * The lock every read-modify-write of {@see self::KEY} holds. The value is one JSON array, so a
     * writer that reads, changes and writes without it overwrites whatever was written in between.
     */
    public const LOCK_KEY = 'pages/delta_queue';

    /** How often a writer retries while another one holds {@see self::LOCK_KEY}. */
    private const LOCK_ATTEMPTS = 3;

    /** How long one retry waits for the lock, in microseconds. */
    private const LOCK_WAIT_US = 10000;

    /**
     * How many non-retryable refusals one entry survives before it is dropped as poisoned.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * The only ops worth replaying. A folder rename sends `mirror` and a review action sends
     * `candidate`/`publish`/`discard`/`take-down`: those are whole-tree or manifest-backed, so they
     * are not queued here.
     *
     * @var list<string>
     */
    public const QUEUEABLE_OPS = ['add', 'update', 'remove'];

    /** The queue never grows past this: a bridge that stays down must not turn appconfig into a log. */
    public const MAX_ENTRIES = 500;

    /** Entries one automatic replay round (boot) tries before it stops. */
    public const REPLAY_LIMIT = 50;

    /** Seconds one replayed delta may take; the boot must not inherit the 5-minute lock-bound budget. */
    public const REPLAY_TIMEOUT = 30;

    /** Seconds one automatic replay round may take in total, however many entries are left. */
    public const REPLAY_BUDGET = 60;

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
        private ILockingProvider $locking,
    ) {
    }

    /** Whether an op is one of the content deltas this queue carries. */
    public static function isQueueable(string $op): bool
    {
        return in_array($op, self::QUEUEABLE_OPS, true);
    }

    /**
     * The queue as stored, oldest first. A value that is not the documented shape is treated as an
     * empty queue (and logged): a corrupt appconfig entry must not break every save.
     *
     * @return list<array{op:string,uid:string,target:string,at:int,attempts:int}>
     */
    public function all(): array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('pages: the author delta queue is not valid JSON; it is treated as empty', [
                'bytes' => strlen($raw),
            ]);

            return [];
        }
        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $op = (string)($entry['op'] ?? '');
            $target = self::key((string)($entry['target'] ?? ''));
            if (!self::isQueueable($op) || $target === '') {
                continue;
            }
            $entries[] = [
                'op' => $op,
                'uid' => (string)($entry['uid'] ?? ''),
                'target' => $target,
                'at' => is_numeric($entry['at'] ?? null) ? (int)$entry['at'] : 0,
                'attempts' => is_numeric($entry['attempts'] ?? null) ? max(0, (int)$entry['attempts']) : 0,
            ];
        }

        return $entries;
    }

    /** How many deltas are waiting. */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Appends one failed delta, replacing any older entry for the same target.
     *
     * `false` means "not queued": an op that is not one of the three content ops, an entry without a
     * target (the `mirror` of a folder rename), the queue could not be encoded, or another writer held
     * the queue for longer than the retry window. The caller logs that it was dropped.
     *
     * @param int|null $at the event's timestamp; defaults to now
     */
    public function enqueue(string $op, string $uid, string $target, ?int $at = null): bool
    {
        $target = self::key($target);
        if (!self::isQueueable($op) || $target === '') {
            return false;
        }
        $entry = [
            'op' => $op,
            'uid' => trim($uid),
            'target' => $target,
            'at' => $at ?? time(),
            'attempts' => 0,
        ];

        return $this->mutate(function (array $queue) use ($entry): array {
            $queue = array_values(array_filter(
                $queue,
                static fn (array $queued): bool => $queued['target'] !== $entry['target'],
            ));
            $queue[] = $entry;
            if (count($queue) > self::MAX_ENTRIES) {
                $dropped = count($queue) - self::MAX_ENTRIES;
                // the oldest entries go first: the newest state of a file is the one worth replaying
                $queue = array_slice($queue, $dropped);
                $this->logger->warning('pages: the author delta queue is full; the oldest entries were dropped', [
                    'dropped' => $dropped,
                    'limit' => self::MAX_ENTRIES,
                ]);
            }

            return $queue;
        });
    }

    /**
     * Forgets every queued entry of one target: a delta that just reached the bridge settles an
     * older failure for the same file. Returns whether anything was removed; an empty queue is not
     * rewritten (the steady state must not write appconfig on every successful save).
     *
     * `$delivered` names the exact attempt that succeeded (`op` and `at`), which is what a replay
     * passes: when a newer failure for the same target was queued while the send was in flight, that
     * entry is a different attempt and must survive. A caller that just delivered the file's newest
     * state omits it and settles every entry of the target.
     *
     * @param array{op:string,at:int}|null $delivered
     */
    public function settle(string $target, ?array $delivered = null): bool
    {
        $target = self::key($target);
        if ($target === '') {
            return false;
        }
        $removed = false;
        $written = $this->mutate(function (array $queue) use ($target, $delivered, &$removed): ?array {
            $kept = array_values(array_filter(
                $queue,
                static function (array $entry) use ($target, $delivered): bool {
                    if ($entry['target'] !== $target) {
                        return true;
                    }
                    if ($delivered !== null
                        && ($entry['op'] !== ($delivered['op'] ?? '') || $entry['at'] !== ($delivered['at'] ?? 0))) {
                        // a newer attempt for the same file: this settlement is about another one
                        return true;
                    }

                    return false;
                },
            ));
            if (count($kept) === count($queue)) {
                return null;
            }
            $removed = true;

            return $kept;
        });

        return $written && $removed;
    }

    /** Drops the whole queue (pairing/reset: the new site's state is not the old queue's). */
    public function clear(): void
    {
        if ((string)$this->config->getAppValue('pages', self::KEY, '') !== '') {
            $this->config->deleteAppValue('pages', self::KEY);
        }
    }

    /**
     * Replays queued deltas in order until the queue is empty, the count limit or the time budget is
     * reached, or the bridge turns out to be unreachable.
     *
     * `$send` receives the body (`{op, uid, target}`) and the per-entry timeout in seconds, and must
     * throw when the delta did not reach the bridge. A success removes that attempt from the queue; a
     * non-retryable failure counts one attempt and drops the entry at {@see self::MAX_ATTEMPTS}; a
     * retryable failure keeps it unchanged. An unreachable bridge ends the round at once: waiting out
     * the per-entry timeout for every remaining entry is exactly the boot delay this bound prevents.
     *
     * @param callable(array<string,mixed>,int):void $send
     * @param int $limit maximum entries to try in this round
     * @param int $timeoutSeconds per-entry timeout handed to `$send`
     * @param int $budgetSeconds wall-clock budget for the whole round
     * @return array{sent:int,failed:int,remaining:int,stopped:string} `stopped` is done|limit|budget|unreachable
     */
    public function replay(
        callable $send,
        int $limit = self::REPLAY_LIMIT,
        int $timeoutSeconds = self::REPLAY_TIMEOUT,
        int $budgetSeconds = self::REPLAY_BUDGET,
    ): array {
        $limit = max(1, $limit);
        $timeoutSeconds = max(1, $timeoutSeconds);
        $budgetSeconds = max(1, $budgetSeconds);
        $started = microtime(true);
        $sent = 0;
        $failed = 0;
        $attempted = 0;
        $stopped = 'done';

        foreach ($this->all() as $entry) {
            if ($attempted >= $limit) {
                $stopped = 'limit';
                break;
            }
            if (microtime(true) - $started >= $budgetSeconds) {
                $stopped = 'budget';
                break;
            }
            $attempted++;
            try {
                $send(['op' => $entry['op'], 'uid' => $entry['uid'], 'target' => $entry['target']], $timeoutSeconds);
                // settle exactly the attempt that went through: a newer failure queued for the same
                // file while the send was in flight is a different event and stays
                $this->settle($entry['target'], ['op' => $entry['op'], 'at' => $entry['at']]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->warning('pages: a queued author delta still could not be delivered', [
                    'op' => $entry['op'],
                    'uid' => $entry['uid'],
                    'target' => $entry['target'],
                    'attempts' => $entry['attempts'],
                    'exception' => $e,
                ]);
                if ($e instanceof BridgeException && $e->isRetryable()) {
                    // no answer at all: every further entry would wait out its own timeout. The entry
                    // is kept unchanged - a transient outage must not count towards the poison limit.
                    $stopped = 'unreachable';
                    break;
                }
                // the bridge answered and refused it: count the refusal and drop the entry once it has
                // clearly proven undeliverable instead of replaying it on every boot for ever
                $this->recordFailure($entry);
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'remaining' => $this->count(),
            'stopped' => $stopped,
        ];
    }

    /**
     * Counts one non-retryable refusal on a queued entry, dropping it once it is poisoned.
     *
     * The entry is matched by its full attempt identity (`op`, `target`, `at`): a newer event for the
     * same file that arrived while the send was in flight has not been attempted and must not inherit
     * the failure. A missing entry is not an error - a concurrent settle may have removed it.
     *
     * @param array{op:string,uid:string,target:string,at:int,attempts:int} $attempted
     */
    private function recordFailure(array $attempted): void
    {
        $this->mutate(function (array $queue) use ($attempted): ?array {
            $kept = [];
            $dropped = false;
            foreach ($queue as $entry) {
                $sameAttempt = $entry['op'] === $attempted['op']
                    && $entry['target'] === $attempted['target']
                    && $entry['at'] === $attempted['at'];
                if (!$sameAttempt) {
                    $kept[] = $entry;
                    continue;
                }
                $entry['attempts']++;
                if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
                    $dropped = true;
                    $this->logger->warning(
                        'pages: a queued author delta was refused %d times; it was dropped instead of being replayed for ever',
                        [
                            'target' => $entry['target'],
                            'op' => $entry['op'],
                            'attempts' => $entry['attempts'],
                            'limit' => self::MAX_ATTEMPTS,
                        ],
                    );
                    continue;
                }
                $kept[] = $entry;
            }
            if ($dropped) {
                return array_values($kept);
            }

            return $kept === $queue ? null : array_values($kept);
        });
    }

    /**
     * One site-relative target in the shape every delta uses: forward slashes, no leading slash.
     */
    private static function key(string $target): string
    {
        return ltrim(trim(str_replace('\\', '/', $target)), '/');
    }

    /**
     * Runs one read-modify-write of the queue under its exclusive lock.
     *
     * `$change` receives the current queue (oldest first) and answers the new one, or `null` while
     * there is nothing to write - an empty rewrite of appconfig on every successful save is exactly
     * what this queue must not do. The result says whether the change was applied; `false` means the
     * lock stayed busy through every retry, or the new queue could not be encoded.
     *
     * A lock that stays busy is logged rather than thrown: the callers are best-effort paths (a save
     * must not fail because the queue was being settled), and a dropped delta is reported by the
     * caller as `queued: false`.
     *
     * @param callable(list<array{op:string,uid:string,target:string,at:int,attempts:int}>):?list<array{op:string,uid:string,target:string,at:int,attempts:int}> $change
     */
    private function mutate(callable $change): bool
    {
        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
            try {
                $this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
            } catch (LockedException) {
                if ($attempt < self::LOCK_ATTEMPTS) {
                    usleep(self::LOCK_WAIT_US);
                    continue;
                }
                $this->logger->warning('pages: the author delta queue is being written by another request; this change was not applied', [
                    'lock' => self::LOCK_KEY,
                ]);

                return false;
            }
            try {
                $queue = $change($this->all());
                if ($queue === null) {
                    return true;
                }

                return $this->write($queue);
            } finally {
                $this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
            }
        }

        return false;
    }

    /**
     * @param list<array{op:string,uid:string,target:string,at:int,attempts:int}> $queue
     */
    private function write(array $queue): bool
    {
        if ($queue === []) {
            // no empty array in appconfig: the key exists only while something waits
            $this->config->deleteAppValue('pages', self::KEY);

            return true;
        }
        $json = json_encode(array_values($queue), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // never write a placeholder: replacing the value with `[]` would silently drop whatever
            // is still waiting. The old value stays as it was, and the caller logs the failure.
            $this->logger->warning('pages: the author delta queue could not be encoded; the stored queue was left untouched', [
                'entries' => count($queue),
                'error' => json_last_error_msg(),
            ]);

            return false;
        }
        $this->config->setAppValue('pages', self::KEY, $json);

        return true;
    }
}
