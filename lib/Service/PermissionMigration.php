<?php

declare(strict_types=1);

namespace OCA\Pages\Service;

use OCA\Pages\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * One-shot migrations of the permission model, in the order the grants collapsed.
 *
 * **v2 — editing into publishing.** The app used to hand out two grants, `edit_groups` and
 * `publish_groups`. They collapsed into one capability - publishing carries the site's own files, the
 * build and the push - so whatever the old edit grant named has to end up in the publish grant.
 * Nobody may gain or lose anything by the merge, which is why the lists are unioned instead of
 * replaced: a group that held both stays once, and a group that held only editing now holds
 * publishing.
 *
 * **v3 — publishing into review** ("把发布合并到审核权里"). One permission now means 审核与发布:
 * deciding about visibility requests **and** publishing them. The members of the old publish group
 * are added to the review group, so nobody loses the ability to publish, and the publish grant is
 * then cleared: the setting stops being read. The two lists are unioned in a defined order (the
 * stored review groups first, so the settings form keeps the order the administrator saw; the publish
 * members follow), deduplicated by the registry's normaliser.
 *
 * Both migrations are idempotent by an appconfig flag, not by inspecting the values: once the flag is
 * set the check is a single appconfig read and nothing is written again, so every request (and every
 * restart) can call run() safely. A hand-edited retired key written after a migration is therefore
 * left alone - it grants nothing any more, and silently re-merging it would move a grant nobody can
 * see in the settings any more.
 *
 * The v3 merge writes a **snapshot before it changes anything**: what the two lists held, what the
 * merged pair is, and when it happened, under {@see self::SNAPSHOT_KEY}. Clearing the retired list is
 * exactly what makes a rollback need that record, and an audit can read it later without guessing.
 */
final class PermissionMigration
{
    /** The appconfig key that records that the edit→publish merge has run. */
    public const FLAG = 'permissions_v2';

    /** The appconfig key that records that the publish→review merge has run. */
    public const REVIEW_FLAG = 'permissions_v3';

    /** Where the publish→review merge records what it found before it changed anything. */
    public const SNAPSHOT_KEY = 'permissions_v3_snapshot';

    public function __construct(
        private SiteRegistry $registry,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /** Runs both merges once each, in order, then only reads the two flags. */
    public function run(): void
    {
        // v2 first: whatever it moves into the publish grant must be part of the v3 merge below, or a
        // fresh install upgrading from the oldest shape would drop the edit members on the floor
        $this->mergeEditIntoPublish();
        $this->mergePublishIntoReview();
    }

    /**
     * v2: the retired edit grant folded into publishing.
     *
     * @see self::mergePublishIntoReview() for the same shape one level up
     */
    private function mergeEditIntoPublish(): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::FLAG, '') === '1') {
            return;
        }

        $editGroups = $this->registry->getEditGroups();
        if ($editGroups !== []) {
            $publishGroups = $this->registry->getPublishGroups();
            // union, publishing first: the stored order is what the settings form shows, and a group
            // that already published keeps its place; setPublishGroups() normalises and deduplicates
            $this->registry->setPublishGroups(array_merge($publishGroups, $editGroups));
            $this->registry->setEditGroups([]);
            $this->logger->info(
                'pages: merged the retired edit grant into the publishing grant and cleared it',
                ['edit_groups' => $editGroups, 'publish_groups_before' => $publishGroups],
            );
        }

        // written even when there was nothing to merge: looking at a retired key on every request is
        // exactly what this flag exists to stop
        $this->config->setAppValue(Application::APP_ID, self::FLAG, '1');
    }

    /**
     * v3: the retired publish grant folded into the review grant, which is now the single
     * 审核与发布 permission.
     *
     * The snapshot is written before the first change, the union is stored, the retired list is
     * cleared, and only then is the flag set: a run that dies halfway leaves the flag unset, and the
     * next request repeats the whole (idempotent) merge from whatever it finds.
     */
    private function mergePublishIntoReview(): void
    {
        if ($this->config->getAppValue(Application::APP_ID, self::REVIEW_FLAG, '') === '1') {
            return;
        }

        $publishGroups = $this->registry->getPublishGroups();
        $reviewGroups = $this->registry->getReviewGroups();
        $merged = $this->registry->mergeReviewGroups($publishGroups);

        $snapshot = [
            'at' => time(),
            'publish_groups' => $publishGroups,
            'review_groups_before' => $reviewGroups,
            'review_groups_after' => $merged,
        ];
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->config->setAppValue(Application::APP_ID, self::SNAPSHOT_KEY, $encoded === false ? '{}' : $encoded);

        if ($publishGroups !== [] || $reviewGroups !== $merged) {
            $this->logger->info(
                'pages: merged the retired publish grant into the review grant (审核与发布) and cleared it',
                $snapshot,
            );
        }

        // the retired setting stops being read from here on: nothing may hold a publish grant that
        // the settings page cannot show any more
        $this->registry->setPublishGroups([]);

        $this->config->setAppValue(Application::APP_ID, self::REVIEW_FLAG, '1');
    }
}
