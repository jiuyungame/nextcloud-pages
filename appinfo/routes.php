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
        // Two pages, one application: the content page (articles, review, reading) is for every
        // signed-in user; the source page (Jekyll tree, whole-file editor, build and publish) is the
        // work of an editor or a publisher. One page for both left authors with an empty navigation
        // column and mixed the site's own files into what they were reading.
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#source', 'url' => '/source', 'verb' => 'GET'],

        // admin config endpoints, admin only (the controller has no NoAdminRequired)
        ['name' => 'adminConfig#show', 'url' => '/api/admin/config', 'verb' => 'GET'],
        ['name' => 'adminConfig#update', 'url' => '/api/admin/config', 'verb' => 'PUT'],

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
        // no DELETE: the one site of this instance cannot be removed, only reconfigured
        // moving the source to another "site storage user" (§14): admin only, explicit, never implicit
        // in a save — preflight reports what would happen, migrate performs it step by step
        ['name' => 'site#preflight', 'url' => '/api/admin/site/preflight', 'verb' => 'POST'],
        ['name' => 'site#migrate', 'url' => '/api/admin/site/migrate', 'verb' => 'POST'],
        // first git pull / fetch from the remote: the bridge does both, only it can run git
        ['name' => 'site#init', 'url' => '/api/site/init', 'verb' => 'POST'],
        ['name' => 'site#pull', 'url' => '/api/site/pull', 'verb' => 'POST'],

        ['name' => 'browse#tree', 'url' => '/api/site/tree', 'verb' => 'GET'],
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
        // Exactly three actions - ask (request), withdraw (cancel), and the reviewer's answer
        // (decision). The pending requests are read as the `requested` entries of /api/articles, and
        // publishing stays at /api/site/publish: it stages everything external and the removals.
        // Reviewing and publishing are one permission (审核与发布): whoever decides may publish.
        ['name' => 'review#request', 'url' => '/api/article/request', 'verb' => 'POST'],
        ['name' => 'review#cancel', 'url' => '/api/article/cancel', 'verb' => 'POST'],
        ['name' => 'review#decision', 'url' => '/api/article/decision', 'verb' => 'POST'],

        // build and publish
        ['name' => 'build#status', 'url' => '/api/site/status', 'verb' => 'GET'],
        ['name' => 'build#build', 'url' => '/api/site/build', 'verb' => 'POST'],
        ['name' => 'build#notify', 'url' => '/api/site/notify', 'verb' => 'POST'],
        ['name' => 'build#diff', 'url' => '/api/site/diff', 'verb' => 'GET'],
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
