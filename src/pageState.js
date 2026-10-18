/**
 * Which page a load is.
 *
 * Two pages share one front end (see appinfo/routes.php): the **content** page — the caller's own file
 * tree, the structured editor, review, publishing and reading, for every signed-in user — and the
 * **source** page, the Jekyll tree and the whole-file editor, for whoever holds the review-and-publish
 * grant (审核与发布: reviewing and publishing are one permission since the publish grant was merged
 * into review). The server names the page in the shell state; the decision below turns that name into
 * the branch the app renders.
 *
 * Everything here is free of Vue and of the network, so "which page is this" can be asserted without
 * a browser. The column's data itself is the tree endpoint's answer and needs no translation: its
 * nodes carry the target every endpoint takes.
 */

/** The page for the caller's own files, review and reading (route `pages.page.index`). */
export const CONTENT_PAGE = 'content'

/** The page for the site's own files: tree, whole-file editor, build and publish (route `pages.page.source`). */
export const SOURCE_PAGE = 'source'

/** The element the shell writes its JSON state into (templates/index.php). */
const STATE_ELEMENT_ID = 'pages-initial-state'

/**
 * The initial state as an object. A missing or unreadable element is an empty state and never a
 * crash: the app still has to render when the shell handed nothing over.
 *
 * @param {Document|null} [doc] the document to read from (injected by a test)
 * @returns {object}
 */
export function readShellState(doc = typeof document === 'undefined' ? null : document) {
	try {
		const element = doc?.getElementById(STATE_ELEMENT_ID)
		const parsed = JSON.parse(element?.textContent || '{}')
		return parsed && typeof parsed === 'object' ? parsed : {}
	} catch (error) {
		return {}
	}
}

/**
 * The page this load is: `'content'` or `'source'`.
 *
 * Only an explicit `'source'` leaves the content page, and only for a caller who can use it. The
 * server redirects a caller without either grant away from `/source`, so a shell that still says
 * `source` describes a page whose every control would be refused — the content page is the one that
 * works for them.
 *
 * @param {object} state the shell state (`page`, `canEdit`, `canPublish`)
 * @returns {'content'|'source'}
 */
export function resolvePage(state) {
	if (String(state?.page ?? '') !== SOURCE_PAGE) {
		return CONTENT_PAGE
	}

	return state?.canEdit === true || state?.canPublish === true ? SOURCE_PAGE : CONTENT_PAGE
}

/**
 * The review grant of a shell state (`showRequests` in App.vue is its only consumer).
 *
 * `canReview` is what the server decided; a shell rendered by an older version carries no such key,
 * and in that version the request panel was gated on `canPublish`. Falling back to it keeps a cached
 * shell from hiding the panel altogether - and keeps the answer a real boolean, so no control is
 * ever mounted on `undefined`. The server reports both keys from the same grant now (审核与发布), so
 * the fallback can only ever agree with `canReview`.
 *
 * @param {object} state the shell state (`canReview`, `canPublish`)
 * @returns {boolean}
 */
export function reviewGrant(state) {
	if (typeof state?.canReview === 'boolean') {
		return state.canReview
	}

	return state?.canPublish === true
}

