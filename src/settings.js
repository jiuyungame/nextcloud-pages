/**
 * The pure parts of the settings page logic: what a failure means, how many files a source apply
 * touched, and which stage of a build answer failed.
 *
 * They live apart from `admin.js` because that file talks to the DOM and cannot be imported by a test;
 * `tests/code/proof-frontend.mjs` asserts these rules directly, so a later edit cannot quietly turn
 * "unreachable" into "rejected" or count the mirror round as the whole apply.
 */

/** How long the settings page waits after the last keystroke before it saves the bridge URL. */
export const AUTO_SAVE_DELAY = 800

/**
 * Whether a failed request means "the backend did not answer" rather than "the backend refused it".
 *
 * The two produce different toasts and the same revert. A request that never got a response at all is
 * unreachable; so are the two statuses the app's own translation layer uses for a dead service (502 /
 * 503). Everything else answered - a 400, a 409, a 500 - and its message is worth showing.
 *
 * @param {unknown} error the rejected axios error (or anything else)
 * @returns {boolean}
 */
export function isUnreachable(error) {
	const status = Number(error?.response?.status)
	return !Number.isFinite(status) || status === 0 || status === 502 || status === 503
}

/**
 * The message of a failed request: the server's own sentence when there is one, else the fallback.
 *
 * @param {unknown} error
 * @param {string} fallback
 * @returns {string}
 */
export function failureMessage(error, fallback) {
	return String(error?.response?.data?.message || error?.message || fallback)
}

/**
 * The file counts of the three trees one "Apply source" rebuilt.
 *
 * The bridge's `mirror` op is one operation now: it deletes and refills `work` (the commit tree),
 * `preview` (the author preview) and `onlinepreview` (the review tree), and reports how many files it
 * wrote into each under `files`. The three are named apart in the confirmation sentence, because
 * "N files" alone would not say which tree an author should look at.
 *
 * @param {object|null|undefined} files the `files` block of the build answer
 * @returns {{work:number, preview:number, onlinepreview:number}}
 */
export function sourceCounts(files) {
	const count = (value) => {
		const number = Number(value)
		return Number.isFinite(number) && number > 0 ? number : 0
	}
	return {
		work: count(files?.work),
		preview: count(files?.preview),
		onlinepreview: count(files?.onlinepreview),
	}
}

/**
 * The delete guard of an "Apply source" answer, or null when the answer is not one.
 *
 * The bridge refuses a rebuild that would delete most of a tree before it clears anything, and
 * answers `{ok: false, error: 'MIRROR_DELETE_GUARD', tree, old, new, removed, guard}`. That is the
 * one Apply source failure that is a **question** - "really delete these files?" - so the page asks
 * before it repeats the request with `force: true`. Every other failure (an empty or unreadable
 * source) is a data problem: there is nothing to confirm and `force` would not help, so this returns
 * null for it and the caller reports the error.
 *
 * @param {object|null|undefined} body the build answer
 * @returns {{tree:string, old:number, new:number, removed:number, guard:object|null, message:string}|null}
 */
export function deleteGuard(body) {
	if (!body || typeof body !== 'object' || body.error !== 'MIRROR_DELETE_GUARD') {
		return null
	}
	const count = (value) => {
		const number = Number(value)
		return Number.isFinite(number) && number > 0 ? number : 0
	}
	return {
		tree: String(body.tree || ''),
		old: count(body.old),
		new: count(body.new),
		removed: count(body.removed),
		guard: body.guard && typeof body.guard === 'object' ? body.guard : null,
		message: String(body.message || ''),
	}
}

/**
 * Which stage of an "Apply source" answer failed, and why.
 *
 * The bridge answers HTTP 200 even when a round failed, so the body decides. The answer carries one
 * `build` block now (`{ok, stage, message}`), where `stage` names the step that failed - `mirror` for
 * the tree rebuild, `build` for the Jekyll run - and `message` is the useful part (the mirror's guard
 * message, or the first compiler error). Anything else with `ok: false` is reported as a build
 * failure rather than as a success, because the tree rebuild is part of the same operation.
 *
 * @param {object|null|undefined} body the build answer
 * @returns {{stage: string, message: string}|null} null when the apply succeeded
 */
export function buildFailure(body) {
	if (!body || typeof body !== 'object') {
		return { stage: 'build', message: '' }
	}
	const build = body.build
	if (build && typeof build === 'object' && build.ok === false) {
		return {
			stage: String(build.stage || 'build'),
			message: String(build.message || ''),
		}
	}
	if (body.ok === false) {
		return {
			stage: String(build?.stage || 'build'),
			message: String(body.message || build?.message || ''),
		}
	}
	return null
}
