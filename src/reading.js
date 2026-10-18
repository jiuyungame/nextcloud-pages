/**
 * The pure logic behind the reading view: how a moment is described in words, and what a click on a
 * vote button means.
 *
 * Kept out of the components so the rules that are easy to get wrong — a timestamp the server may
 * send in three shapes, and a vote that has to be withdrawable — can be tested without a browser.
 * Nothing here talks to the network: the server's answer is always what the numbers are taken from.
 */
import { n, t } from './i18n.js'

/** The sort modes the API accepts; the first one is the default. */
export const SORTS = ['new', 'score', 'comments']

/** Labels of the sort switch, so the list and the request cannot disagree about a mode. */
const SORT_KEYS = {
	new: 'Newest',
	score: 'Best rated',
	comments: 'Most discussed',
}

/** @param {string} sort */
export function sortLabelKey(sort) {
	return SORT_KEYS[sort] || SORT_KEYS.new
}

/** @param {string} sort @returns {string} the accepted mode, falling back to the default */
export function normalizeSort(sort) {
	return SORTS.includes(sort) ? sort : SORTS[0]
}

/**
 * A timestamp in seconds or milliseconds (number or numeric string) or a date string, as milliseconds.
 * The API mixes both: article dates are unix seconds, comment times read like ISO strings.
 */
export function toMillis(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const numeric = typeof value === 'number' || /^\d+$/.test(String(value).trim())
	if (numeric) {
		const seconds = Number(value)
		if (!Number.isFinite(seconds) || seconds <= 0) {
			return null
		}
		// anything below this bound cannot be milliseconds for a date in this century
		return seconds < 100000000000 ? seconds * 1000 : seconds
	}
	const parsed = new Date(String(value)).getTime()
	return Number.isNaN(parsed) ? null : parsed
}

/**
 * A moment as "just now" / "5 minutes ago" — the shape a comment thread reads best in.
 *
 * Returns a form (`{key, count}`) rather than a string so it stays free of translation, and the label
 * is built by {@see relativeLabel}. A moment in the future reads as "just now": a clock a little ahead
 * of the server must not produce "in -3 seconds".
 *
 * @param {number|string} value seconds, milliseconds or a date string
 * @param {number} now milliseconds, injectable so a test does not depend on the clock
 * @returns {{key:'now'|'minute'|'hour'|'day'|'month'|'year', count:number}|null}
 */
export function relativeTime(value, now = Date.now()) {
	const millis = toMillis(value)
	if (millis === null) {
		return null
	}
	const seconds = Math.floor((now - millis) / 1000)
	if (seconds < 45) {
		return { key: 'now', count: 0 }
	}
	const steps = [
		['minute', 60],
		['hour', 3600],
		['day', 86400],
		['month', 2592000],
		['year', 31536000],
	]
	let chosen = steps[0]
	for (const step of steps) {
		if (seconds >= step[1]) {
			chosen = step
		}
	}
	return { key: chosen[0], count: Math.max(1, Math.floor(seconds / chosen[1])) }
}

/**
 * The words for a moment: `relativeTime()` plus the translation. One place decides the wording, so a
 * comment and an article row can never describe the same age differently.
 */
export function relativeLabel(value, now = Date.now()) {
	const form = relativeTime(value, now)
	if (form === null) {
		return ''
	}
	switch (form.key) {
		case 'now':
			return t('pages', 'just now')
		case 'minute':
			return n('pages', '%n minute ago', '%n minutes ago', form.count)
		case 'hour':
			return n('pages', '%n hour ago', '%n hours ago', form.count)
		case 'day':
			return n('pages', '%n day ago', '%n days ago', form.count)
		case 'month':
			return n('pages', '%n month ago', '%n months ago', form.count)
		default:
			return n('pages', '%n year ago', '%n years ago', form.count)
	}
}

/** An absolute form of the same moment, for the `title` attribute: never a guess. */
export function absoluteTime(value) {
	const millis = toMillis(value)
	if (millis === null) {
		return ''
	}
	return new Date(millis).toLocaleString()
}

/**
 * What a vote click means, and it has to be reversible: clicking the vote one already holds withdraws
 * it (`'none'`), which is exactly what the API expects to hear.
 *
 * @param {'up'|'down'|'none'} current the caller's vote as the server last reported it
 * @param {'up'|'down'} clicked the button that was clicked
 * @returns {'up'|'down'|'none'} the vote to send
 */
export function toggleVote(current, clicked) {
	const held = current === 'up' || current === 'down' ? current : 'none'
	return held === clicked ? 'none' : clicked
}

/**
 * The numbers as they would look after a vote, used **only** to keep the buttons responsive until the
 * server answers. The server's own numbers always win: a refused vote must never leave the UI claiming
 * it (see the caller, which replaces these with the response or rolls back).
 *
 * @param {{up:number,down:number,mine:'up'|'down'|'none'}} context
 * @param {'up'|'down'|'none'} next
 */
export function optimisticVote(context, next) {
	const up = Number(context?.up) || 0
	const down = Number(context?.down) || 0
	const held = context?.mine === 'up' || context?.mine === 'down' ? context.mine : 'none'
	if (held === next) {
		return { up, down, mine: held }
	}
	const after = { up, down }
	if (held !== 'none') {
		after[held] -= 1
	}
	if (next !== 'none') {
		after[next] += 1
	}
	return { up: Math.max(0, after.up), down: Math.max(0, after.down), mine: next }
}

/** The caller's own vote, from whatever the server called the field. */
export function mineOf(article) {
	const raw = article?.mine
	if (raw === 'up' || raw === 'down' || raw === 'none') {
		return raw
	}
	/*
	 * The vote endpoints answer with the number the column holds — `1` for up, `-1` for down, `0` for
	 * none. Accepting only the words made every vote that already existed read as "none": the button
	 * never showed as held, and clicking it voted again instead of withdrawing, so a vote could be cast
	 * but never taken back.
	 */
	const numeric = Number(raw)
	if (numeric > 0) {
		return 'up'
	}
	if (numeric < 0) {
		return 'down'
	}

	return 'none'
}

/** The three numbers of a vote context, from an article as any endpoint reports it. */
export function voteContextOf(article) {
	return {
		up: Number(article?.up) || 0,
		down: Number(article?.down) || 0,
		mine: mineOf(article),
	}
}

/**
 * The reason a comment write failed, in words a reader can act on: a rate limit is a wait, not an
 * error, and the server says how long.
 *
 * @param {{status:number, data?:object, message?:string}} result
 * @returns {{kind:'rate'|'forbidden'|'gone'|'error', seconds:number, message:string}}
 */
export function commentFailure(result) {
	const message = String(result?.message || '')
	if (result?.status === 429 || result?.data?.error === 'TOO_MANY_REQUESTS') {
		const raw = result?.data?.retryAfter
		const seconds = Number(raw)
		return {
			kind: 'rate',
			seconds: Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds) : 0,
			message,
		}
	}
	if (result?.status === 403) {
		return { kind: 'forbidden', seconds: 0, message }
	}
	if (result?.status === 404) {
		return { kind: 'gone', seconds: 0, message }
	}
	return { kind: 'error', seconds: 0, message }
}

/** The excerpt shown under a title in the list: the API's summary, or a dash when there is none. */
export function summaryOf(article, limit = 160) {
	const text = String(article?.summary ?? '').trim()
	if (text === '') {
		return ''
	}
	return text.length <= limit ? text : text.slice(0, limit).trim() + '…'
}

/**
 * The total of a vote row — `up - down` — with a sign, so a reader sees the sum and not only both
 * halves. Zero is `0` and not `+0`: a plus in front of a zero reads as a value that is somehow
 * positive.
 *
 * @param {number|string} up
 * @param {number|string} down
 * @returns {string}
 */
export function scoreLabel(up, down) {
	const total = (Number(up) || 0) - (Number(down) || 0)
	return total > 0 ? `+${total}` : String(total)
}

/**
 * The state values the article list may be filtered by, in the order the filter offers them.
 *
 * The server answers an unknown value with HTTP 400 rather than ignoring it, so the set is kept here
 * and the filter only ever sends one of these. `''` is the "all states" entry.
 */
export const STATE_FILTERS = ['', 'internal', 'requested', 'external']

/**
 * @param {string} state
 * @returns {boolean} whether the value may be sent as a `state=` filter
 */
export function isStateFilter(state) {
	return STATE_FILTERS.includes(String(state ?? ''))
}

/**
 * The message the list shows when it came back empty. Which sentence is right depends on what was
 * asked for: with a filter applied, "the site has no articles" is a wrong answer to "nothing carries
 * this tag".
 *
 * @param {{tag?:string, state?:string}} filter
 * @param {(text:string, vars?:Array) => string} translate
 * @returns {string}
 */
export function emptyListMessage(filter, translate) {
	const tag = String(filter?.tag ?? '')
	const state = String(filter?.state ?? '')
	if (tag !== '' && state !== '') {
		return translate('No article carries this tag in that state.')
	}
	if (state !== '') {
		return translate('No article is in that state.')
	}
	if (tag !== '') {
		return translate('No article carries this tag.')
	}
	return translate('There are no articles yet.')
}

/**
 * The reason a list request failed, in words. A 400 is named as the filter being the problem: the
 * server rejects an unknown `state` value instead of ignoring it, and a plain "Request failed" would
 * send the reader looking for a bug rather than at the filter they just picked.
 *
 * @param {{status:number, data?:object, message?:string}} result
 * @param {(text:string, vars?:Array) => string} translate
 * @returns {string}
 */
export function listFailureMessage(result, translate) {
	if (result?.status === 400) {
		return translate('The filter was rejected by the server:') + ' '
			+ (String(result?.message || '') || translate('unknown value'))
	}
	return String(result?.message || '') || translate('The articles could not be loaded.')
}
