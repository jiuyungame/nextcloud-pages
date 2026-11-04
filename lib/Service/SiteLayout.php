<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The site folder's one required subdirectory: `source/`.
 *
 * `nc_path` still means the **site folder** - the folder the administrator sees and the one the
 * bridge's `site.source` resolves to. The three subdirectories are derived by the bridge from that one
 * value (`<site>/source`, `<site>/online`, `<site>/pending`), so the app stores none of them. What it
 * does have to guarantee is the one input the bridge refuses to start without: `source/`.
 *
 * {@see self::ensureSource()} makes `<site>/source/` exist (and checks it is writable). It runs on
 * the points a site becomes usable - a configuration save or push, `/site/init`, and
 * `occ pages:push` - so the bridge never receives a site whose skeleton directory is missing. The
 * bridge creates `online/` and `pending/` on its own; the app never writes those.
 *
 * A fresh install has nothing to migrate: the folder is created here, and the first Apply source
 * builds all three trees from it.
 *
 * Everything goes through the Nextcloud Files API (`IRootFolder` behind {@see FileService}), never
 * through the data directory: a folder made behind the file cache would be invisible in the Files app
 * and the bridge would read a path the instance no longer agrees exists.
 */
final class SiteLayout
{
    /** The subdirectory that holds the skeleton, the one input the bridge requires. */
    public const SOURCE = SiteTrees::SOURCE;

    public function __construct(
        private SiteRegistry $registry,
        private FileService $files,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
    }

    /**
     * Make `<site>/source/` exist, and refuse a site whose skeleton directory cannot be written.
     *
     * The site folder itself is created on the way (a first configuration often names a folder that
     * does not exist yet). A no-op when no site is configured or when the site has no storage user yet:
     * the caller is a configuration path that has not stored the values the folder is derived from.
     *
     * @throws NotPermittedException when the directory exists but cannot be written to
     * @throws NotFoundException when the configured site storage user does not exist
     */
    public function ensureSource(): void
    {
        $site = $this->registry->getSite();
        if ($site === null) {
            return;
        }
        $uid = trim((string)($site['nc_user'] ?? ''));
        $ncPath = trim((string)($site['nc_path'] ?? ''));
        if ($uid === '' || $ncPath === '') {
            return;
        }
        $folder = $this->files->ensureSubfolder($uid, $ncPath, self::SOURCE);
        if (!$folder->isUpdateable()) {
            throw new NotPermittedException($this->l10n->t(
                'The site source directory cannot be written: %s',
                [$ncPath . '/' . self::SOURCE],
            ));
        }
        $this->logger->debug('pages: the site source directory is in place', [
            'user' => $uid,
            'path' => rtrim($ncPath, '/') . '/' . self::SOURCE,
        ]);
    }
}
