<?php
/**
 * App shell: the mount point, the initial state for the front end, and a no-JS hint.
 *
 * The controller hands a full state to administrators and only {uid, canAuthor, canReview, canEdit,
 * canPublish, isAdmin, site} to everybody else — and trims that one site down to {display, connected,
 * revision}. A key is emitted only when it was actually handed over, so a trimmed page carries no
 * empty bridgeUrl placeholders that would still describe the configuration.
 *
 * @var array $_
 */
$state = [
    // who is asking: the front end uses it to mark the caller's own entries in the list. The decision
    // rules do not look at the owner: a reviewer may decide about any entry, their own included, and
    // `decidedBy` records who answered.
    'uid' => (string)($_['uid'] ?? ''),
    // the two grants, as the server decided them: authoring, and 审核与发布 (reviewing and publishing
    // are one permission, so `canReview`, `canEdit` and `canPublish` all report it under the names the
    // pages already use - the keys stay so a cached shell cannot break the front end)
    'canAuthor' => $_['canAuthor'] ?? false,
    'canReview' => $_['canReview'] ?? false,
    'canEdit' => $_['canEdit'] ?? false,
    'canPublish' => $_['canPublish'] ?? false,
    'isAdmin' => $_['isAdmin'] ?? false,
    // single site: one object, never a list (no id, no name — the title is basename(nc_path))
    'site' => is_array($_['site'] ?? null) ? $_['site'] : [],
];
if (array_key_exists('bridgeUrl', $_)) {
    // administrators only: the config revision and the bridge connection metadata
    $state += [
        'revision' => $_['revision'],
        'pushedRevision' => $_['pushedRevision'] ?? 0,
        'bridgeUrl' => $_['bridgeUrl'] ?? '',
        'connected' => $_['connected'] ?? false,
        'protocol' => $_['protocol'] ?? 0,
    ];
}
// printed unescaped below, so the JSON_HEX_* flags are what keeps a value from closing the script tag
$json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<div id="pages-app" class="pages-app">
    <div class="pages-loading">
        <span class="icon-loading-small"></span>
        <?php p($l->t('Loading Pages…')); ?>
    </div>
</div>

<script type="application/json" id="pages-initial-state"><?php print_unescaped($json); ?></script>

<noscript>
    <div class="pages-noscript">
        <?php p($l->t('Pages requires JavaScript.')); ?>
    </div>
</noscript>
