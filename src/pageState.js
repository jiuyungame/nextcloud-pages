/**
 * The shell state and the one capability decision the shell makes on the client.
 *
 * There is **one** page (see appinfo/routes.php): the caller's own author area, the structured editor,
 * the visibility requests, publishing and reading. The server renders who may do what into the shell
 * state, and this module turns that into the booleans the app gates on.
 *
 * Everything here is free of Vue and of the network, so the decisions can be asserted without a
 * browser. The column's data itself is the tree endpoint's answer and needs no translation: its nodes
 * carry the target every endpoint takes.
 */

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
		// a JSON array is valid JSON but not a state: the shell writes an object, and a control reading
		// `.canReview` off an array would silently see undefined
		return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
	} catch (error) {
		return {}
	}
}

/**
 * The review grant of a shell state. `canReview` in App.vue is the one consumer: it gates the
 * request panel, the reviewer's row actions and the read view's comment moderation.
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
