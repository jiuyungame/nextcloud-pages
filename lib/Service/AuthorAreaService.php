<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IUserManager;
use OCP\Server;

/**
 * The author space: one fixed folder inside every participating user's own files, and the article
 * targets the published site sees for it.
 *
 * The folder is `Pages/` and it is not configurable any more: a single place per user, whose
 * structure is entirely the author's (any depth, any file type). Its whole tree is mirrored into the
 * site under `<author_root>/<uid>/…`, where `author_root` is the one site-relative directory the
 * administrator sets once (default `tmp`). Those targets are the identity of an entry everywhere
 * else in the app (the `pages_articles.path` column, `/api/article?path=`, the bridge payload), so
 * this class is the only place that knows how a target corresponds to a file, and the only place
 * that has to ask "may this caller touch it".
 *
 * Ownership rule: a caller is either the author of the area (`$uid`) or an editor, publisher or
 * administrator, who may touch every area and the site skeleton. A target that names another author
 * is refused here rather than at the call site, so no endpoint can address a foreign file by accident.
 *
 * The class is otherwise side-effect free: it maps paths and reads metadata, it never writes a file.
 * The one exception is deliberate and named as such: `ensureArea()` creates the area folder.
 */
final class AuthorAreaService
{
    /** The folder every author keeps in their own files. Fixed, not a setting. */
    public const AREA = 'Pages';

    /** The extensions Jekyll renders as documents: those entries get the article editor and community. */
    public const RENDERABLE_EXTENSIONS = ['md', 'markdown', 'html'];

    /**
     * The retired target prefix of the pre-v2 layout (`_posts/<uid>/…`). Files have been moved and
     * manifest keys rewritten, but a stale target list, a hand-edited manifest or a not-yet-pushed
     * client may still name one: it is still resolved, only never produced.
     */
    public const LEGACY_PREFIX = '_posts';

    private IUserManager $userManager;

    public function __construct(
        private FileService $files,
        private SiteRegistry $registry,
        ?IUserManager $userManager = null,
    ) {
        // resolved from the container when the dependency injector builds this service, and handed in
        // directly by a test; the fallback keeps the class usable without it
        $this->userManager = $userManager ?? Server::get(IUserManager::class);
    }

    /** The author area, fixed: `Pages`. */
    public function area(): string
    {
        return self::AREA;
    }

    /** The site directory the author trees are mirrored into (`tmp` unless the admin changed it). */
    public function authorRoot(): string
    {
        return $this->registry->getAuthorRoot();
    }

    /** The display name of an author, or the uid when the account cannot answer (deleted user). */
    public function displayName(string $uid): string
    {
        try {
            $user = $this->userManager->get($uid);
            $name = $user === null ? '' : trim($user->getDisplayName());
        } catch (\Throwable) {
            $name = '';
        }

        return $name !== '' ? $name : $uid;
    }

    /** Whether the account behind an author area still exists. */
    public function userExists(string $uid): bool
    {
        try {
            return $this->userManager->userExists($uid);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The target prefix that holds one author's whole tree, without a trailing slash. */
    public function targetPrefix(string $uid): string
    {
        return $this->authorRoot() . '/' . $uid;
    }

    /** The retired prefix of the same author; used by the migration and by stale target lists. */
    public function legacyPrefix(string $uid): string
    {
        return self::LEGACY_PREFIX . '/' . $uid;
    }

    /**
     * The site target of one file inside an author area: the whole tree lands under
     * `<author_root>/<uid>/`, at the depth it has in the author's own folder. Every file is an entry
     * (an image is mirrored and reviewed like a page), which is why the extension does not matter.
     */
    public function targetFor(string $uid, string $areaRelative): string
    {
        $rel = $this->normalizeRelative($areaRelative);

        return $rel === '' ? '' : $this->targetPrefix($uid) . '/' . $rel;
    }

    /**
     * A target expressed inside the author's own area, or '' when it does not belong to it. The
     * retired `_posts/<uid>/` prefix is accepted too: a manifest written before the migration, or a
     * client that has not reloaded, must still find its file.
     */
    public function areaRelative(string $uid, string $target): string
    {
        $target = $this->normalizeRelative($target);
        if ($target === '') {
            return '';
        }
        foreach ([$this->targetPrefix($uid), $this->legacyPrefix($uid)] as $prefix) {
            if (str_starts_with($target, $prefix . '/')) {
                return substr($target, strlen($prefix) + 1);
            }
        }

        return '';
    }

    /**
     * A target as a file inside an author's files, or a refusal.
     *
     * `$uid` is the author whose area may be addressed; `$selfOnly` restricts the caller to that one
     * area (a user without the edit capability), while an editor passes false and may address any
     * author's target. Two shapes are accepted:
     *   - a site target: `<author_root>/<uid>/<file>` (or the retired `_posts/<uid>/<file>`)
     *   - a path relative to the area itself: `2026-05-01-hello.md`, `images/pic.png`, `drafts/note.md`
     * A single leading slash is tolerated on both (article paths travel with one through the JSON API),
     * but a doubled one is not: `//x` is an absolute-path attempt, and nothing here needs it.
     * Anything else - the site skeleton, another author's namespace, a traversal - is refused, with a
     * distinct code so the endpoint can answer 403 for "not yours" and 400 for "not a path this app
     * addresses".
     *
     * @return array{ok:bool,code:string,uid:string,file:string,target:string}
     */
    public function resolve(string $uid, string $target, bool $selfOnly = true): array
    {
        $uid = trim($uid);
        $normalized = $this->normalizeRelative($target);
        $refuse = static fn (string $code): array => [
            'ok' => false,
            'code' => $code,
            'uid' => $uid,
            'file' => '',
            'target' => '',
        ];
        if ($normalized === '') {
            return $refuse('empty');
        }
        if (str_starts_with(str_replace('\\', '/', trim($target)), '//')) {
            return $refuse('invalid');
        }
        if (str_contains($normalized, '..')) {
            return $refuse('invalid');
        }
        if ($uid === '') {
            return $refuse('invalid');
        }

        // a target that names an author: the uid in it has to be the one this call may address
        $named = $this->uidOfTarget($normalized);
        if ($named !== null) {
            if ($named !== $uid) {
                return $refuse('foreign');
            }

            return $this->resolved($uid, $normalized);
        }

        // A site target of the skeleton (a `_`-prefixed directory, `assets/…`) is not an author's
        // file; anything else is a path relative to the area itself, including a file the author put
        // into a `_`-prefixed folder (the app mirrors it, Jekyll just does not render it).
        if ($selfOnly && $this->isSiteTarget($normalized)) {
            return $refuse('foreign');
        }

        return $this->resolved($uid, $this->targetFor($uid, $normalized));
    }

    /**
     * The author a target belongs to, or null when the target names nobody. Used by the endpoints to
     * answer "whose file is this" before any capability decision.
     */
    public function uidOfTarget(string $target): ?string
    {
        $target = $this->normalizeRelative($target);
        foreach ([$this->authorRoot(), self::LEGACY_PREFIX] as $root) {
            $prefix = $root . '/';
            if (!str_starts_with($target, $prefix)) {
                continue;
            }
            $rest = substr($target, strlen($prefix));
            $uid = strstr($rest, '/', true);

            return $uid === false || $uid === '' ? null : $uid;
        }

        return null;
    }

    /** Whether a target is expressed in the retired `_posts/<uid>/…` namespace. */
    public function isLegacyTarget(string $target): bool
    {
        $target = $this->normalizeRelative($target);

        return str_starts_with($target, self::LEGACY_PREFIX . '/');
    }

    /**
     * The author area as a FileService-relative path. `ensure` creates the folder (and its parents)
     * on first use, which is what makes an empty install work without preparation.
     */
    public function ensureArea(string $uid, bool $ensure = true): string
    {
        $area = $this->area();
        if ($ensure) {
            $this->files->ensureSiteFolder($uid, $area);
        }

        return $area;
    }

    /** Whether the author area exists right now. */
    public function areaExists(string $uid): bool
    {
        return $this->files->siteExists($uid, $this->area());
    }

    /**
     * The author area as a tree, or an empty listing while it does not exist yet. A missing folder is
     * an ordinary state for a user who has not written anything (the app creates it on first use).
     *
     * @return array{nodes:list<array<string,mixed>>,truncated:bool}
     */
    public function tree(string $uid): array
    {
        try {
            return $this->files->tree($uid, $this->area());
        } catch (\Throwable) {
            return ['nodes' => [], 'truncated' => false];
        }
    }

    /** Whether a file inside an author area is one Jekyll renders as a document (an article). */
    public function isRenderable(string $areaRelative): bool
    {
        $rel = ltrim(str_replace('\\', '/', $areaRelative), '/');
        if ($rel === '') {
            return false;
        }
        if (str_starts_with($rel, '.')) {
            return false;
        }

        return in_array(strtolower((string)pathinfo($rel, PATHINFO_EXTENSION)), self::RENDERABLE_EXTENSIONS, true);
    }

    /**
     * Whether a file is mirrored at all: everything except an entry the author marked as private
     * with a leading dot (Nextcloud's own hidden files are not part of the site either).
     */
    public function isMirrored(string $areaRelative): bool
    {
        $rel = ltrim(str_replace('\\', '/', $areaRelative), '/');
        if ($rel === '') {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if (str_starts_with($segment, '.')) {
                return false;
            }
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The successful shape of resolve(): the target normalised and the file it maps to inside the
     * author's files.
     *
     * @return array{ok:bool,code:string,uid:string,file:string,target:string}
     */
    private function resolved(string $uid, string $target): array
    {
        $target = $this->normalizeRelative($target);
        if ($target === '') {
            return ['ok' => false, 'code' => 'invalid', 'uid' => $uid, 'file' => '', 'target' => ''];
        }
        $file = $this->areaRelative($uid, $target);

        return [
            'ok' => true,
            'code' => '',
            'uid' => $uid,
            // a target the app does not model (any other file of the area) is addressed as itself
            'file' => $file !== '' ? $file : $target,
            'target' => $target,
        ];
    }

    /** A target that belongs to the site skeleton rather than to an author's tree. */
    private function isSiteTarget(string $target): bool
    {
        return str_starts_with($target, '_') || str_starts_with($target, 'assets/');
    }

    /** Slashes normalised, leading slashes dropped and empty segments removed. */
    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }
}
