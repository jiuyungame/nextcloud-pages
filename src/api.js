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
 * (the id is not part of the API, the URL, or the stored configuration). The settings page
 * (src/admin.js) talks to the admin endpoints with axios directly; this module is the
 * author-facing surface.
 */
export const api = {
	/**
	 * The file tree of the caller's own author area: the column of the one page this app has, for
	 * every user - the site's storage user and an administrator included. Its nodes carry the site
	 * `target` every file and article endpoint takes, and the `target_prefix` they are built on.
	 */
	tree: () => call(axios.get(base('/api/site/tree'), { params: { scope: 'author' } })),

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

	/**
	 * Delete one file or folder of the caller's author area.
	 *
	 * `mode` is what a deletion of a *published* file has to answer: `''` (or absent) deletes the file
	 * only, `'request-offline'` deletes it and files a take-off request that waits for a reviewer, and
	 * `'keep'` deletes it and hands the page to the orphan owner directory at once. The server ignores
	 * the two workflows for a folder or an entry the site is not serving and answers
	 * `mode_applied: false` with the reason, so a caller may send a mode unconditionally.
	 *
	 * @param {string} path the target of the file or folder
	 * @param {''|'request-offline'|'keep'} [mode] what the online page should do with it
	 */
	deleteFile: (path, mode = '') =>
		call(axios.delete(base('/api/site/file'), { params: { path, mode } })),

	status: () => call(axios.get(base('/api/site/status'))),
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
	 * The visibility workflow of the snapshot model: an entry is internal until a reviewer approves a
	 * frozen candidate for the site. The author asks (request), withdraws (cancel) and the reviewer
	 * answers (decision); a reviewer with nothing pending takes a served page down with its own
	 * endpoint (take-down), because a decision would be a 409 there.
	 */

	/**
	 * Ask for the entry to go online (`online`, which on a served entry means an update of its
	 * content) or off it (`offline`). The server freezes a candidate snapshot for `online`; a pending
	 * request answers 409.
	 *
	 * @param {string} path article target @param {'online'|'offline'} intent the direction asked for
	 */
	requestVisibility: (path, intent) =>
		call(axios.post(base('/api/article/request'), { path, intent })),

	/** Withdraw the pending request; the entry returns to where its intent started */
	cancelRequest: (path) => call(axios.post(base('/api/article/cancel'), { path })),

	/**
	 * Answer a pending request: `approve` publishes the candidate or carries out the take-off,
	 * `reject` discards the candidate or declines the take-off (the served version stays).
	 *
	 * @param {string} path article target
	 * @param {'approve'|'reject'} decision
	 * @param {string} [note] why a request was rejected; the author sees it
	 */
	decideRequest: (path, decision, note = '') =>
		call(axios.post(base('/api/article/decision'), { path, decision, note })),

	/**
	 * A reviewer takes a page the site serves off it, with nothing pending. A decision would be a
	 * conflict on such an entry, which is why this is its own endpoint.
	 *
	 * @param {string} path article target
	 * @param {string} [note] why the page was taken down; the author sees it
	 */
	takeDown: (path, note = '') =>
		call(axios.post(base('/api/article/take-down'), { path, note })),

	/**
	 * The take-off requests: every entry in `requested`, which since the folders are the truth means
	 * exactly one carrying a `downRequest`. 403 without the review grant.
	 *
	 * A candidate is not in this answer: the state is derived (`online/` → `external`, otherwise
	 * `internal`), so a page waiting for a first publication or an update keeps its state and is read
	 * with `awaitingArticles()` instead.
	 */
	visibilityRequests: () => call(axios.get(base('/api/articles'), { params: { state: 'requested' } })),

	/**
	 * The entries carrying a candidate in `pending/` (`?awaiting=1`): a first publication, or an
	 * update of an entry the site already serves. The panel asks for this list and the take-off list
	 * (`visibilityRequests()`) in parallel and merges them by target.
	 */
	awaitingArticles: () => call(axios.get(base('/api/articles'), { params: { awaiting: true } })),

	/*
	 * The structured article: the body without its front matter, plus the metadata as fields. This is
	 * what the fields panel edits; a file that is not an article is a resource of the author's area,
	 * replaced as bytes or deleted, never edited as text.
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
