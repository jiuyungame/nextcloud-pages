<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCP\IL10N;

/**
 * Whether this instance already holds content, and therefore pins the site layout.
 *
 * Two settings decide *where* content lives rather than *what* it is: the site storage user and the
 * site folder. Moving either once content exists would leave the visibility manifest and the bridge's
 * trees pointing at paths that no longer hold the files they describe - the app has no reliable way to
 * follow such a move for content it does not own. So the two are locked as soon as content is
 * registered, and only a reset unlocks them.
 *
 * The author content directory is deliberately not one of them: a move is supported, because the
 * manifest keys can be re-keyed with the new directory and the bridge is re-synced (the move rebuilds
 * the commit and review trees, and the one-shot overwrite rewrites the preview tree's author area), so
 * the setting stays editable at any time.
 *
 * "Content exists" is answered from data the app already owns, never from a filesystem walk of the
 * whole site: the visibility manifest (`entries` non-empty), or a target the site serves / waits for
 * (`online/`, `pending/`). The pushed target list this used to consult is gone - author content is
 * event-driven and no app-side list of mirror targets survives a save.
 *
 * A read failure of the manifest is not a reason to unlock: `ReviewStore::read()` already answers the
 * empty manifest for a missing/unreadable file, and the app-wide rule is "no content, no lock" only
 * for a demonstrably empty state.
 *
 * The same rule applies to a content directory the bridge could not confirm: the Files API fallback
 * answering "nothing is live / nothing waits" is not a demonstration that the instance is empty, so
 * the fields stay locked and a save that would change them answers 409 (`BRIDGE_UNKNOWN`) until the
 * bridge can confirm the state.
 */
final class ContentLock
{
    /** The one sentence the settings page shows next to a locked field. */
    public const MESSAGE = 'Locked: content exists. Reset Pages to change this.';

    /** The sentence shown while the bridge cannot confirm whether content exists. */
    public const MESSAGE_UNKNOWN = 'Locked: the site content cannot be confirmed right now. Try again later.';

    public function __construct(
        private ReviewStore $store,
        private SiteRegistry $registry,
        private IL10N $l10n,
    ) {
    }

    /**
     * The facts behind the answer, for the settings template and `occ pages:doctor`.
     *
     * `mirrored` is always 0 now that the pushed target list is gone; the key stays in the shape so
     * the template and any external reader of this method keep finding it. `trusted` is false while
     * the content directories came from the Files API fallback: `locked` is then fail-closed, because
     * "nothing is live" could be a filecache that has not caught up.
     *
     * @return array{locked:bool,manifest:int,mirrored:int,trusted:bool}
     */
    public function state(): array
    {
        $manifest = $this->store->read();
        $counts = $this->store->counts();
        $trusted = $this->store->treesTrusted();

        return [
            'locked' => $manifest['entries'] !== [] || $counts['online'] > 0 || $counts['pending'] > 0 || !$trusted,
            'manifest' => count($manifest['entries']),
            'mirrored' => 0,
            'trusted' => $trusted,
        ];
    }

    public function isLocked(): bool
    {
        return $this->state()['locked'];
    }

    /** The hint next to a locked field, translated. */
    public function message(): string
    {
        return $this->l10n->t(
            $this->store->treesTrusted() ? self::MESSAGE : self::MESSAGE_UNKNOWN,
        );
    }

    /**
     * Refuse a change of one of the locked layout fields (the site storage user, the site folder),
     * while it is really a change. The author content directory is not passed through here: it is
     * movable at any time (see the class comment).
     *
     * A request that repeats the stored value is accepted: the settings page sends the whole site
     * block on every write-through, so editing the branch must not be refused because the folder it
     * carries is the same one it already has.
     *
     * A real change while the content directories cannot be confirmed is a 409 (`BRIDGE_UNKNOWN`),
     * not a 400: nothing about the caller's input is wrong, and the same request succeeds once the
     * bridge answers.
     *
     * @param string $label the field as the administrator sees it
     * @param mixed  $before the stored value
     * @param mixed  $after  the value the request carries
     * @throws \InvalidArgumentException when the field would change while content exists
     * @throws ConflictException when the content directories cannot be confirmed
     */
    public function rejectChange(string $label, mixed $before, mixed $after): void
    {
        if ((string)$before === (string)$after) {
            return;
        }
        $this->store->requireTrustedTrees();
        if (!$this->isLocked()) {
            return;
        }
        throw new \InvalidArgumentException($this->l10n->t(
            '%1$s cannot be changed while content exists. Reset Pages first (Danger zone).',
            [$label],
        ));
    }
}
