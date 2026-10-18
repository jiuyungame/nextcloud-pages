/**
 * Thin wrapper around the Pages backend API. Uses @nextcloud/axios, which sets the
 * requesttoken header needed for the CSRF check on write operations.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from './i18n.js'

const base = (path) => generateUrl('/apps/pages' + path)

async function call(promise) {
	try {
		const response = await promise
		return { ok: true, status: response.status, data: response.data }
	} catch (error) {
		const response = error?.response
		return {
			ok: false,
			status: response?.status ?? 0,
			data: response?.data ?? {},
			message: response?.data?.message || error?.message || t('pages', 'Request failed'),
		}
	}
}

/*
 * Single-site design: there is exactly one site, so no call site carries a site id any more
 * (the id is not part of the API, the URL, or the stored configuration).
 */
export const api = {
	site: () => call(axios.get(base('/api/site'))),

	/** Save the single site (admin only; the body is the site object itself, without id/name) */
	saveSite: (site) => call(axios.put(base('/api/site'), site)),

	/** First git pull / fetch from the remote: the bridge does both, only it can run git */
	init: () => call(axios.post(base('/api/site/init'))),
	pull: () => call(axios.post(base('/api/site/pull'))),

	/**
	 * The file tree of one scope. The scope is what the caller is looking at: `author` is the
	 * caller's own author area (the content page's column, for every user - the site's storage user
	 * and an administrator included), `site` is the whole site source (the source page). One scope
	 * never falls back to the other; the answer says which one it is and, for an author area, carries
	 * the `target_prefix` its nodes are built on.
	 */
	tree: (scope = '') => call(axios.get(base('/api/site/tree'), scope ? { params: { scope } } : {})),

	readFile: (path) => call(axios.get(base('/api/site/file'), { params: { path } })),

	writeFile: (path, content, baseEtag) =>
		call(axios.put(base('/api/site/file'), { path, content, baseEtag })),

	/**
	 * The same write for bytes: an image or another resource of the author's area is replaced
	 * through the whole-file endpoint, which takes the content base64-encoded for it.
	 */
	writeFileBytes: (path, contentBase64, baseEtag) =>
		call(axios.put(base('/api/site/file'), { path, contentBase64, baseEtag })),

	createFile: (path, content) =>
		call(axios.post(base('/api/site/file'), { path, content })),

	createFolder: (path) =>
		call(axios.post(base('/api/site/folder'), { path })),

	/** Rename / move: two words for the same operation */
	move: (from, to) =>
		call(axios.post(base('/api/site/move'), { from, to })),

	deleteFile: (path) =>
		call(axios.delete(base('/api/site/file'), { params: { path } })),

	status: () => call(axios.get(base('/api/site/status'))),
	build: () => call(axios.post(base('/api/site/build'))),
	diff: () => call(axios.get(base('/api/site/diff'))),
	publish: (message) =>
		call(axios.post(base('/api/site/publish'), { message })),

	/**
	 * Repeat the configuration push a save could not deliver, for the caller's own author area.
	 *
	 * It is the same push the save path runs, addressed by nothing (the author is the caller), and it
	 * never commits: this is the config push, while POST /api/site/publish is the one that commits. The
	 * answer keeps HTTP 200 and reports `bridge.pushed`, exactly like a save, because "the file is saved
	 * and the mirror did not take it" is a state and not an HTTP error.
	 */
	pushAuthorSources: () => call(axios.post(base('/api/site/push'))),

	/*
	 * Articles and the visibility workflow. An article is addressed by its **target** — the path it has
	 * in the published site (`<author_root>/<uid>/<file>`) — and not by a file path inside the site folder.
	 */

	/** The caller's own articles, internal ones included */
	myArticles: () => call(axios.get(base('/api/articles/mine'))),

	/*
	 * The reading side: every article of the site, plus the community data around one of them. Reading
	 * needs no capability, so none of these is gated on the client either.
	 */

	/**
	 * The article list. An empty `tag` means every tag, `sort` is new|score|comments, and an empty
	 * `state` means every visibility state — the server filters, so a truncated list is still counted
	 * over the whole site rather than over the rows that happened to arrive. An unknown `state` is
	 * answered with HTTP 400 (not ignored), which is why the value comes from STATE_FILTERS.
	 */
	readingList: (tag = '', sort = 'new', state = '', external = false) =>
		call(axios.get(base('/api/articles'), { params: { tag, sort, state, external } })),

	/** Tag counts of the published articles, most used first */
	tagCounts: () => call(axios.get(base('/api/tags'))),

	/** One article of the reading list, by its target */
	readArticle: (path) => call(axios.get(base('/api/article'), { params: { path } })),

	/** The comments of one article, oldest first */
	comments: (path) => call(axios.get(base('/api/article/comments'), { params: { path } })),
	/** @param {string} path @param {string} body plain text, up to 2000 characters */
	addComment: (path, body) => call(axios.post(base('/api/article/comments'), { path, body })),

	/** @param {number} id the comment to delete (one's own, or any with the publish grant) */
	deleteComment: (id) => call(axios.delete(base('/api/article/comments'), { data: { id } })),

	/** @param {string} path @param {'up'|'down'|'none'} vote `'none'` withdraws the caller's vote */
	vote: (path, vote) => call(axios.post(base('/api/article/vote'), { path, vote })),

	/*
	 * The visibility workflow: a page is internal until a reviewer approves it for the site. Exactly
	 * three actions - ask (request), withdraw (cancel) and the reviewer's answer (decision) - plus the
	 * list of pending requests, which is the article list filtered to `state=requested`.
	 */

	/** @param {string} path article target @param {'online'|'offline'} intent the direction asked for */
	requestVisibility: (path, intent) =>
		call(axios.post(base('/api/article/request'), { path, intent })),

	/** Withdraw the pending request; the entry returns to where its intent started */
	cancelRequest: (path) => call(axios.post(base('/api/article/cancel'), { path })),

	/**
	 * @param {string} path article target
	 * @param {'approve'|'reject'} decision `approve` applies the intent, `reject` returns to internal
	 * @param {string} [note] why a request was rejected; the author sees it
	 */
	decideRequest: (path, decision, note = '') =>
		call(axios.post(base('/api/article/decision'), { path, decision, note })),

	/** The pending requests (every entry in `requested`), with their intent and note; 403 without the review grant */
	visibilityRequests: () => call(axios.get(base('/api/articles'), { params: { state: 'requested' } })),

	/*
	 * The structured article: the body without its front matter, plus the metadata as fields. This is
	 * what the fields panel edits. `readFile`/`writeFile` above stay the raw whole-file path, which is
	 * what every non-article file uses (layout, stylesheet, `_config.yml`) and what keeps those files
	 * byte-exact.
	 */

	/** @param {string} path article target */
	articleContent: (path) => call(axios.get(base('/api/article/content'), { params: { path } })),

	/**
	 * @param {{path:string, body:string, baseEtag?:string, meta:object}} payload
	 *        `meta` carries only the fields that changed (absent = leave as it is, '' / [] = remove)
	 */
	saveArticleContent: (payload) => call(axios.put(base('/api/article/content'), payload)),

	// Note: the URL segment is `preview-token`, not `session` — the latter does not route
	// in Nextcloud (see the comment in appinfo/routes.php).
	session: () => call(axios.post(base('/api/site/preview-token'))),
}

export default api
