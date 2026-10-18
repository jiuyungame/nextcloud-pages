<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Changing the instance-level "site storage user" moves the source of truth from
 * `<data>/<old>/files/<nc_path>` to `<data>/<new>/files/<nc_path>`. The target is usually empty, and
 * an empty source may never be mirrored over a full working copy (that is what the bridge's delete
 * guard is for), so the move has to be explicit: this service answers "what would happen" (preflight)
 * and then performs the move in the only safe order — copy → verify → config → push → delete old.
 *
 * The old directory is deleted last, so every failure leaves either the old location untouched or the
 * config pointing at a complete new location. Never "old deleted, config still old".
 */
class SiteMigrationService
{
    /** Above this size the copy is worth warning about: several hundred MB of images take a while. */
    private const LARGE_SITE_BYTES = 200 * 1024 * 1024;

    /**
     * How many entries preflight reads before it stops counting. The migration itself always counts
     * the whole tree (its verification depends on it); the report in front of a save must not walk a
     * huge tree twice just to print a size, so it reports what it read and says so.
     */
    private const PREFLIGHT_MAX_ENTRIES = 5000;

    public function __construct(
        private FileService $files,
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private IUserManager $userManager,
        private IL10N $l10n,
    ) {
    }

    /**
     * What saving `nc_user` would mean, without changing anything.
     *
     * `decision` is what the settings form acts on:
     *   `save_directly`   the target already holds a site: repoint, leave the old location alone
     *   `offer_migration` the target is missing or empty: offer migrate / rebuild from git / cancel
     *   `first_config`    the current location does not exist yet: plain save, then initialise
     *
     * @param string $ncUser the user the source would move to
     * @return array<string,mixed>
     */
    public function preflight(string $ncUser): array
    {
        [$sourceUser, $sourcePath, $targetUser, $targetPath, $sameUser] = $this->plan($ncUser);

        if (!$sameUser && !$this->userManager->userExists($targetUser)) {
            throw new \InvalidArgumentException($this->l10n->t(
                'No such user: %s (site storage user)',
                [$targetUser],
            ));
        }

        $source = $this->files->existingFolder($sourceUser, $sourcePath);
        $sourceInfo = $this->sourceInfo($sourceUser, $sourcePath, $source);
        $target = $this->files->existingFolder($targetUser, $targetPath);
        $targetInfo = $this->targetInfo($target);
        $quota = $this->quota($targetUser, $sourceInfo['bytes']);

        if ($sourceInfo['state'] !== 'folder') {
            $decision = 'first_config';
        } elseif ($targetInfo['has_content']) {
            // the target wins; the old folder is not touched, so there is nothing to migrate
            $decision = 'save_directly';
        } else {
            $decision = 'offer_migration';
        }

        // `key` is the stable identity of a warning, `message` the sentence the form prints
        $warnings = [];
        $shared = $source !== null && $this->files->hasShares($source);
        if ($shared) {
            $warnings[] = $this->warning(
                'shares',
                $this->l10n->t('The folder (or one of its entries) is shared: shares hang off the file id and stop working after the move.'),
            );
        }
        if (!$sameUser && $sourceInfo['state'] === 'folder' && $sourceInfo['bytes'] >= self::LARGE_SITE_BYTES) {
            $warnings[] = $this->warning(
                'large_site',
                $this->l10n->t(
                    'This site holds %s; copying several hundred MB takes a while and keeps the form busy.',
                    [$this->files->bytesLabel($sourceInfo['bytes'])],
                ),
            );
        }
        if (!$sameUser && $decision !== 'first_config' && !$quota['ok']) {
            $warnings[] = $this->warning('quota', (string)$quota['message']);
        }
        if ($sourceInfo['state'] === 'folder' && !$sourceInfo['complete']) {
            $warnings[] = $this->warning(
                'size_capped',
                $this->l10n->t(
                    'The folder holds more than %s entries, so the size above is a lower bound.',
                    [(string)self::PREFLIGHT_MAX_ENTRIES],
                ),
            );
        }

        return [
            'ok' => true,
            'source_user' => $sourceUser,
            'target_user' => $targetUser,
            'nc_path' => $targetPath,
            'same_user' => $sameUser,
            'decision' => $decision,
            'source' => $sourceInfo,
            'target' => $targetInfo,
            'quota' => $quota,
            'shares' => ['any' => $shared],
            'warnings' => array_column($warnings, 'message'),
            'warning_items' => $warnings,
        ];
    }

    /**
     * Performs the move. Stops at the first failing step and reports every step it attempted, so the
     * form can show exactly where it stopped.
     *
     * @param string $ncUser the user the source moves to
     * @return array<string,mixed>
     */
    public function migrate(string $ncUser): array
    {
        [$sourceUser, $sourcePath, $targetUser, $targetPath, $sameUser] = $this->plan($ncUser);
        if ($sameUser) {
            throw new \InvalidArgumentException($this->l10n->t('The site already lives in that user\'s files'));
        }
        if (!$this->userManager->userExists($targetUser)) {
            throw new \InvalidArgumentException($this->l10n->t(
                'No such user: %s (site storage user)',
                [$targetUser],
            ));
        }
        $site = $this->registry->getSite();
        if ($site === null) {
            throw new \InvalidArgumentException($this->l10n->t('No site is configured yet'));
        }

        $source = $this->files->existingFolder($sourceUser, $sourcePath);
        if ($source === null) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The current source folder does not exist: %s',
                [$sourcePath],
            ));
        }
        // hashed while copying: the same list is re-read before the old folder is deleted, so a change
        // during the migration is noticed even when the file count and the byte total stay equal
        $sourceEntriesHash = hash('sha256', implode("\n", $this->files->totals($source)['paths']));

        // 0. the quota question first: it needs nothing but the size of the source, and asking it
        // before the target folder exists keeps a refusal free of side effects. A user whose quota
        // cannot even take an empty folder (a 1 MB quota, say) fails here rather than half-way
        // through a copy, and the message names the quota instead of a folder-creation error.
        $sourceInfo = $this->sourceInfo($sourceUser, $sourcePath, $source);
        $quota = $this->quota($targetUser, $sourceInfo['bytes']);
        if (!$quota['ok']) {
            return $this->stop([], 'copy', (string)$quota['message'], true);
        }

        // 1. copy into the target user's files (charged to that user's quota)
        $target = $this->files->existingFolder($targetUser, $targetPath);
        $freshlyCreated = $target === null;
        try {
            $target = $this->files->ensureSiteFolder($targetUser, $targetPath);
        } catch (\Throwable $e) {
            return $this->stop([], 'copy', $this->message($e), !$this->quota($targetUser, $sourceInfo['bytes'])['ok']);
        }
        if (!$freshlyCreated) {
            // only reachable when the target was left empty; anything inside it is in the way
            try {
                $this->files->deleteFolder($target);
            } catch (\Throwable $e) {
                return $this->stop([], 'copy', $this->message($e));
            }
            try {
                $target = $this->files->ensureSiteFolder($targetUser, $targetPath);
            } catch (\Throwable $e) {
                return $this->stop([], 'copy', $this->message($e));
            }
        }

        $steps = [];
        try {
            // Node::copy() → View::copy(): the filecache follows, so the new folder is visible in
            // Files immediately instead of after `occ files:scan`
            $this->files->copyFolder($source, $target->getPath());
            $steps[] = $this->step(
                'copy',
                true,
                $this->l10n->t('Copied %s to %s', [$sourcePath, $targetUser . $targetPath]),
            );
        } catch (\Throwable $e) {
            // nothing is configured yet, so the copy is dropped again and the old location stays
            $this->cleanup($target);
            return $this->stop($steps, 'copy', $this->message($e), $this->isQuotaError($e));
        }

        // 2. verify before anything points at the new location
        $sourceTotals = $this->files->totals($source);
        $targetTotals = $this->files->totals($target);
        if ($sourceTotals['paths'] !== $targetTotals['paths']) {
            $this->cleanup($target);
            return $this->stop($steps, 'verify', $this->l10n->t(
                'The source folder changed while it was being copied; nothing was changed. Try again.',
            ));
        }
        if (!$this->sameTree($sourceTotals, $targetTotals)) {
            $this->cleanup($target);
            return $this->stop($steps, 'verify', $this->l10n->t(
                'The copy does not match the source (%1$s vs %2$s files, %3$s vs %4$s); nothing was changed.',
                [
                    (string)$sourceTotals['files'],
                    (string)$targetTotals['files'],
                    $this->files->bytesLabel($sourceTotals['bytes']),
                    $this->files->bytesLabel($targetTotals['bytes']),
                ],
            ));
        }
        $steps[] = $this->step('verify', true, $this->l10n->t(
            'Checked %s files and %s',
            [(string)$targetTotals['files'], $this->files->bytesLabel($targetTotals['bytes'])],
        ));

        // 3. the config now points at the complete new location (the revision moves with it)
        try {
            $updated = $this->registry->setSite([...$site, 'nc_user' => $targetUser]);
            $steps[] = $this->step('config', true, $this->l10n->t(
                'Site storage user is now %s',
                [$targetUser],
            ));
        } catch (\Throwable $e) {
            return $this->stop($steps, 'config', $this->message($e));
        }

        // 4. push: only a bridge that holds the new location may trigger the old folder's deletion
        try {
            $this->bridge->pushConfig();
            $steps[] = $this->step('push', true, $this->l10n->t('The bridge now uses the new location'));
        } catch (\Throwable $e) {
            return $this->stop($steps, 'push', $this->message($e));
        }

        // 5. delete the old copy — last, and only the site directory itself
        $steps = [...$steps, ...$this->deleteOld($source, $sourceEntriesHash, $sourceUser, $sourcePath)];

        return [
            'ok' => true,
            'steps' => $steps,
            'site' => $updated,
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'has_unpushed' => $this->registry->hasUnpushedChanges(),
            'message' => $this->l10n->t('Migrated to %s', [$targetUser]),
        ];
    }

    /**
     * Removes the old site directory. The entry list is compared once more first: if the source grew
     * or shrank after the copy, deleting it would drop whatever was added in the meantime.
     *
     * @return list<array{step:string,ok:bool,message:string,quota?:bool}>
     */
    private function deleteOld(Folder $source, string $sourceEntriesHash, string $sourceUser, string $sourcePath): array
    {
        try {
            $hash = hash('sha256', implode("\n", $this->files->totals($source)['paths']));
        } catch (\Throwable $e) {
            return [$this->step('delete_old', false, $this->l10n->t(
                'The old folder was kept: it could not be checked again (%s).',
                [$this->message($e)],
            ))];
        }
        if ($hash !== $sourceEntriesHash) {
            return [$this->step('delete_old', false, $this->l10n->t(
                'The old folder was kept because it changed during the migration; both locations hold a full copy now.',
            ))];
        }
        try {
            $this->files->deleteFolder($source);
        } catch (\Throwable $e) {
            return [$this->step('delete_old', false, $this->l10n->t(
                'The old folder could not be removed (%s); it is unused now and can be deleted by hand.',
                [$this->message($e)],
            ))];
        }
        return [$this->step('delete_old', true, $this->l10n->t('Removed the old copy in %s', [$sourceUser . $sourcePath]))];
    }

    /**
     * Resolves the users and the folder path the migration would use. An empty path is refused here:
     * clearing nc_path is a different edit, not a move.
     *
     * @return array{0:string,1:string,2:string,3:string,4:bool}
     */
    private function plan(string $ncUser): array
    {
        $site = $this->registry->getSite();
        if ($site === null) {
            throw new \InvalidArgumentException($this->l10n->t('No site is configured yet'));
        }
        $targetUser = trim($ncUser);
        if ($targetUser === '') {
            throw new \InvalidArgumentException($this->l10n->t(
                'The site storage user is required (it maps to the <user> level of the data directory)',
            ));
        }
        // the bridge validates the same thing (a user id is one path segment): refusing it here keeps
        // a copy from being made for a configuration the bridge would then reject
        if (str_contains($targetUser, '/') || $targetUser === '.' || $targetUser === '..') {
            throw new \InvalidArgumentException($this->l10n->t('No such user: %s (site storage user)', [$targetUser]));
        }
        $sourceUser = trim((string)($site['nc_user'] ?? ''));
        $targetPath = $this->path((string)($site['nc_path'] ?? ''));
        return [$sourceUser, $targetPath, $targetUser, $targetPath, $sourceUser === $targetUser];
    }

    /**
     * The source as the report needs it. The walk stops at PREFLIGHT_MAX_ENTRIES (the migration's own
     * check is unbounded); `complete` says whether the numbers cover the whole tree.
     *
     * @return array{user:string,path:string,state:string,files:int,bytes:int,complete:bool}
     */
    private function sourceInfo(string $ncUser, string $ncPath, ?Folder $folder): array
    {
        $info = ['user' => $ncUser, 'path' => $ncPath, 'state' => 'missing', 'files' => 0, 'bytes' => 0, 'complete' => true];
        if ($folder !== null) {
            // like the target: "exists but empty" is a state of its own (an empty source is exactly
            // what must not be mirrored over a full working copy)
            $totals = $this->files->totals($folder, self::PREFLIGHT_MAX_ENTRIES);
            $info['state'] = $totals['entries'] === 0 ? 'empty' : 'folder';
            $info['files'] = $totals['files'];
            $info['bytes'] = $totals['bytes'];
            $info['complete'] = $totals['complete'];
        }
        return $info;
    }

    /** @return array{state:string,exists:bool,has_content:bool,files:int,bytes:int} */
    private function targetInfo(?Folder $folder): array
    {
        if ($folder === null) {
            return ['state' => 'missing', 'exists' => false, 'has_content' => false, 'files' => 0, 'bytes' => 0];
        }
        $totals = $this->files->totals($folder);
        return [
            'state' => $totals['entries'] === 0 ? 'empty' : 'existing',
            'exists' => true,
            'has_content' => $totals['entries'] > 0,
            'files' => $totals['files'],
            'bytes' => $totals['bytes'],
        ];
    }

    /**
     * Whether the target user's free space can take the copy.
     *
     * `\OCP\Files\FileInfo` reports space as one of three negative constants and they mean different
     * things — UNKNOWN (-2) and UNLIMITED (-3) both mean "the copy fits", NOT_COMPUTED (-1) means the
     * quota depends on files outside this folder and cannot be answered here. -1 is also this
     * method's own "the storage would not say" value, and both are reported as "cannot tell" rather
     * than as "fine": an unanswerable quota must never make a half-finished copy look acceptable.
     *
     * @return array{ok:bool,free:int,needed:int,unlimited:bool,message:string}
     */
    private function quota(string $ncUser, int $needed): array
    {
        try {
            $free = (int)$this->files->userFilesFolder($ncUser)->getFreeSpace();
        } catch (\Throwable) {
            $free = FileInfo::SPACE_NOT_COMPUTED;
        }
        if ($free === FileInfo::SPACE_UNKNOWN || $free === FileInfo::SPACE_UNLIMITED) {
            return ['ok' => true, 'free' => $free, 'needed' => $needed, 'unlimited' => true, 'message' => ''];
        }
        if ($free === FileInfo::SPACE_NOT_COMPUTED) {
            return [
                'ok' => false,
                'free' => $free,
                'needed' => $needed,
                'unlimited' => false,
                'message' => $this->l10n->t(
                    'The free space of %s could not be determined, so the copy was not started.',
                    [$ncUser],
                ),
            ];
        }
        if ($free < $needed) {
            return [
                'ok' => false,
                'free' => $free,
                'needed' => $needed,
                'unlimited' => false,
                'message' => $this->l10n->t(
                    'The target user\'s quota is not enough: %1$s is needed and only %2$s is free.',
                    [$this->files->bytesLabel($needed), $this->files->bytesLabel(max(0, $free))],
                ),
            ];
        }
        return ['ok' => true, 'free' => $free, 'needed' => $needed, 'unlimited' => false, 'message' => ''];
    }

    /**
     * Same count and same byte total: the two facts §14 requires the copy to be checked against.
     * Compared as ints because FileInfo::getSize() may answer a float.
     *
     * @param array{files:int,bytes:int} $a
     * @param array{files:int,bytes:int} $b
     */
    private function sameTree(array $a, array $b): bool
    {
        return (int)$a['files'] === (int)$b['files'] && (int)$a['bytes'] === (int)$b['bytes'];
    }

    /** Best-effort removal of a copy that never became the source; failures here change nothing. */
    private function cleanup(?Folder $folder): void
    {
        if ($folder === null) {
            return;
        }
        try {
            $this->files->deleteFolder($folder);
        } catch (\Throwable) {
            // deliberately ignored: an empty leftover folder is visible and harmless
        }
    }

    private function isQuotaError(\Throwable $e): bool
    {
        $text = strtolower($e->getMessage());
        return str_contains($text, 'quota') || str_contains($text, 'free space') || str_contains($text, 'not enough');
    }

    private function message(\Throwable $e): string
    {
        $text = trim($e->getMessage());
        return $text === '' ? $e::class : $text;
    }

    /** @return array{step:string,ok:bool,message:string,quota?:bool} */
    private function step(string $name, bool $ok, string $message, bool $quota = false): array
    {
        $step = ['step' => $name, 'ok' => $ok, 'message' => $message];
        if ($quota) {
            $step['quota'] = true;
        }
        return $step;
    }

    /** @return array{key:string,message:string} */
    private function warning(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message];
    }

    /**
     * The one failure shape: every attempted step, where it stopped and why.
     *
     * @param list<array{step:string,ok:bool,message:string,quota?:bool}> $steps
     * @return array<string,mixed>
     */
    private function stop(array $steps, string $step, string $message, bool $quota = false): array
    {
        $steps[] = $this->step($step, false, $message, $quota);
        return [
            'ok' => false,
            'steps' => $steps,
            'failed_step' => $step,
            'quota' => $quota,
            'message' => $quota
                ? $this->l10n->t('The target user\'s quota is not enough. The old location is unchanged.')
                : $message,
        ];
    }

    /** nc_path with exactly one leading slash: `/Sites/blog`, never `Sites/blog` or `//Sites`. */
    private function path(string $ncPath): string
    {
        $path = trim(str_replace('\\', '/', $ncPath));
        $path = trim($path, '/');
        return '/' . $path;
    }
}
