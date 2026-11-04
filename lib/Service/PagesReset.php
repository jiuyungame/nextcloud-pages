<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * "Reset Pages (back to a fresh install)": the one operation that clears both sides.
 *
 * Nothing here touches a user's files. The site folder and every author's `Pages/**` stay exactly as
 * they are; what the reset removes is the app's *bookkeeping* (the visibility manifest, the article
 * cache, the site configuration, the grants and the pairing) and the bridge's *derived data* (its work
 * and preview trees, rebuilt from the Nextcloud folder by the mirror and the build). Only the app side
 * is backed up, into this app's own appdata (`reset-backups/<id>/`); the bridge trees are simply
 * deleted, because they are copies that can be regenerated at any time.
 *
 * The order is deliberate: backup, bridge, app. Only when the backup exists is anything changed, and
 * the app side is cleared even when the bridge cannot be reached - otherwise an unreachable bridge
 * would leave an app that looks configured but has no content behind it. That case leaves the
 * `bridge_reset_pending` record behind, which the doctor reports and the next reset retries (the
 * bridge side is idempotent, so a second call is safe).
 */
final class PagesReset
{
    /** Where the app-side backup of a reset lives inside the app's appdata folder. */
    public const BACKUP_DIR = 'reset-backups';

    public function __construct(
        private IAppData $appData,
        private ReviewStore $store,
        private ArticleMapper $articles,
        private SiteRegistry $registry,
        private BridgeClient $bridge,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * Run the reset and report what happened, step by step.
     *
     * @return array<string,mixed>
     * @throws \Throwable when the backup cannot be written: nothing is changed in that case
     */
    public function run(): array
    {
        // the reset also drops the one-shot overwrite flag: the next boot has to rebuild the preview
        // tree's author area from the folders that are still there
        \OCP\Server::get(AuthorDeltaService::class)->forgetOverwrite();
        // and the queued deltas of the site being reset: replaying them into a fresh pairing would
        // write files the new configuration never saw
        \OCP\Server::get(DeltaQueue::class)->clear();
        // the credentials the bridge call needs, captured before the app state is cleared
        $url = $this->registry->getBridgeUrl();
        $secret = $this->registry->getSharedSecret();
        $pending = $this->registry->getResetPending();
        if ($secret === '' && $pending !== null) {
            // a previous reset already cleared the app keys: its pending record carries the retry
            $url = $pending['url'] !== '' ? $pending['url'] : $url;
            $secret = $pending['secret'];
        }

        $manifest = $this->store->read();
        $served = $this->store->counts();
        $before = [
            'manifest_entries' => count($manifest['entries']),
            'manifest_published' => $served['online'],
            'articles' => $this->articles->countAll(),
        ];
        $backup = $this->backup($url, $before);

        $bridge = $this->resetBridge($url, $secret);

        // the app side runs in both outcomes: the reset must not leave a configured app behind.
        // clear() empties the manifest *and* the article cache - it is the one operation that leaves
        // no cached row behind - so what it removed is exactly what `$before` counted above.
        $this->store->clear();
        $this->registry->resetConfiguration();
        if ($bridge['ok'] === true) {
            $this->registry->clearResetPending();
        } else {
            // the normal keys are gone, so the retry needs the credentials of this very call
            $this->registry->setResetPending($url, $secret);
        }

        $removed = [
            // two different things, reported separately: manifest entries and served files are not
            // the same unit, and adding them counted a file twice when both records named it
            'entries' => $before['manifest_entries'],
            'online' => $before['manifest_published'],
            'articles' => $before['articles'],
        ];
        $this->logger->info('pages: full reset finished', [
            'backup' => $backup['path'],
            'bridge_ok' => $bridge['ok'],
            'article_rows' => $before['articles'],
            'before' => $before,
            'removed' => $removed,
        ]);

        return [
            'ok' => true,
            'backup' => $backup,
            'bridge' => $bridge,
            'removed' => $removed,
            'pending' => $bridge['ok'] !== true,
            // the one setting the reset keeps, so the next pairing knows where the bridge is
            'bridge_url' => $this->registry->getBridgeUrl(),
        ];
    }

    /**
     * Call the bridge's half of the reset. A failure is a state, not an exception: the app side still
     * has to run, and the answer says whether the cleanup is pending.
     *
     * @return array{ok:bool,url:string,already_clean?:bool,removed?:list<string>,message?:string,retryable?:bool,error?:string}
     */
    private function resetBridge(string $url, string $secret): array
    {
        if (trim($url) === '' || trim($secret) === '') {
            // nothing to authenticate with: either the bridge is already unpaired (the reset ran
            // before) or this install never paired - both are "no cleanup left to do"
            return ['ok' => true, 'url' => $url, 'already_clean' => true];
        }
        try {
            $result = $this->bridge->resetSite($url, $secret);

            return [
                'ok' => true,
                'url' => $url,
                'already_clean' => ($result['already_clean'] ?? false) === true,
                // the bridge deletes its work and preview trees and reports which ones it removed
                'removed' => is_array($result['removed'] ?? null) ? $result['removed'] : [],
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'url' => $url,
                'error' => $e instanceof BridgeException ? $e->getBridgeCode() : 'ERROR',
                'message' => $e->getMessage(),
                'retryable' => $e instanceof BridgeException && $e->isRetryable(),
            ];
        }
    }

    /**
     * Copy what the reset is about to drop into the app's own appdata, before it is dropped.
     *
     * The backup is the undo path: the manifest in the bytes the app stores it, the article cache as
     * JSON, and the configuration (never the secrets - they are fingerprints everywhere else too).
     *
     * @param array<string,int> $counts what the app held before the reset
     * @return array{id:string,path:string,at:int,counts:array<string,int>}
     */
    private function backup(string $bridgeUrl, array $counts): array
    {
        $at = time();
        $id = 'reset-' . date('Ymd-His', $at) . '-' . substr(sha1((string)$at . $bridgeUrl), 0, 6);
        $folder = $this->backupRoot()->newFolder($id);

        // the manifest exactly as stored: restoring it is a file copy, not a re-serialisation
        $rawManifest = $this->store->rawJson();
        $folder->newFile('review.json', $rawManifest === '' ? "{}\n" : $rawManifest);

        $articles = [];
        foreach ($this->articles->findAll() as $row) {
            $articles[] = $this->articleRow($row);
        }
        $folder->newFile('articles.json', $this->json($articles));

        $site = $this->registry->getSite();
        $folder->newFile('config.json', $this->json([
            'bridge_url' => $bridgeUrl,
            'site' => $site,
            'author_root' => $this->registry->getAuthorRoot(),
            'author_groups' => $this->registry->getAuthorGroups(),
            'review_groups' => $this->registry->getReviewGroups(),
            'revision' => $this->registry->getRevision(),
            'pushed_revision' => $this->registry->getPushedRevision(),
            'counts' => $counts,
        ]));

        return [
            'id' => $id,
            'path' => 'appdata/pages/' . self::BACKUP_DIR . '/' . $id,
            'at' => $at,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function articleRow(Article $row): array
    {
        return [
            'id' => $row->getId(),
            'path' => $row->getPath(),
            'owner_uid' => $row->getOwnerUid(),
            'title' => $row->getTitle(),
            'tags' => $row->getTags(),
            'state' => $row->getState(),
            'review_note' => $row->getReviewNote(),
            'created_at' => $row->getCreatedAt(),
            'updated_at' => $row->getUpdatedAt(),
            'submitted_at' => $row->getSubmittedAt(),
            'published_at' => $row->getPublishedAt(),
            'published_revision' => $row->getPublishedRevision(),
        ];
    }

    /** @param array<int|string,mixed> $data */
    private function json(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new \RuntimeException($this->l10n->t('The reset backup could not be written (%s).', [json_last_error_msg()]));
        }

        return $encoded . "\n";
    }

    private function backupRoot(): ISimpleFolder
    {
        try {
            return $this->appData->getFolder(self::BACKUP_DIR);
        } catch (NotFoundException) {
            return $this->appData->newFolder(self::BACKUP_DIR);
        }
    }
}
