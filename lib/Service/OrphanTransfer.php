<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\Files\File;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The handover of a published article whose author deleted it (转交孤儿所有者).
 *
 * The situation is always the same: the site serves a version of the article (`online/<target>`), the
 * author's file is gone, and nobody can maintain the page any more. Deleting it would throw away
 * content the site still shows; leaving it alone keeps a page nobody can edit. This service does the
 * third thing: it reads the published bytes back out of the bridge's `online/` directory and writes
 * them into the **site source** as `source/<author_root>/<orphan_owner>/<rel>`, where they are the
 * site's own files.
 *
 * Why `<orphan_owner>` and never the author's uid: the bridge excludes every *real* user's
 * `<author_root>/<uid>/` prefix from the preview mirror, so a transferred copy under a real uid would
 * never be mirrored. The directory is therefore an administrator setting that the app refuses to point
 * at an existing account ({@see SiteRegistry::setOrphanOwner()}) - and this service checks it again at
 * the moment of the move, because an account can be created after the setting was saved.
 *
 * The steps, in this order, and the order is the contract:
 *
 *  1. the article has to be on the site (`online/`), because the only copy left is that one;
 *  2. the orphan owner has to be usable (not an account) right now;
 *  3. the published bytes are read back from the bridge (404/413 are the two honest failures);
 *  4. they are written into `<site>/source/<author_root>/<orphan>/<rel>`, so the site owns them;
 *  5. the bridge takes the old target down (`online/<target>` and the host copies) - without this the
 *     old address would keep serving the page forever;
 *  6. the manifest entry is forgotten, so the article is not reviewable any more;
 *  7. the bridge re-assembles the three trees (the `mirror` = Apply source op), so the new file is in
 *     `work`/`preview`/`onlinepreview` immediately instead of waiting for the next event, and the
 *     preview is pinged to rebuild (best effort: the handover already happened);
 *  8. the caller gets `{orphan, target, site_path, bytes}` back to report the move.
 *
 * **Retries are safe.** A run that wrote the new file and then failed at the take-down leaves the
 * manifest entry in place; running it again finds the same bytes already at the destination and
 * continues instead of failing on "file exists". A run that failed *before* the write changed nothing
 * at all.
 *
 * What this service deliberately does not do: build the site itself. Step 7 rebuilds the trees without
 * a build (`build: false`) and asks the bridge for the usual debounced build; the new file goes live
 * with the next publish like any other file of the site's own.
 */
final class OrphanTransfer
{
    public function __construct(
        private SiteRegistry $registry,
        private AuthorAreaService $areas,
        private ReviewStore $review,
        private FileService $files,
        private BridgeClient $bridge,
        private AuthorSourcesService $sources,
        private IUserManager $userManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Hand one published article over to the orphan owner's directory.
     *
     * Everything happens before the caller writes any bookkeeping, and a failure at any step is
     * reported as an `\OCA\Pages\Service\ConflictException` the reviewer can act on: the manifest entry
     * is untouched, so the request keeps waiting and may be retried or approved as a take-down.
     *
     * @param string $target the article as a site target (`/<author_root>/<uid>/<rel>`)
     * @throws ConflictException when the article is not on the site, the orphan owner is unusable, the
     *         published bytes cannot be read back (missing / too large), or the destination already
     *         holds different content
     * @throws BridgeException when the bridge cannot take the old target down (the entry stays, a
     *         retry is safe)
     * @return array{orphan:string,target:string,site_path:string,bytes:int,created:bool,notified:bool,rebuilt:bool,message:string}
     */
    public function transfer(string $target): array
    {
        // 1. the article that is being handed over: it has to be on the site, because the only copy of
        //    the content left is the published one in online/
        $path = self::normalise($target);
        if (!$this->review->isLive($path)) {
            throw new ConflictException(
                $this->l10n->t('The site does not have this article, so there is nothing to take down.'),
                ['path' => $path],
            );
        }
        $uid = $this->areas->uidOfTarget($path);
        $rel = $uid === null ? '' : $this->areas->areaRelative($uid, $path);
        if ($uid === null || $rel === '') {
            throw new ConflictException($this->l10n->t('Not an article path: %s', [$path]), ['path' => $path]);
        }

        // 2. the orphan owner: a directory name, and not a real account (again, right now - an account
        //    created after the setting was saved must not swallow the transferred file)
        $orphan = $this->registry->getOrphanOwner();
        if ($this->userManager->userExists($orphan)) {
            throw new ConflictException(
                $this->l10n->t('The orphan owner must not be an existing user: its files would be excluded from the site source.'),
                ['orphan' => $orphan],
            );
        }

        $site = $this->registry->getSite();
        $ncUser = trim((string)($site['nc_user'] ?? ''));
        $ncPath = trim((string)($site['nc_path'] ?? ''));
        if ($site === null || $ncUser === '' || $ncPath === '') {
            throw new ConflictException($this->l10n->t('No site is configured yet'), ['path' => $path]);
        }

        // 3. the published bytes, read back out of online/ (the author's file is gone, so this is the
        //    only copy left). The two transfer failures - the site does not hold the target, or it is
        //    above the bridge's read-back cap - are the caller's conflicts to report, not bridge
        //    errors: nothing about the request is malformed, the content simply cannot be handed over.
        try {
            $content = $this->bridge->publishedContent($path)['content'];
        } catch (BridgeException $e) {
            if (!in_array($e->getBridgeCode(), ['NOT_FOUND', 'TOO_LARGE'], true)) {
                throw $e;
            }
            throw new ConflictException($e->getMessage(), [
                'transfer_failed' => true,
                'reason' => $e->getBridgeCode(),
                'target' => $path,
            ]);
        }

        // 4. the site source now owns the file: <site>/source/<author_root>/<orphan>/<rel>
        $siteRel = $this->registry->getAuthorRoot() . '/' . $orphan . '/' . $rel;
        $sourcePath = rtrim($ncPath, '/') . '/' . SiteTrees::SOURCE;
        $created = true;
        try {
            $this->files->write($ncUser, $sourcePath, $siteRel, $content, null, true);
        } catch (ConflictException $e) {
            // a retry after a run that stopped between the write and the take-down: the same bytes are
            // already there, which is exactly what this step wanted to achieve
            if (!$this->sameContent($ncUser, $sourcePath, $siteRel, $content)) {
                throw $e;
            }
            $created = false;
        }

        // 5. the old address leaves the site: online/<target> and the two host copies, in one operation.
        //    Failing here leaves the entry in place, so the reviewer retries and step 4's comparison
        //    above makes that retry a no-op.
        $this->bridge->reviewOp('take-down', $uid, $path);
        $this->review->refreshTrees();

        // 6. the article is the site's own now: nothing is left to request or decide about it
        $this->review->forgetArticle([$path]);

        // 7. rebuild the three derived trees so the new source file is in work/preview/onlinepreview
        //    right away (the trees are pure functions of source/ + online/ + pending/), and ping the
        //    bridge so the preview build catches up. Both are best effort: the handover is complete.
        $rebuilt = true;
        $notified = true;
        try {
            $this->bridge->mirror($this->sources->authorAreas(), false);
        } catch (\Throwable $e) {
            $rebuilt = false;
            $this->logger->warning('pages: the orphan handover could not rebuild the trees', [
                'target' => $path,
                'exception' => $e,
            ]);
        }
        try {
            $this->bridge->notify();
        } catch (\Throwable $e) {
            $notified = false;
            $this->logger->warning('pages: the orphan handover could not notify the bridge', [
                'target' => $path,
                'exception' => $e,
            ]);
        }

        // 8. the facts the caller reports
        return [
            'orphan' => $orphan,
            'target' => $siteRel,
            'site_path' => rtrim($ncPath, '/') . '/' . SiteTrees::SOURCE . '/' . $siteRel,
            'bytes' => strlen($content),
            'created' => $created,
            'notified' => $notified,
            'rebuilt' => $rebuilt,
            'message' => $this->l10n->t('Transferred %s to the orphan directory %s.', [$path, $orphan]),
        ];
    }

    /** A target in the app's stored shape: one leading slash, forward slashes, no traversal. */
    private static function normalise(string $target): string
    {
        return '/' . ltrim(trim(str_replace('\\', '/', $target)), '/');
    }

    /**
     * Whether the destination already holds exactly these bytes - what makes a retried handover a
     * no-op instead of an "already exists" conflict.
     *
     * The read is deliberately the raw one (`Node::getContent()`, not `FileService::read()`): an
     * author's file may be an image, and the text reader refuses binary content and anything above its
     * editor limit. This is a comparison of bytes, nothing else.
     */
    private function sameContent(string $uid, string $ncPath, string $rel, string $content): bool
    {
        $parent = dirname($rel);
        $folderPath = $parent === '.' || $parent === '' ? $ncPath : rtrim($ncPath, '/') . '/' . $parent;
        try {
            $folder = $this->files->existingFolder($uid, $folderPath);
            if ($folder === null) {
                return false;
            }
            $node = $folder->get(basename($rel));

            return $node instanceof File && $node->getContent() === $content;
        } catch (\Throwable) {
            return false;
        }
    }
}
