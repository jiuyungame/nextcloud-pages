<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Security\ICrypto;

/**
 * Site registry: the app is the source of truth for site config, while the bridge TOML keeps only
 * the listen address and the two bootstrap secrets. Field names deliberately reuse the bridge's
 * snake_case vocabulary so that no translation layer can silently drop fields.
 *
 * This app has exactly one site: the config is a single object under KEY_SITE, with no id, no name
 * and no per-site working copy.
 */
class SiteRegistry
{
    public const KEY_BRIDGE_URL = 'bridge_url';
    public const KEY_SHARED_SECRET = 'bridge_shared_secret';
    public const KEY_SIGNING_SECRET = 'bridge_session_signing_secret';
    public const KEY_SITE = 'site';
    public const KEY_REVISION = 'sites_revision';
    /**
     * Global (host-wide) bridge parameters the administrator set in this app, as one JSON object.
     * Only keys that were actually set are stored: an absent key means "the bridge keeps using its
     * TOML or built-in default", so the app never freezes an invented default into the bridge.
     */
    public const KEY_BRIDGE_GLOBAL = 'bridge_global';

    /**
     * Every global bridge parameter this app may push, with the JSON type it must have. The bridge
     * rejects an unknown key with HTTP 400, so the app validates the same list before sending.
     *
     * @var array<string,string>
     */
    public const GLOBAL_TYPES = [
        'listen' => 'string',
        'nc_data_root' => 'string',
        'work_root' => 'string',
        'state_dir' => 'string',
        'override_dir' => 'string',
        'log_dir' => 'string',
        'build_timeout_s' => 'int',
        'build_debounce_ms' => 'int',
        'log_tail_lines' => 'int',
        'mirror_verify' => 'string',
        'mirror_delete_guard_ratio' => 'float',
        'mirror_delete_guard_min' => 'int',
        'mirror_preserve_exec' => 'bool',
        'mirror_use_gitignore' => 'bool',
        'exclude' => 'stringList',
        'unexclude' => 'stringList',
    ];

    /** @return list<string> */
    public static function globalKeys(): array
    {
        return array_keys(self::GLOBAL_TYPES);
    }

    // group grants: administrators always hold every capability, these lists only add to that.
    // Two of the three names are retired: `edit_groups` was merged into `publish_groups` (v2), and
    // `publish_groups` was merged into `review_groups` (v3). Only the review grant is read any more;
    // the two retired keys grant nothing and are kept for compatibility reads alone.
    public const KEY_EDIT_GROUPS = 'edit_groups';
    public const KEY_PUBLISH_GROUPS = 'publish_groups';
    public const KEY_REVIEW_GROUPS = 'review_groups';

    /**
     * The author space: the folder every participating user keeps in their own files (fixed), the one
     * site directory their trees are mirrored into, and who may author at all. All of it lives in the
     * same appconfig as the bridge settings, because it belongs to this app rather than to the site
     * object the bridge receives.
     */
    public const AUTHOR_AREA = 'Pages';
    /** The retired setting of the pre-v2 layout: nothing reads it any more. */
    public const KEY_AUTHOR_AREA = 'author_area';
    public const DEFAULT_AUTHOR_ROOT = 'tmp';
    public const KEY_AUTHOR_ROOT = 'author_root';
    /**
     * Names an author root may not take: the site's own Jekyll directories and its asset folder. The
     * shape rule already refuses the `_`-prefixed ones; `assets` only fails this list.
     */
    public const RESERVED_AUTHOR_ROOTS = ['_posts', '_data', '_layouts', '_includes', '_sass', 'assets', '_site', '.git'];
    public const KEY_AUTHOR_GROUPS = 'author_groups';

    /**
     * The directory that receives an article whose author deleted it, instead of losing it: the file is
     * written into the **site source** as `<author_root>/<orphan_owner>/<rel>` and the manifest entry is
     * forgotten, so the site owns the page from then on.
     *
     * It has to be a directory name that is **not a real user id**: the bridge excludes every *real*
     * author's `<author_root>/<uid>/` prefix from the skeleton mirror, so a name that happens to be an
     * account would make the transferred file invisible to the mirror - the exact opposite of the point.
     */
    public const KEY_ORPHAN_OWNER = 'orphan_owner';
    public const DEFAULT_ORPHAN_OWNER = 'Admin';

    /** Upper bound of an orphan owner name: a directory segment, not a path and not a sentence. */
    public const ORPHAN_OWNER_MAX = 64;
    /**
     * The author targets an older version's last accepted push left behind, keyed by uid:
     * `{uid: [target, ...]}`. Nothing writes it any more and the steady-state flow does not read it;
     * the one-shot overwrite consults it only to learn directory names that may still hold stale
     * bridge files, and the reset deletes it.
     */
    public const KEY_AUTHOR_SOURCES = 'author_sources';

    /**
     * The retired on-site companion of the map above, keyed by uid. Nothing derives an on-site set
     * any more: what the site serves is `online/`, and a publish reads that, so nothing reads this
     * key; it is only deleted (overwrite, reset) so an install that still carries it does not keep a
     * second, stale answer around.
     */
    public const KEY_AUTHOR_SOURCES_ONSITE = 'author_sources_onsite';

    /**
     * Suffix of the bridge's preview directory. The app derives the path from the work root it
     * pushes, so the preview tree always sits next to the commit tree on the bridge host.
     */
    public const PREVIEW_SUFFIX = '.preview';

    private const SECRET_KEYS = [self::KEY_SHARED_SECRET, self::KEY_SIGNING_SECRET];

    /**
     * Default templates for "new file"; a site overrides them with `new_file.templates`.
     * `when_present` is a gate: the template is offered only when those files exist in the tree.
     * The front end replaces `{slug}` `{title}` `{date}` `{time}` `{year}` `{month}` `{day}`.
     */
    private const DEFAULT_NEW_FILE_TEMPLATES = [
        [
            'id' => 'jekyll-post',
            'label' => 'Post (Jekyll)',
            'path' => '_posts/{date}-{slug}.md',
            'when_present' => ['_config.yml'],
            'content' => <<<'MD'
---
layout: post
title: "{title}"
date: {date} {time} +0800
categories:
tags:
---

MD,
        ],
        [
            'id' => 'jekyll-page',
            'label' => 'Page (Jekyll)',
            'path' => '{slug}.md',
            'when_present' => ['_config.yml'],
            'content' => <<<'MD'
---
layout: page
title: "{title}"
---

MD,
        ],
        // fallback: last, because the UI preselects the first template whose gate passes
        [
            'id' => 'blank',
            'label' => 'Blank file',
            'path' => '',
            'content' => '',
        ],
    ];

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private IL10N $l10n,
        private IUserManager $userManager,
    ) {
    }

    public function getBridgeUrl(): string
    {
        return (string)$this->config->getAppValue('pages', self::KEY_BRIDGE_URL, 'http://127.0.0.1:8787');
    }

    public function setBridgeUrl(string $url): void
    {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException($this->l10n->t('The bridge URL must start with http:// or https://'));
        }
        $this->config->setAppValue('pages', self::KEY_BRIDGE_URL, $url);
    }

    public function getSharedSecret(): string
    {
        return $this->getSecret(self::KEY_SHARED_SECRET);
    }

    public function setSharedSecret(string $secret): void
    {
        $this->setSecret(self::KEY_SHARED_SECRET, $secret);
    }

    public function getSigningSecret(): string
    {
        return $this->getSecret(self::KEY_SIGNING_SECRET);
    }

    public function setSigningSecret(string $secret): void
    {
        $this->setSecret(self::KEY_SIGNING_SECRET, $secret);
    }

    /** Secrets are returned as fingerprints only, never as plaintext. */
    public function secretFingerprint(string $key): string
    {
        $value = $this->getSecret($key);
        if ($value === '') {
            return '';
        }
        return 'SHA256:' . substr(hash('sha256', $value), 0, 16);
    }

    public function isConnected(): bool
    {
        return $this->getSharedSecret() !== '' && $this->getBridgeUrl() !== '';
    }

    /**
     * Whether the loopback channel may be used to reach the bridge. Nextcloud blocks app access to
     * local addresses, and the only framework switch (allow_local_remote_servers) is global: this
     * narrow channel applies to 127.0.0.1/::1/localhost only and is on by default.
     */
    public function isLoopbackBypassEnabled(): bool
    {
        return $this->config->getAppValue('pages', 'bridge_loopback_bypass', 'yes') === 'yes';
    }

    public function setLoopbackBypass(bool $enabled): void
    {
        $this->config->setAppValue('pages', 'bridge_loopback_bypass', $enabled ? 'yes' : 'no');
    }

    /**
     * The last push the bridge never accepted, as `{ts, author, code, message, retryable}`.
     *
     * It is app state, not a table: the per-entry "not pushed" mark lives in the browser only (the plan
     * forbids a column for it), so this one record is what lets `occ pages:doctor` answer "did the last
     * save reach the bridge, for whom, and can trying again help" after a reload. It is written by the
     * same catch that produces the save answer's `bridge` block and cleared by the next accepted push,
     * so it never grows into a log or a queue.
     */
    public const KEY_LAST_PUSH_FAILURE = 'last_push_failure';

    /**
     * The push failure of the **last publish** (a commit whose push was attempted and failed).
     *
     * It is what marks the live rows `pushFailed`: the commit carried the entries, so they are live,
     * and the one fact that is still wrong is that the remote did not get them. Cleared
     * by the next publish whose push was not attempted (no remote) or went through - there is no
     * "not pushed" state stored anywhere, only this one failure record.
     */
    public const KEY_PUBLISH_PUSH_FAILURE = 'publish_push_failure';

    /**
     * Whether a publish repository was ever seen to exist, as a sticky `0`/`1`.
     *
     * The bridge is the authority - only its working copy really knows - so every path that sees the
     * repository (a commit, a successful init, an `initialized:true` status) marks it here. The flag
     * is what answers when the bridge cannot be asked: a status call that fails must not turn the
     * permanent lock on `repo_url`/`branch` into an open one. Only the full reset clears it, because
     * only the reset deletes the repository.
     */
    public const KEY_REPO_INITIALISED = 'repo_initialised';

    /**
     * The pending bridge cleanup of a reset that ran while the bridge was unreachable.
     *
     * The app-side reset clears the normal credentials (the app is unpaired afterwards), so the
     * retry of the bridge half needs the address and shared secret it was reset with. They live in
     * this one record - encrypted like every other secret - and only until the bridge confirms the
     * cleanup; `occ pages:doctor` reports the pending state.
     */
    public const KEY_RESET_PENDING = 'bridge_reset_pending';

    /**
     * The global bridge parameters the administrator set here, exactly as stored: no defaults are
     * filled in, because an omitted key is what keeps the bridge on its own TOML/default value.
     *
     * @return array<string,mixed>
     */
    public function getGlobal(): array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_BRIDGE_GLOBAL, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        // a hand-edited value that is not a JSON object reads as "nothing set", never as a widening
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * Replaces the whole set in one write. A null value drops the key ("back to the bridge's own
     * value"), a null map clears every pushed parameter.
     *
     * @param array<string,mixed>|null $values
     */
    public function setGlobal(?array $values): void
    {
        if ($values === null || $values === []) {
            $this->config->deleteAppValue('pages', self::KEY_BRIDGE_GLOBAL);
            return;
        }
        $this->config->setAppValue(
            'pages',
            self::KEY_BRIDGE_GLOBAL,
            json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Merges one payload into the stored set: a key present with a null value removes it.
     *
     * @param array<string,mixed> $values
     */
    public function mergeGlobal(array $values): void
    {
        $current = $this->getGlobal();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
                continue;
            }
            $current[$key] = $value;
        }
        $this->setGlobal($current);
    }

    /**
     * Compatibility read of the retired editing grant. Editing collapsed into publishing (v2), and
     * publishing collapsed into reviewing (v3): this list grants nothing any more, and nothing may
     * gate on it.
     *
     * An unset or malformed value reads as "no group is granted", never as "everyone".
     *
     * @return list<string>
     */
    public function getEditGroups(): array
    {
        return $this->getGroupList(self::KEY_EDIT_GROUPS);
    }

    /**
     * Groups granted reviewing — which is also the publishing grant: approving or rejecting
     * visibility requests, taking an article off the site directly, moderating
     * comments, and publishing (building, diffing, pushing, the site's own files and reaching other
     * authors' areas). Administrators do not need to be listed.
     *
     * @return list<string>
     */
    public function getReviewGroups(): array
    {
        return $this->getGroupList(self::KEY_REVIEW_GROUPS);
    }

    /**
     * Replaces the whole review-and-publish grant with this list (an empty list means "administrators
     * only").
     *
     * @param array<int,mixed> $gids
     */
    public function setReviewGroups(array $gids): void
    {
        $this->setGroupList(self::KEY_REVIEW_GROUPS, $gids);
    }

    /**
     * Compatibility read of the retired publishing grant. Publishing is the review grant now
     * ({@see getReviewGroups()}): this list grants nothing, and nothing may gate on it.
     *
     * @return list<string>
     */
    public function getPublishGroups(): array
    {
        return $this->getGroupList(self::KEY_PUBLISH_GROUPS);
    }

    /**
     * The single site directory every author's whole tree is mirrored into: author A's files become
     * `<author_root>/<A>/…` in the site. One path segment, never empty, default `tmp`.
     *
     * A stored value that cannot be one (hand-edited, or left over from an older format) reads as the
     * default rather than as an empty path, which would put every author's tree at the site root.
     */
    public function getAuthorRoot(): string
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_AUTHOR_ROOT, self::DEFAULT_AUTHOR_ROOT);
        try {
            return $this->cleanAuthorRoot($raw);
        } catch (\InvalidArgumentException) {
            return self::DEFAULT_AUTHOR_ROOT;
        }
    }

    /**
     * Stores the author root. The value decides where every author's files land in the site, so it is
     * validated instead of sanitised: a typo must be a 400, not a second, silent layout.
     *
     * @throws \InvalidArgumentException when the value is not one usable path segment
     */
    public function setAuthorRoot(string $root): void
    {
        $clean = $this->cleanAuthorRoot($root);
        $this->config->setAppValue('pages', self::KEY_AUTHOR_ROOT, $clean);
    }

    /**
     * One clean author root, or an `\InvalidArgumentException` naming the rule that failed.
     *
     * @throws \InvalidArgumentException
     */
    private function cleanAuthorRoot(string $root): string
    {
        $root = trim($root);
        if ($root === '') {
            throw new \InvalidArgumentException(
                $this->l10n->t('The author content directory must not be empty.'),
            );
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $root) !== 1) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The author content directory must be a single folder name: letters, digits, dot, underscore or hyphen, starting with a letter or a digit (no slash, no “..”, no leading dot).',
            ));
        }
        if (in_array(strtolower($root), self::RESERVED_AUTHOR_ROOTS, true)) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The author content directory “%s” is reserved by the site.',
                [$root],
            ));
        }

        return $root;
    }

    /**
     * The site directory that receives an article whose author deleted it, default `Admin`.
     *
     * The stored value is shape-checked here and a value that cannot be one folder name reads as the
     * default: a hand-edited config must not turn the transfer into a path with a slash (or into the
     * site root). Whether the name is free - not a real user - is checked where it is *used*
     * ({@see setOrphanOwner()} for the form, and the transfer itself for the moment of the move),
     * because an account created after the setting was saved must not make the stored value look
     * invalid for every reader.
     */
    public function getOrphanOwner(): string
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_ORPHAN_OWNER, self::DEFAULT_ORPHAN_OWNER);
        try {
            return $this->cleanOrphanOwner($raw);
        } catch (\InvalidArgumentException) {
            return self::DEFAULT_ORPHAN_OWNER;
        }
    }

    /**
     * Stores the orphan owner. The value decides where an author's deleted article lands in the site,
     * so it is validated instead of sanitised, and it must not name an existing account: the mirror
     * excludes real users' author directories, so the transferred file would never reach the site.
     *
     * @throws \InvalidArgumentException when the value is not one usable folder name, or names a user
     */
    public function setOrphanOwner(string $owner): void
    {
        $clean = $this->cleanOrphanOwner($owner);
        if ($this->userManager->userExists($clean)) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The orphan owner must not be an existing user: its files would be excluded from the site source.',
            ));
        }
        $this->config->setAppValue('pages', self::KEY_ORPHAN_OWNER, $clean);
    }

    /**
     * One clean orphan owner, or an `\InvalidArgumentException` naming the rule that failed. The shape
     * is the author root's (one path segment, starting with a letter or a digit), because the value
     * becomes one directory inside the site source.
     *
     * @throws \InvalidArgumentException
     */
    private function cleanOrphanOwner(string $owner): string
    {
        $owner = trim($owner);
        if ($owner === '' || strlen($owner) > self::ORPHAN_OWNER_MAX
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $owner) !== 1) {
            throw new \InvalidArgumentException($this->l10n->t(
                'The orphan owner must be a single folder name (letters, digits, dot, underscore, hyphen; no slash).',
            ));
        }

        return $owner;
    }

    /**
     * Groups allowed to author. An empty list is the deliberate "every signed-in user may author"
     * setting, so it must stay distinguishable from a broken value; both are returned as [] here and
     * the caller treats emptiness as "everyone" (see getGroupList() for the malformed case).
     *
     * @return list<string>
     */
    public function getAuthorGroups(): array
    {
        return $this->getGroupList(self::KEY_AUTHOR_GROUPS);
    }

    /**
     * Replaces the whole authoring grant with this list (an empty list means "every signed-in user").
     *
     * @param array<int,mixed> $gids
     */
    public function setAuthorGroups(array $gids): void
    {
        $this->setGroupList(self::KEY_AUTHOR_GROUPS, $gids);
    }

    /**
     * The author targets an older version's last accepted push left behind (legacy cache; the author
     * content flow no longer uses it - kept only so the one-shot overwrite can read the directory
     * names it may have to purge).
     *
     * @return array<string,list<string>>
     */
    public function getAuthorSources(): array
    {
        return $this->decodeTargetMap(self::KEY_AUTHOR_SOURCES);
    }

    /**
     * Legacy writer of the two target maps above. The current author content flow never calls it -
     * saves travel as deltas and the complete state as the one-shot overwrite - but the keys are still
     * part of an older install's stored state, so this stays the one place their JSON shape is written.
     *
     * @param array<string,list<string>> $sources
     * @param array<string,list<string>>|null $onsite
     */
    public function setAuthorSources(array $sources, ?array $onsite = null): void
    {
        $this->storeTargetMap(self::KEY_AUTHOR_SOURCES, $sources);
        if ($onsite !== null) {
            $this->storeTargetMap(self::KEY_AUTHOR_SOURCES_ONSITE, $onsite);
        }
    }

    /**
     * @return array<string,list<string>>
     */
    private function decodeTargetMap(string $key): array
    {
        $raw = (string)$this->config->getAppValue('pages', $key, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $uid => $targets) {
            $uid = trim((string)$uid);
            if ($uid === '' || !is_array($targets)) {
                continue;
            }
            $list = [];
            foreach ($targets as $target) {
                $target = is_string($target) ? trim($target) : '';
                if ($target !== '') {
                    $list[] = $target;
                }
            }
            $out[$uid] = $list;
        }

        return $out;
    }

    /** @param array<string,list<string>> $sources */
    private function storeTargetMap(string $key, array $sources): void
    {
        if ($sources === []) {
            $this->config->deleteAppValue('pages', $key);
            return;
        }
        $this->config->setAppValue(
            'pages',
            $key,
            json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * The preview directory the bridge should use, derived from the work root this app pushes.
     *
     * It is the work root's sibling (`<work_root>.preview`), which is the same default the bridge
     * applies on its own. An install whose work root the app never set (a hand-written TOML) gets
     * an empty string: the field is then left out of the payload and the bridge keeps its own
     * default instead of the app inventing a host path it cannot know.
     */
    public function previewDir(): string
    {
        $global = $this->getGlobal();
        $workRoot = trim((string)($global['work_root'] ?? ''));
        if ($workRoot === '' || $workRoot[0] !== '/') {
            return '';
        }

        return rtrim($workRoot, '/') . self::PREVIEW_SUFFIX;
    }

    /**
     * The site object as it leaves the app: the author directory and the preview directory always
     * travel with it, so the bridge validates and reports the same values for every push (pairing,
     * the settings form and `occ pages:push` alike).
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    public function siteForBridge(array $site): array
    {
        $site['author_root'] = $this->getAuthorRoot();
        $preview = $this->previewDir();
        if ($preview !== '') {
            $site['preview_dir'] = $preview;
        }

        return $site;
    }

    private function getSecret(string $key): string
    {
        $stored = (string)$this->config->getAppValue('pages', $key, '');
        if ($stored === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable) {
            // decryption fails after a rotated instance secret, and for plaintext written by
            // `occ config:app:set`; in both cases the stored value is used as-is
            return $stored;
        }
    }

    /**
     * Drop every setting a full reset must not keep.
     *
     * What survives is the bridge URL and the pushed global parameters: the first is the only
     * setting the reset deliberately keeps (the bridge lives at the same address), and the second
     * describes host paths, not content - clearing them would leave the bridge unable to find the
     * Nextcloud data directory it was paired with. Everything that names content or grants access
     * goes back to the documented defaults: no site, no author sources, `tmp` as the author content
     * directory, `Admin` as the orphan owner, every signed-in user may author, only administrators
     * review and publish, and no secret at all (which is the UNPAIRED state). The initialised flag
     * goes with them as well: the repository it remembered is exactly what the reset deletes, so this
     * is the only place allowed to clear it.
     */
    public function resetConfiguration(): void
    {
        foreach ([
            self::KEY_SITE,
            self::KEY_REVISION,
            'sites_pushed_revision',
            self::KEY_AUTHOR_AREA,
            self::KEY_AUTHOR_ROOT,
            self::KEY_ORPHAN_OWNER,
            self::KEY_AUTHOR_GROUPS,
            self::KEY_AUTHOR_SOURCES,
            self::KEY_AUTHOR_SOURCES_ONSITE,
            self::KEY_REVIEW_GROUPS,
            self::KEY_PUBLISH_GROUPS,
            self::KEY_EDIT_GROUPS,
            self::KEY_LAST_PUSH_FAILURE,
            self::KEY_PUBLISH_PUSH_FAILURE,
            self::KEY_REPO_INITIALISED,
        ] as $key) {
            $this->config->deleteAppValue('pages', $key);
        }
        $this->setSharedSecret('');
        $this->setSigningSecret('');
    }

    /**
     * Remember what a retry of the bridge cleanup needs: its address and the secret it accepted.
     *
     * @param string $url          the bridge the reset should clean up
     * @param string $sharedSecret the secret that was valid at that moment ('' when there was none)
     */
    public function setResetPending(string $url, string $sharedSecret): void
    {
        $this->config->setAppValue('pages', self::KEY_RESET_PENDING, json_encode([
            'at' => time(),
            'url' => $url,
            'shared_secret' => $sharedSecret === '' ? '' : $this->crypto->encrypt($sharedSecret),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The pending bridge cleanup, or null when the bridge confirmed it (or no reset ran).
     *
     * @return array{at:int,url:string,secret:string}|null
     */
    public function getResetPending(): ?array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_RESET_PENDING, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        $secret = (string)($decoded['shared_secret'] ?? '');
        if ($secret !== '') {
            try {
                $secret = $this->crypto->decrypt($secret);
            } catch (\Throwable) {
                // a rotated instance secret: the stored value is the plaintext fallback, exactly
                // like getSecret() reads it
            }
        }

        return [
            'at' => (int)($decoded['at'] ?? 0),
            'url' => (string)($decoded['url'] ?? ''),
            'secret' => $secret,
        ];
    }

    /** Clear the pending bridge cleanup (the bridge confirmed it). */
    public function clearResetPending(): void
    {
        $this->config->deleteAppValue('pages', self::KEY_RESET_PENDING);
    }

    private function setSecret(string $key, string $plain): void
    {
        if (!in_array($key, self::SECRET_KEYS, true)) {
            throw new \InvalidArgumentException('Not a secret field: ' . $key);
        }
        $this->config->setAppValue('pages', $key, $plain === '' ? '' : $this->crypto->encrypt($plain));
    }

    /**
     * Group grants live in the same appconfig as the bridge settings, as a JSON array of group ids.
     * Anything that is not a JSON array (unset, broken JSON, an object) reads as "no groups": a
     * hand-edited value must never widen access by accident.
     *
     * @return list<string>
     */
    private function getGroupList(string $key): array
    {
        $raw = (string)$this->config->getAppValue('pages', $key, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        return $this->normalizeGroups($decoded);
    }

    /** @param array<int,mixed> $gids */
    private function setGroupList(string $key, array $gids): void
    {
        $this->config->setAppValue(
            'pages',
            $key,
            json_encode($this->normalizeGroups($gids), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Trims entries, drops everything that is not a non-empty string and deduplicates, so that the
     * stored grant is exactly the set of groups the administrator picked.
     *
     * @param array<int,mixed> $gids
     * @return list<string>
     */
    private function normalizeGroups(array $gids): array
    {
        $out = [];
        foreach ($gids as $gid) {
            // group ids are strings; a number or an array is not a group name
            if (!is_string($gid)) {
                continue;
            }
            $gid = trim($gid);
            if ($gid === '' || in_array($gid, $out, true)) {
                continue;
            }
            $out[] = $gid;
        }
        return $out;
    }

    /**
     * The one site of this instance, or null while it has not been configured yet.
     *
     * The site is one JSON object under KEY_SITE; a list or any other shape is a hand-edited value and
     * is not a site.
     *
     * @return array<string,mixed>|null
     */
    public function getSite(): ?array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_SITE, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        // a list here would be a hand-edited value: the single site is always a JSON object
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        return $this->fillDefaults($this->dropRemovedFields($decoded));
    }

    /**
     * The site as a list of zero or one entries. Kept because a few call sites count the configured
     * sites; the push payload itself is a single object (`site`) since protocol 3.
     *
     * @return list<array<string,mixed>>
     */
    public function getSites(): array
    {
        $site = $this->getSite();
        return $site === null ? [] : [$site];
    }

    /**
     * Drops the fields that only existed while the app could hold several sites: a stale id, name or
     * working copy must never turn into a route parameter, a title or a pushed field again.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function dropRemovedFields(array $site): array
    {
        unset($site['id'], $site['name'], $site['work_path']);
        return $site;
    }

    /**
     * Fills in defaults on read: sites stored by older versions lack the newer fields, and only
     * filling them on the write path would leave the UI without them until the next save.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function fillDefaults(array $site): array
    {
        if (!is_array($site['new_file'] ?? null)) {
            $site['new_file'] = ['templates' => self::DEFAULT_NEW_FILE_TEMPLATES];
        } elseif (!is_array($site['new_file']['templates'] ?? null) || $site['new_file']['templates'] === []) {
            $site['new_file']['templates'] = self::DEFAULT_NEW_FILE_TEMPLATES;
        }
        return $site;
    }

    /**
     * The site as a non-administrator may see it: the folder name decides the title, and no field
     * that describes the repository, the paths or the build recipe leaves the server. The preview
     * routes are the only way to see the site itself, and they need nothing from this payload.
     *
     * @param array<string,mixed> $site
     * @return array{display:string,connected:bool,revision:int}
     */
    public function trimSite(array $site): array
    {
        // the display name is the site folder name only: an owner/repo label would hand out the git
        // remote, and there is no stored name or id left to fall back to
        $ncPath = rtrim((string)($site['nc_path'] ?? ''), '/');
        $display = $ncPath === '' ? '' : basename($ncPath);
        return [
            'display' => $display,
            'connected' => $this->isConnected(),
            'revision' => $this->getRevision(),
        ];
    }

    /**
     * Stores the one site and bumps the revision, which tells the bridge that this config is newer
     * and drives the "unpushed changes" flag in the UI.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    public function setSite(array $site): array
    {
        $site = $this->normalize($site);
        $this->persist($site);
        return $site;
    }

    /** @param array<string,mixed> $site */
    private function persist(array $site): void
    {
        $this->config->setAppValue('pages', self::KEY_SITE, json_encode($site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->config->setAppValue('pages', self::KEY_REVISION, (string)$this->nextRevision());
    }

    /**
     * Drop the one site. Only the rollback of a write-through that never reached the bridge uses it:
     * a first configuration that the backend refused must leave "no site" behind, not an empty one.
     */
    public function forgetSite(): void
    {
        $this->config->deleteAppValue('pages', self::KEY_SITE);
    }

    /**
     * Put the revision marker back after a write-through was rolled back.
     *
     * The store bumps the revision when it stores a site, so a rejected push would otherwise leave the
     * counter ahead of what the bridge holds - the next doctor run would report "out of sync" for a
     * configuration that never changed.
     */
    public function restoreRevision(int $revision): void
    {
        $this->config->setAppValue('pages', self::KEY_REVISION, (string)$revision);
    }

    public function getRevision(): int
    {
        return (int)$this->config->getAppValue('pages', self::KEY_REVISION, '0');
    }

    /** Timestamp-based revision: no collisions across edits or processes, no read-modify-write. */
    private function nextRevision(): int
    {
        return max(time(), $this->getRevision() + 1);
    }

    public function getPushedRevision(): int
    {
        return (int)$this->config->getAppValue('pages', 'sites_pushed_revision', '0');
    }

    public function markPushed(int $revision): void
    {
        $this->config->setAppValue('pages', 'sites_pushed_revision', (string)$revision);
    }

    public function hasUnpushedChanges(): bool
    {
        return $this->getRevision() > $this->getPushedRevision();
    }

    /**
     * The sticky answer to "does the publish repository exist", for when the bridge cannot be asked.
     *
     * Only `'1'` is a yes: an unset value (fresh install), a hand-edited one or a leftover from a
     * version that spelled it differently must never lock the two fields on their own.
     */
    public function isRepoInitialised(): bool
    {
        return $this->config->getAppValue('pages', self::KEY_REPO_INITIALISED, '0') === '1';
    }

    /**
     * Remembers that the repository exists, so the remote/branch lock survives an unreachable bridge.
     *
     * Idempotent on purpose: the steady state is one appconfig write, ever - every save and every
     * status call goes through here, and none of them may rewrite the value.
     */
    public function markRepoInitialised(): void
    {
        if (!$this->isRepoInitialised()) {
            $this->config->setAppValue('pages', self::KEY_REPO_INITIALISED, '1');
        }
    }

    /**
     * Records the last push that failed, or clears it with `null` after one the bridge accepted.
     *
     * The timestamp is taken here so that every writer agrees on the format, and a caller cannot
     * report a moment it invented. Only the fields the doctor prints are kept - a growing history would
     * be the queue this app deliberately does not have.
     *
     * @param array{author:string,code:string,message:string,retryable:bool}|null $failure
     */
    public function recordPushFailure(?array $failure): void
    {
        if ($failure === null) {
            $this->config->deleteAppValue('pages', self::KEY_LAST_PUSH_FAILURE);

            return;
        }
        $this->config->setAppValue('pages', self::KEY_LAST_PUSH_FAILURE, json_encode([
            'ts' => time(),
            'author' => (string)($failure['author'] ?? ''),
            'code' => (string)($failure['code'] ?? 'ERROR'),
            'message' => (string)($failure['message'] ?? ''),
            'retryable' => (bool)($failure['retryable'] ?? false),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The record above, or null when the last push was accepted (or none failed since this key
     * existed). A hand-edited value that is not an object reads as "no failure", never as an error the
     * doctor would then misreport.
     *
     * @return array{ts:int,author:string,code:string,message:string,retryable:bool}|null
     */
    public function getLastPushFailure(): ?array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_LAST_PUSH_FAILURE, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return [
            'ts' => (int)($decoded['ts'] ?? 0),
            'author' => (string)($decoded['author'] ?? ''),
            'code' => (string)($decoded['code'] ?? 'ERROR'),
            'message' => (string)($decoded['message'] ?? ''),
            'retryable' => (bool)($decoded['retryable'] ?? false),
        ];
    }

    /**
     * Record (or clear, with `null`) the push failure of the last publish.
     *
     * @param array{code:string,ahead:int|null,commit:string,message:string}|null $failure
     */
    public function recordPublishPushFailure(?array $failure): void
    {
        if ($failure === null) {
            $this->config->deleteAppValue('pages', self::KEY_PUBLISH_PUSH_FAILURE);

            return;
        }
        $this->config->setAppValue('pages', self::KEY_PUBLISH_PUSH_FAILURE, json_encode([
            'ts' => time(),
            'code' => (string)($failure['code'] ?? 'PUSH_FAILED'),
            'ahead' => $failure['ahead'] === null ? null : (int)$failure['ahead'],
            'commit' => (string)($failure['commit'] ?? ''),
            'message' => (string)($failure['message'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The record above, or null when the last publish was not a failed push.
     *
     * @return array{ts:int,code:string,ahead:int|null,commit:string,message:string}|null
     */
    public function getPublishPushFailure(): ?array
    {
        $raw = (string)$this->config->getAppValue('pages', self::KEY_PUBLISH_PUSH_FAILURE, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return [
            'ts' => (int)($decoded['ts'] ?? 0),
            'code' => (string)($decoded['code'] ?? 'PUSH_FAILED'),
            'ahead' => isset($decoded['ahead']) ? (int)$decoded['ahead'] : null,
            'commit' => (string)($decoded['commit'] ?? ''),
            'message' => (string)($decoded['message'] ?? ''),
        ];
    }

    /**
     * Fills in defaults and validates locally; the authoritative safety checks (source and working
     * copy must not contain each other, working copy must not sit in the data directory) run on the
     * bridge with the same rules. The working copy is a global bridge setting now, not a site field,
     * so the app neither stores nor validates a work_path any more.
     *
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    public function normalize(array $site): array
    {
        $ncPath = trim((string)($site['nc_path'] ?? ''));
        if ($ncPath === '' || $ncPath[0] !== '/') {
            throw new \InvalidArgumentException($this->l10n->t('nc_path must start with / (it is relative to the Nextcloud user root)'));
        }
        // The remote is optional: a publish is the commit itself, so a site without a remote is a
        // supported configuration ("commit only"). An empty value is a stored value, not "unchanged".
        $repoUrl = trim((string)($site['repo_url'] ?? ''));

        $recipe = is_array($site['recipe'] ?? null) ? $site['recipe'] : [];
        // Jekyll is the only generator this app drives: an install that still stores "auto" (or
        // nothing) keeps working, and any other value is refused instead of quietly mis-building.
        $preset = strtolower(trim((string)($recipe['preset'] ?? 'jekyll')));
        if ($preset === '' || $preset === 'auto') {
            $preset = 'jekyll';
        }
        if ($preset !== 'jekyll') {
            // The form never sends a preset, so one save rewrites a legacy value (hugo, plain, …) to
            // jekyll; a site that is not Jekyll can still be built through the command field.
            throw new \InvalidArgumentException($this->l10n->t('Only the Jekyll preset is supported (save the site to switch it, or set a build command)'));
        }

        // no id, no name, no work_path: the app is single-site, the title is basename(nc_path) and
        // the working copy belongs to the bridge (see the class comment)
        return [
            'nc_user' => trim((string)($site['nc_user'] ?? '')),
            'nc_path' => '/' . trim($ncPath, '/'),
            'repo_url' => $repoUrl,
            'branch' => trim((string)($site['branch'] ?? 'main')) ?: 'main',
            'remote_name' => trim((string)($site['remote_name'] ?? 'origin')) ?: 'origin',
            'exclude' => array_values(array_filter(
                array_map('strval', is_array($site['exclude'] ?? null) ? $site['exclude'] : []),
                static fn (string $v): bool => $v !== '',
            )),
            'recipe' => array_filter([
                'preset' => $preset,
                'output_dir' => trim((string)($recipe['output_dir'] ?? '')) ?: null,
                'command' => $this->stringList($recipe['command'] ?? null),
                'install_cmd' => $this->stringList($recipe['install_cmd'] ?? null),
                'env' => is_array($recipe['env'] ?? null) ? $recipe['env'] : [],
                'overrides' => is_array($recipe['overrides'] ?? null) ? $recipe['overrides'] : [],
            ], static fn ($v): bool => $v !== null),
            'new_file' => [
                'templates' => $this->normalizeTemplates($site['new_file']['templates'] ?? null),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeTemplates(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return self::DEFAULT_NEW_FILE_TEMPLATES;
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = strtolower(trim((string)($item['id'] ?? '')));
            $label = trim((string)($item['label'] ?? ''));
            $path = trim((string)($item['path'] ?? ''));
            if ($id === '' || $label === '') {
                continue;   // templates without id/label are unusable in the UI, drop them
            }
            $entry = [
                'id' => $id,
                'label' => $label,
                'path' => $path,
                'content' => (string)($item['content'] ?? ''),
            ];
            $when = $this->stringList($item['when_present'] ?? null);
            if ($when !== null) {
                $entry['when_present'] = $when;
            }
            $out[] = $entry;
        }
        return $out === [] ? self::DEFAULT_NEW_FILE_TEMPLATES : $out;
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            $out = array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
            return $out === [] ? null : $out;
        }
        return null;
    }
}
