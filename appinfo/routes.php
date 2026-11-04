<?php

declare(strict_types=1);

/**
 * Route table. Every `/api/*` route requires a logged-in session, and all but a few of them are
 * additionally gated per controller method by the capability of the caller (author / review-and-publish
 * / admin; see BaseController::siteContext()); `/api/site` is readable by any signed-in user with a
 * trimmed payload, the shell `/` and `/api/site/preview-token` need no capability either, and
 * `/preview/{token}/{path}` is gated by a short-lived signed token.
 *
 * No route carries a site id: this app has exactly one site, and the bridge does not take one either
 * any more (protocol v3 serves that single site under `/site/...`).
 */

return [
    'routes' => [
        // page
        //
        // One page, three views (edit / requests / read): the caller's own author area, the structured
        // editor, the visibility requests, publishing and reading, for every signed-in user. The app
        // no longer edits the site's own source folder - that folder's writers are the Files app /
        // WebDAV, Import and Pull, and its content reaches the site through the publish pipeline.
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // admin config endpoints, admin only (the controller has no NoAdminRequired)
        ['name' => 'adminConfig#show', 'url' => '/api/admin/config', 'verb' => 'GET'],
        ['name' => 'adminConfig#update', 'url' => '/api/admin/config', 'verb' => 'PUT'],
        // reset Pages back to a fresh install: admin only and password-confirmed, because it removes
        // the configuration (the site folder and every author's files are never touched)
        ['name' => 'reset#reset', 'url' => '/api/admin/reset', 'verb' => 'POST'],

        // the single site and its files
        ['name' => 'site#list', 'url' => '/api/site', 'verb' => 'GET'],
        // pairing happens before a working config exists: the bridge is in its UNPAIRED state and
        // only /health and /pair answer (the app talks to the URL from the request, not from config)
        ['name' => 'site#pair', 'url' => '/api/bridge/pair', 'verb' => 'POST'],
        ['name' => 'site#bridgeHealth', 'url' => '/api/bridge/health', 'verb' => 'GET'],
        // a bare liveness probe: no session, no CSRF token, no signature and no side effect, so systemd,
        // a monitor or a curl one-liner can ask "is the companion service there" without a credential
        ['name' => 'site#ping', 'url' => '/api/bridge/ping', 'verb' => 'GET'],
        ['name' => 'site#pushConfig', 'url' => '/api/bridge/push', 'verb' => 'POST'],
        // the author-scoped retry of a save whose push failed: same push the save path runs, capped at
        // the caller's own grant, addressed by nothing (the author is the caller)
        ['name' => 'site#pushAuthorSources', 'url' => '/api/site/push', 'verb' => 'POST'],
        ['name' => 'site#save', 'url' => '/api/site', 'verb' => 'PUT'],
        // no DELETE: the one site of this instance cannot be removed, only reconfigured. Moving the
        // source to another "site storage user" is locked once content exists and only the reset
        // unlocks it (see ContentLock), so the retired preflight/migrate pair has no route any more.
        // initialise the publish repository: the bridge creates it and fetches the remote's refs, and
        // never checks anything out - Pull is the route that replaces the folder. Only it can run git.
        ['name' => 'site#init', 'url' => '/api/site/init', 'verb' => 'POST'],
        // the author content directory's own channel: the bridge is the one writer of that value
        ['name' => 'site#setAuthorRoot', 'url' => '/api/site/author-root', 'verb' => 'POST'],
        // Pull: what the confirmation needs to say first (the ahead count and the number of
        // unpublished local files), then the replacement itself
        ['name' => 'site#pullPreview', 'url' => '/api/site/pull/preview', 'verb' => 'GET'],
        ['name' => 'site#pull', 'url' => '/api/site/pull', 'verb' => 'POST'],
        // Import: a zip/tgz replaces the site folder through the Nextcloud file API (admin only)
        ['name' => 'site#importSource', 'url' => '/api/site/source/import', 'verb' => 'POST'],

        ['name' => 'browse#tree', 'url' => '/api/site/tree', 'verb' => 'GET'],
        // the author area's own file API: every one of these addresses a target of the caller's
        // `<author_root>/<uid>/…` area, never a file of the site's source folder
        ['name' => 'browse#read', 'url' => '/api/site/file', 'verb' => 'GET'],
        ['name' => 'browse#write', 'url' => '/api/site/file', 'verb' => 'PUT'],
        ['name' => 'browse#create', 'url' => '/api/site/file', 'verb' => 'POST'],
        ['name' => 'browse#delete', 'url' => '/api/site/file', 'verb' => 'DELETE'],
        // folders get their own route: POST /file means "create file"
        ['name' => 'browse#makeFolder', 'url' => '/api/site/folder', 'verb' => 'POST'],
        // rename/move likewise: an existing target must be rejected here
        ['name' => 'browse#move', 'url' => '/api/site/move', 'verb' => 'POST'],

        // the community view: reading articles needs no capability (like the preview), so these are
        // reachable for every signed-in user
        ['name' => 'article#index', 'url' => '/api/articles', 'verb' => 'GET'],
        ['name' => 'article#mine', 'url' => '/api/articles/mine', 'verb' => 'GET'],
        ['name' => 'article#read', 'url' => '/api/article', 'verb' => 'GET'],
        ['name' => 'article#tags', 'url' => '/api/tags', 'verb' => 'GET'],
        // the community features around an article: one vote per reader (changeable, withdrawable) and
        // the comment thread, moderated by the review grant. Both are open to every signed-in user,
        // like reading; the article's own visibility rules are applied by the index behind them.
        ['name' => 'article#vote', 'url' => '/api/article/vote', 'verb' => 'POST'],
        ['name' => 'article#comments', 'url' => '/api/article/comments', 'verb' => 'GET'],
        ['name' => 'article#addComment', 'url' => '/api/article/comments', 'verb' => 'POST'],
        ['name' => 'article#deleteComment', 'url' => '/api/article/comments', 'verb' => 'DELETE'],
        // the article editor: the body as text, the front matter as fields. These two exist next to
        // browse#read/browse#write on purpose - the whole-file routes stay for an editor who has to fix
        // something the fields panel does not model, while an author's structured save goes through here,
        // where the server merges only the named fields into the file and keeps every other key
        // (layout, permalink, categories, …) exactly where it was.
        ['name' => 'browse#articleContent', 'url' => '/api/article/content', 'verb' => 'GET'],
        ['name' => 'browse#articleSave', 'url' => '/api/article/content', 'verb' => 'PUT'],
        // the author status table of the admin panel (review-and-publish grant, see the controller)
        ['name' => 'author#sources', 'url' => '/api/author/sources', 'verb' => 'GET'],

        // the visibility workflow: a page is internal until a reviewer approves it for the site.
        // Four actions - ask (request), withdraw (cancel), the reviewer's answer (decision), and the
        // reviewer's own take-down of a page with nothing pending (take-down). An online request is
        // what asks for content to go out, including the new content of an online page: the bridge
        // freezes the bytes it names, so there is no separate update-request route any more. The
        // pending requests are read as the `requested` entries of /api/articles, and publishing stays
        // at /api/site/publish: it stages everything the site has a version of and the removals.
        // Reviewing and publishing are one permission (审核与发布): whoever decides may publish.
        ['name' => 'review#request', 'url' => '/api/article/request', 'verb' => 'POST'],
        ['name' => 'review#cancel', 'url' => '/api/article/cancel', 'verb' => 'POST'],
        ['name' => 'review#decision', 'url' => '/api/article/decision', 'verb' => 'POST'],
        ['name' => 'review#takeDown', 'url' => '/api/article/take-down', 'verb' => 'POST'],

        // build and publish
        ['name' => 'build#status', 'url' => '/api/site/status', 'verb' => 'GET'],
        ['name' => 'build#build', 'url' => '/api/site/build', 'verb' => 'POST'],
        ['name' => 'build#notify', 'url' => '/api/site/notify', 'verb' => 'POST'],
        ['name' => 'build#publish', 'url' => '/api/site/publish', 'verb' => 'POST'],
        ['name' => 'build#log', 'url' => '/api/site/log', 'verb' => 'GET'],

        // preview: issue a short-lived token, and proxy the build output
        //
        // the URL segment is deliberately not "session": that route never matched
        ['name' => 'preview#session', 'url' => '/api/site/preview-token', 'verb' => 'POST'],
        // path needs `.*` rather than `.+`: <a href="/"> becomes <base>/ with no path at all,
        // and the controller normalises an empty path to index.html
        ['name' => 'preview#serve', 'url' => '/preview/{token}/{path}', 'verb' => 'GET',
         'requirements' => ['path' => '.*']],
    ],
];
