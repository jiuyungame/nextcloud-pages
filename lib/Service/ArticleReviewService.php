<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\Db\Article;
use OCA\Pages\Db\ArticleMapper;
use OCA\Pages\Db\PublishedMapper;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * The visibility workflow: a page is `internal` until a reviewer approves it for the site.
 *
 * A page has exactly three states, and the review decides only **whether it goes live** - never what
 * the page says:
 *
 *   internal ──request(online)──▶ requested(online) ──approve──▶ external ──publish──▶ on the site
 *      ▲                              │                              │
 *      │                              └──reject (note)──▶ internal   │
 *      └──────────────────── cancel ────────────────────┘           │
 *                                                                   │
 *   external ──request(offline)──▶ requested(offline) ──approve──▶ internal ──publish──▶ off the site
 *                    │
 *                    └──cancel──▶ external          external ──reject (下线)──▶ internal
 *
 * Every transition is enforced in this class, one method per author action: {@see self::request()}
 * (internal→requested(online), external→requested(offline)), {@see self::cancel()} (requested→back to
 * what it was), {@see self::decide()} (requested→external or internal by the intent, requested and
 * external→internal on a rejection), and {@see self::markPublished()} (external stays external, the
 * commit is recorded). Nothing else writes a state.
 *
 * The state lives in the visibility manifest - a file in the app's own appdata, see {@see ReviewStore} -
 * never in the article: an article stays a plain Jekyll page with no extra front matter, so the same
 * markdown builds the same way whether it is internal or live. That is also what makes "the site has
 * it" and "it is approved for the site" two separate facts: `external` is the approval, and the
 * manifest's `published` list is what the last commit really carried. An entry can therefore be
 * external and not yet served, which is exactly the window between a decision and the next publish.
 *
 * Getting an article off the site is the same pair of states in the other direction, and a pending
 * request never changes what the site serves: `external` + `requested(offline)` is still on the site
 * until the approval ({@see ReviewStore::onSite()}), so an author's request cannot take a public page
 * down by itself.
 *
 * Deleting is not a workflow of this class: a deleted file is a direct delete (Nextcloud's trash keeps
 * a copy), and the next publish carries the removal out of the commit because the manifest's
 * `published` list still names the path ({@see self::classify()}).
 *
 * Every change goes through the store, which writes the manifest and then syncs the database caches
 * (`pages_articles`, `pages_published`). Those tables are read here for the counts - a cache serves
 * queries and sorting - while anything that *decides* something (which articles may be committed,
 * what state an article is in) asks the manifest.
 */
class ArticleReviewService
{
    /** A request older than this is mentioned by the doctor, not failed. */
    public const STALE_REQUEST_DAYS = 14;

    public function __construct(
        private ArticleMapper $articles,
        private PublishedMapper $published,
        private ReviewStore $store,
        private FileService $files,
        private AuthorAreaService $areas,
        private SiteRegistry $registry,
        private ArticleIndexService $index,
        private IL10N $l10n,
        private IUserManager $userManager,
    ) {
    }

    /**
     * The author's request: put this page on the site (`online`), or take it off (`offline`).
     *
     * Asking twice for the same thing is not an error, so a double click is harmless and does not
     * move the moment the request was made. A caller with the review or publish grant may file it for
     * anybody's page (a reviewer's own route to the same decision), an author only for their own.
     *
     * @param string $intent `online` or `offline`
     * @throws \InvalidArgumentException when the intent is not one of the two, or the target is no article
     * @throws NotPermittedException when it is somebody else's article
     * @throws ConflictException when the entry is in a state the intent cannot start from
     * @return array{path:string,state:string,intent:string,created:bool,owner:string}
     */
    public function request(string $viewerUid, string $target, string $intent, bool $mayDecide): array
    {
        if (!in_array($intent, Article::INTENTS, true)) {
            throw new \InvalidArgumentException($this->l10n->t('A request intent of “online” or “offline” is required'));
        }
        $article = $this->require($target);
        $path = (string)$article->getPath();
        $owner = trim((string)$article->getOwnerUid());
        if ($owner === '') {
            // a skeleton post is published with the site by an editor; there is no owner whose folder
            // a visibility decision could move it out of
            throw new ConflictException($this->l10n->t(
                'This article belongs to the site itself; an editor publishes it with the site.',
            ));
        }
        if ($owner !== $viewerUid && !$mayDecide) {
            throw new NotPermittedException($this->l10n->t('You may only request a change for your own articles.'));
        }

        $manifest = $this->store->read();
        $state = ReviewStore::stateOf($manifest, $path);
        $pending = ReviewStore::intentOf($manifest, $path);
        if ($state === Article::STATE_REQUESTED) {
            if ($pending === $intent) {
                // the same request twice is the same fact: a double click must not move its moment
                return ['path' => $path, 'state' => $state, 'intent' => $intent, 'created' => false, 'owner' => $owner];
            }
            // the other direction does not apply either: a pending online request is not on the site
            // yet, so there is nothing to take offline, and vice versa
            throw new ConflictException($this->l10n->t(
                $pending === Article::INTENT_ONLINE
                    ? 'The site does not have this article yet; withdraw the online request first.'
                    : 'The site still has this article; withdraw the offline request first.',
            ));
        }
        if ($intent === Article::INTENT_ONLINE && $state !== Article::STATE_INTERNAL) {
            throw new ConflictException($this->l10n->t('This article is approved for the site already.'));
        }
        if ($intent === Article::INTENT_OFFLINE && $state !== Article::STATE_EXTERNAL) {
            throw new ConflictException($this->l10n->t(
                'The site does not have this article, so there is nothing to take offline.',
            ));
        }

        $this->store->setState([$path], Article::STATE_REQUESTED, [
            'owner' => $owner,
            'intent' => $intent,
            'note' => '',
            'decidedBy' => '',
            'submittedAt' => time(),
        ]);

        return ['path' => $path, 'state' => Article::STATE_REQUESTED, 'intent' => $intent, 'created' => true, 'owner' => $owner];
    }

    /**
     * Withdraw a pending request: the entry returns to the state its intent started from - internal for
     * an online request, external for an offline one. Nothing about the site changes either way, which
     * is why a withdrawal needs no bridge call.
     *
     * The author of the article or a holder of the review/publish grant may withdraw it; the same rule
     * as filing it, because a reviewer may have filed it on the author's behalf.
     *
     * @throws \InvalidArgumentException when the target is no article
     * @throws NotPermittedException when the caller is neither the author nor a grant holder
     * @throws ConflictException when no request is waiting
     * @return array{path:string,state:string,intent:string,cancelled:bool,owner:string}
     */
    public function cancel(string $viewerUid, string $target, bool $mayDecide): array
    {
        $article = $this->require($target);
        $path = (string)$article->getPath();
        $owner = trim((string)$article->getOwnerUid());
        if ($owner !== $viewerUid && !$mayDecide) {
            throw new NotPermittedException($this->l10n->t('You may only withdraw a request about your own articles.'));
        }
        $manifest = $this->store->read();
        $entry = $this->requireEntry($manifest, $path);
        if (($entry['state'] ?? '') !== Article::STATE_REQUESTED) {
            throw new ConflictException($this->l10n->t('No request is waiting for a decision on this article.'));
        }
        $intent = ReviewStore::intentOf($manifest, $path);
        $state = $intent === Article::INTENT_OFFLINE ? Article::STATE_EXTERNAL : Article::STATE_INTERNAL;
        // the intent is the request: withdrawing it leaves the state the request started from, and the
        // moment it was made stays as the record of the last request
        $this->store->setState([$path], $state, ['intent' => '', 'note' => '', 'decidedBy' => '']);

        return ['path' => $path, 'state' => $state, 'intent' => $intent, 'cancelled' => true, 'owner' => $owner];
    }

    /**
     * The reviewer's decision about one entry.
     *
     *   approve  apply the intent: `requested(online)` becomes `external`, `requested(offline)` and a
     *            rejected request become `internal`
     *   reject   the entry may not be on the site: `requested` (either direction) and an `external`
     *            entry go to `internal`, with the note. On an external entry this is the reviewer's
     *            direct take-down (下线), which is why it needs no request first.
     *
     * The review grant is the only condition: a reviewer may decide about an entry they own too. On an
     * instance whose only reviewer is also the author, refusing self-decisions would make every request
     * undecidable; `decidedBy` records who decided, which is the accountability that matters.
     *
     * @throws NotFoundException when the manifest does not know the article
     * @throws ConflictException when the decision does not apply to the entry's state
     * @return array{path:string,state:string,decision:string,intent:string,owner:string,decidedBy:string}
     */
    public function decide(string $reviewerUid, string $target, string $decision, string $note): array
    {
        if ($decision !== 'approve' && $decision !== 'reject') {
            throw new \InvalidArgumentException($this->l10n->t('A decision of “approve” or “reject” is required'));
        }
        $path = $this->path($target);
        $manifest = $this->store->read();
        $entry = $this->requireEntry($manifest, $path);
        $owner = trim((string)$entry['owner']);
        $state = (string)$entry['state'];
        $intent = ReviewStore::intentOf($manifest, $path);
        if ($decision === 'approve') {
            if ($state !== Article::STATE_REQUESTED) {
                throw new ConflictException($this->l10n->t('No request is waiting for a decision on this article.'));
            }
            $next = $intent === Article::INTENT_OFFLINE ? Article::STATE_INTERNAL : Article::STATE_EXTERNAL;
            $this->store->setState([$path], $next, ['intent' => '', 'note' => '', 'decidedBy' => $reviewerUid]);
        } else {
            if ($state === Article::STATE_INTERNAL) {
                throw new ConflictException($this->l10n->t('This article is not on the site and not requested either.'));
            }
            // `reject` is the decision that the entry may not be on the site: it applies to a pending
            // request of either direction and to an entry that is already external (the direct 下线)
            $this->store->setState([$path], Article::STATE_INTERNAL, [
                'intent' => '',
                'note' => $note,
                'decidedBy' => $reviewerUid,
            ]);
            $next = Article::STATE_INTERNAL;
        }

        return [
            'path' => $path,
            'state' => $next,
            'decision' => $decision,
            'intent' => $intent,
            'owner' => $owner,
            'decidedBy' => $reviewerUid,
        ];
    }

    /**
     * How many **author** articles sit in each state, for the doctor and the admin panel.
     *
     * The site's own files (`owner === ''`) are published with the site by an editor and never enter
     * the visibility workflow: counting them made the request panel claim a pile of internal entries
     * nobody can review — the demo posts a theme ships with `published: false` among them. A row whose
     * author is gone or whose file no longer exists is left out too, so the numbers describe the site
     * rather than the table. A table that cannot be read at all answers zeroes, which is a display
     * problem and not one of the pages that ask for these numbers (the doctor reports the same table
     * separately).
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(Article::STATES, 0);
        try {
            foreach ($this->articles->findAll() as $row) {
                if (trim((string)$row->getOwnerUid()) === '') {
                    continue;
                }
                // one lookup per row: the file has to be there for the article to be counted at all
                if (!$this->liveRow($row) || !$this->rowFileExists($row)) {
                    continue;
                }
                $state = (string)$row->getState();
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        } catch (\Throwable) {
            return $counts;
        }

        return $counts;
    }

    /**
     * The requests nobody has looked at for a long time, for the doctor's warning.
     *
     * @return list<string> target paths
     */
    public function staleRequests(): array
    {
        $limit = time() - self::STALE_REQUEST_DAYS * 86400;
        $out = [];
        try {
            foreach ($this->articles->findByState(Article::STATE_REQUESTED) as $row) {
                if (!$this->liveRow($row)) {
                    continue;
                }
                if ((int)$row->getSubmittedAt() > 0 && (int)$row->getSubmittedAt() < $limit) {
                    $out[] = (string)$row->getPath();
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * Whether the file behind a row is still there: an author's post in their own folder, a site-owned
     * post in the site folder.
     */
    private function rowFileExists(Article $row): bool
    {
        $path = ltrim((string)$row->getPath(), '/');
        if ($path === '') {
            return false;
        }

        return $this->pathExists(trim((string)$row->getOwnerUid()), $path);
    }

    /** Whether an article still belongs to somebody: the site itself, or an account that exists. */
    private function liveRow(Article $row): bool
    {
        $owner = trim((string)$row->getOwnerUid());
        if ($owner === '') {
            return true;
        }
        try {
            return $this->userManager->get($owner) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Which paths a publish call may commit.
     *
     * Two sources, deliberately:
     *
     *  - **everything `external`**, read from the visibility manifest: the diff the bridge prints is
     *    capped at 100 entries, so the list of entries that were decided about cannot come from it;
     *  - **everything else that changed**, but only for a caller who may edit, and never an author's
     *    own files. That is what keeps a page that is not external out of a commit.
     *
     * The removals are the other half of the same list: a path the last commit put on the site is
     * staged again when it is no longer external, which is how a take-down reaches the commit.
     *
     * @param list<string> $changed paths relative to the work copy, as the bridge reports them
     * @return array{paths:list<string>,owners:array<string,string>,articles:list<array{path:string,owner:string}>,skipped:list<string>,stale:list<string>}
     */
    public function classify(array $changed, bool $canEdit): array
    {
        /** @var array<string,string> $paths site path => owner uid ('' for the site's own files) */
        $paths = [];
        $articles = [];
        $skipped = [];
        $stale = [];
        $manifest = $this->store->read();

        foreach ($manifest['entries'] as $target => $entry) {
            // a pending take-offline request is still approved for the site (see ReviewStore::onSite())
            if (!ReviewStore::onSite($manifest, (string)$target)) {
                continue;
            }
            $owner = trim((string)$entry['owner']);
            // An external entry can outlive its file: the account was deleted, or the author removed the
            // post again. The mirror cannot delete what an app no longer lists, so such a file may
            // still sit in the work copy - it must not be committed from here.
            if (!$this->pathExists($owner, (string)$target)) {
                continue;
            }
            $paths[$target] = $owner;
            $articles[] = ['path' => $target, 'owner' => $owner];
        }

        // The published list is the truth about what the site has. A file that is on that list but no
        // longer exists in Nextcloud was deleted (or its account was): the removal has to travel with
        // the next commit, otherwise the site keeps serving a page nobody can edit any more.
        //
        // An entry that is no longer on the site (taken down, or its request rejected) is the same case
        // with the file still on disk: its target left the list the mirror syncs, so the work copy has
        // no copy of it and the commit has to carry the removal. The entry's *state* decides, not the
        // file - the file stays, which is exactly why a "does the source exist" test would keep the path
        // on the site forever. And the state does not change by itself, so a second publish stages the
        // same removal as the first (there is nothing left to remove by then, and the published entry is
        // forgotten once).
        //
        // `$mirrored` is what the last accepted push told the bridge the **commit tree** holds: a
        // path that is still in that list has not been dropped from the commit tree yet, and staging
        // it would commit a removal git does not have. A path that left it (taken down: still in the
        // author area, no longer on the site) is exactly what the next commit has to carry away.
        // Then the entry stays published until the push that omits it arrives.
        $mirrored = $this->registry->getAuthorSourcesOnSite();
        foreach ($manifest['published'] as $path => $entry) {
            $owner = (string)$entry['owner'];
            $offsite = $owner !== ''
                && !ReviewStore::onSite($manifest, (string)$path)
                && !in_array((string)$path, $mirrored[$owner] ?? [], true);
            if (isset($paths[$path]) || (!$offsite && $this->pathExists($owner, (string)$path))) {
                continue;
            }
            $paths[$path] = $owner;
            $stale[] = $path;
        }

        foreach ($changed as $path) {
            $rel = ltrim(trim((string)$path), '/');
            if ($rel === '' || isset($paths[$rel])) {
                continue;
            }
            if ($this->isAuthorPath($rel)) {
                // an author's file is only published through a decision, never as a side effect
                $skipped[$rel] = true;
                continue;
            }
            if ($canEdit) {
                $paths[$rel] = '';
            }
        }

        $list = array_keys($paths);
        sort($list);
        $skippedList = array_keys($skipped);
        sort($skippedList);
        $staleList = array_values(array_unique($stale));
        sort($staleList);

        return [
            'paths' => $list,
            'owners' => $paths,
            'articles' => $articles,
            'skipped' => $skippedList,
            'stale' => $staleList,
        ];
    }

    /**
     * Whether a site path still has a file behind it: an author's path in their own folder, a path of
     * the site itself in the site folder. A path whose account is gone answers `false` rather than
     * throwing: it is simply not publishable.
     */
    private function pathExists(string $uid, string $path): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return false;
        }
        try {
            if ($uid === '') {
                $site = $this->registry->getSite();
                $owner = trim((string)($site['nc_user'] ?? ''));
                $ncPath = trim((string)($site['nc_path'] ?? ''));
                if ($owner === '' || $ncPath === '') {
                    return false;
                }

                return $this->files->fileExists($owner, $ncPath, $path);
            }
            $resolved = $this->areas->resolve($uid, $path);
            if (!$resolved['ok']) {
                // a path of the site itself, looked up for a uid that is not its owner
                return false;
            }

            return $this->files->fileExists($uid, $this->areas->area(), (string)$resolved['file']);
        } catch (\Throwable) {
            // an account that no longer exists, a path the area refuses, a backend that will not answer:
            // all of them mean "there is no file to commit", which is what the callers ask
            return false;
        }
    }

    /**
     * Record what the commit put on the site. Called with what the bridge actually accepted, so a
     * failed publish leaves the entries external and the next attempt picks them up again.
     *
     * Three things happen, in this order: the published-file list learns the new paths (that is what
     * the reading list and the take-down logic read), the removals that were carried out are forgotten,
     * and the articles that went out keep their `external` state with the new moment and revision.
     *
     * @param array{paths:list<string>,owners:array<string,string>,articles:list<array{path:string,owner:string}>,stale:list<string>} $classified
     * @return array{published:list<string>,forgotten:list<string>,recorded:int}
     */
    public function markPublished(array $classified, string $revision): array
    {
        $owners = is_array($classified['owners'] ?? null) ? $classified['owners'] : [];
        // the removals go out of the list *after* the recording, so a path that was both staged as a
        // removal and named by an entry cannot survive as a stale row
        $recorded = $this->store->record($owners, $revision);
        $forgotten = $this->store->forget(is_array($classified['stale'] ?? null) ? $classified['stale'] : []);

        $done = [];
        foreach ($classified['articles'] ?? [] as $article) {
            $target = (string)($article['path'] ?? '');
            if ($target !== '') {
                $done[] = $target;
            }
        }
        if ($done !== []) {
            // the state does not move: `external` is the approval, the publish only records the moment
            // and the revision that carries it
            $this->store->setState($done, Article::STATE_EXTERNAL, [
                'intent' => '',
                'publishedAt' => time(),
                'revision' => $revision,
            ]);
        }

        return ['published' => $done, 'forgotten' => $forgotten, 'recorded' => $recorded];
    }

    /**
     * How many files the *cache* says are on the site. {@see ReviewStore::published()} is the truth; the
     * doctor prints both numbers, because two of them drifting apart is what a stale cache looks like.
     */
    public function publishedCount(): int
    {
        return $this->published->countAll();
    }

    /**
     * The article row behind a target, or an exception. Every entry point goes through here, so a
     * target that the app does not know cannot be requested or decided about.
     */
    private function require(string $target): Article
    {
        $path = $this->path($target);
        if ($path === '') {
            throw new \InvalidArgumentException($this->l10n->t('An article path is required'));
        }
        $article = $this->articles->findByPath($path);
        if ($article === null) {
            // an entry that was never indexed (an image the author just uploaded, or a file the scan
            // has not seen yet): the manifest is not the only place a decision can be prepared from,
            // so the row is created from the file that is really there
            $this->registerMissing($path);
            $article = $this->articles->findByPath($path);
        }
        if ($article === null) {
            throw new \InvalidArgumentException($this->l10n->t('No such article: %s', [$target]));
        }

        return $article;
    }

    /**
     * Creates the cache row for an entry that has none yet. Only a file that really exists in an
     * author's area is registered: a target naming nothing must stay unknown, and the caller gets the
     * 404 it had before.
     */
    private function registerMissing(string $path): void
    {
        $target = ltrim($path, '/');
        $uid = $this->areas->uidOfTarget($target);
        if ($uid === null) {
            return;
        }
        $resolved = $this->areas->resolve($uid, $path);
        if (!$resolved['ok'] || $resolved['file'] === '') {
            return;
        }
        try {
            if (!$this->areas->areaExists($uid) || !$this->files->fileExists($uid, $this->areas->area(), (string)$resolved['file'])) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        $this->index->register($uid, $this->areas->area(), (string)$resolved['file'], (string)$resolved['target']);
    }

    /**
     * The manifest entry a decision is about, or a 404: a decision about an article the manifest does
     * not know would be a decision about nothing.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    private function requireEntry(array $manifest, string $path): array
    {
        $entry = ReviewStore::entryOf($manifest, $path);
        if ($entry === []) {
            throw new NotFoundException($this->l10n->t('No such article: %s', [$path]));
        }

        return $entry;
    }

    /** Normalise whatever the client sent into the stored form (`/<author_root>/<uid>/<file>`). */
    private function path(string $target): string
    {
        $target = trim(str_replace('\\', '/', $target));
        if ($target === '') {
            return '';
        }
        if (str_contains($target, '..')) {
            throw new \InvalidArgumentException($this->l10n->t('An article path must not contain ..'));
        }

        return '/' . ltrim($target, '/');
    }

    /** Whether a work-copy path belongs to an author area rather than to the site itself. */
    private function isAuthorPath(string $rel): bool
    {
        // `<author_root>/<uid>/…` (and the retired `_posts/<uid>/…`): the uid segment is what makes
        // it an author's, so the site's own `_posts/<file>.md` is not one
        return $this->areas->uidOfTarget($rel) !== null;
    }
}
