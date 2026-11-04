/**
 * Small shared helpers for the article views. Kept out of the components so both of them group,
 * sort, label and format the same way — the two lists show the same article shape.
 */
import { t } from './i18n.js'

/**
 * The three visibility states, in the order a list shows them: what waits for a reviewer first, then
 * what is internal, then what the site is going to serve. Kept as one list so the article list, the
 * request panel and the reading view cannot disagree about the order or about a label.
 *
 * `requested` means one thing only since the folder-based model: the author asked for a take-off
 * (`downRequest`). A page waiting to go live is `internal` (or `external` when an update waits) and
 * carries `candidate: true`; the lists that show it read that flag, not a fourth state.
 */
const STATE_ORDER = ['requested', 'internal', 'external']

/** State labels for the filters, keyed by the values the API uses. */
const STATE_LABELS = {
	internal: () => t('pages', 'Internal'),
	requested: () => t('pages', 'Take-off requested'),
	external: () => t('pages', 'External'),
}

/**
 * The label of one entry, from the author's point of view.
 *
 * Since the folders are the truth, the state alone no longer says what the author is waiting for: a
 * `candidate` is a page (or an update) waiting for a reviewer, while `requested` only means a
 * take-off request. The three labels are therefore derived from the row's own flags first, and only
 * then from the state: `external` reads **Online** for a settled served entry.
 */
const ENTRY_STATE_LABELS = {
	internal: () => t('pages', 'Internal'),
	requested: () => t('pages', 'Take-off requested'),
	external: () => t('pages', 'Online'),
}

/** Colour family of a state badge; an unknown state is drawn as neutral, not as an error. */
const STATE_KINDS = {
	internal: 'neutral',
	requested: 'info',
	external: 'success',
}

export function stateLabel(state) {
	const label = STATE_LABELS[state]
	return label ? label() : String(state || '')
}

/**
 * The label of one entry in a list, in four cases:
 *
 *   1. `candidate: true` → **Waiting for review** (a first publication or an update waits)
 *   2. `state === 'requested'` → **Take-off requested** (a downRequest waits)
 *   3. `published: true` → **Online**
 *   4. otherwise → **Internal**
 *
 * `candidate` wins over the state on purpose: a pending request is `internal` or `external` now,
 * and a stale answer that still calls it `requested` must not be shown as a take-off. An unknown
 * state is named as it came, never invented.
 */
export function entryStateLabel(state, article) {
	if (article?.candidate === true) {
		return t('pages', 'Waiting for review')
	}
	if (state === 'requested') {
		return t('pages', 'Take-off requested')
	}
	if (article?.published === true) {
		return t('pages', 'Online')
	}
	const label = ENTRY_STATE_LABELS[state]
	return label ? label() : stateLabel(state)
}

export function stateKind(state, article) {
	// a candidate is the one pending thing a state alone cannot name, so its badge is the pending
	// colour; every other badge keeps the state's own family
	if (article?.candidate === true) {
		return 'info'
	}
	return STATE_KINDS[state] || 'neutral'
}

/**
 * The rows of the request panel: the entries with a pending candidate and the entries carrying a
 * take-off request. They come from two filters of the same list endpoint (`?awaiting=1` answers only
 * candidates, `?state=requested` only take-offs, because the state is derived from the folders), so
 * the panel merges them here. The first list wins on a duplicate, and the empty target is dropped:
 * a row no endpoint could address is not one this panel may decide.
 */
export function mergeRows(...lists) {
	const seen = new Set()
	const out = []
	for (const list of lists) {
		for (const article of Array.isArray(list) ? list : []) {
			const key = rowKey(article)
			if (key === '' || seen.has(key)) {
				continue
			}
			seen.add(key)
			out.push(article)
		}
	}
	return out
}

/**
 * The direction of the pending request on a row: `online` (the author asked for the page to go live),
 * `offline` (asked for it to come off the site), or '' while nothing is pending.
 */
export function intentOf(article) {
	const intent = String(article?.intent || '')
	return intent === 'online' || intent === 'offline' ? intent : ''
}

/** Where a state sorts in a list: what waits for a reviewer, then internal, then external. */
export function stateRank(state) {
	const rank = STATE_ORDER.indexOf(state)
	return rank === -1 ? STATE_ORDER.length : rank
}

/** Entity id of a row; the target is unique across the site, so it is the key everything hangs on. */
export function rowKey(article) {
	return String(article?.path || '')
}

/**
 * Group rows by state in STATE_ORDER, keeping the order the server returned inside each group.
 * Sections are data, not markup, so the caller decides which ones to render.
 */
export function groupByState(articles) {
	const seen = new Map()
	;(Array.isArray(articles) ? articles : []).forEach((article, index) => {
		seen.set(rowKey(article), index)
	})
	const groups = new Map()
	for (const article of Array.isArray(articles) ? articles : []) {
		const state = String(article?.state || '')
		if (!groups.has(state)) {
			groups.set(state, [])
		}
		groups.get(state).push(article)
	}
	return Array.from(groups.entries())
		.map(([state, rows]) => ({ state, rows }))
		.sort((a, b) => stateRank(a.state) - stateRank(b.state))
		.map((group) => ({
			...group,
			rows: group.rows.slice().sort((a, b) => (seen.get(rowKey(a)) ?? 0) - (seen.get(rowKey(b)) ?? 0)),
		}))
}

/**
 * A timestamp or a date string as a short local date.
 *
 * `0` and an absent value both read as "no date": the API reports a missing moment as `0` (see
 * ArticleIndexService, where a post without front-matter date and without a `YYYY-MM-DD-` prefix is
 * `0`), and drawing that as 1970 would invent a date the article never had.
 */
export function formatDate(value) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const numeric = typeof value === 'number' || /^\d+$/.test(String(value))
	if (numeric && Number(value) === 0) {
		return ''
	}
	// the API's `date` is a unix timestamp in seconds; a Date instance is accepted as well
	const date = numeric ? new Date(Number(value) * 1000) : new Date(value)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString()
}

/** Whole days since a unix timestamp; 0 means "today", an unusable value means "no idea". */
export function waitingDays(value, now = Date.now()) {
	const seconds = Number(value)
	if (!Number.isFinite(seconds) || seconds <= 0) {
		return null
	}
	const days = Math.floor((now / 1000 - seconds) / 86400)
	return days > 0 ? days : 0
}

/**
 * The message for a failed call: a 403 is named as a permission problem, because "Request failed"
 * sends an author looking for a bug instead of asking the person who granted the capability.
 */
export function reasonFor(result) {
	if (result?.status === 403) {
		return t('pages', 'You do not have permission for this action.')
	}
	return result?.message || t('pages', 'Request failed')
}

/**
 * A title as a file-name candidate: lower case, spaces and everything a file name must not contain
 * become dashes, runs collapse, leading and trailing dots and dashes go.
 *
 * Letters of any script survive, because a Chinese title is a perfectly good address on a Chinese
 * site — this site's own pages are named that way. Forcing ASCII would make those titles
 * uncreatable, which is why the earlier rule was wrong.
 */
export function slugify(title) {
	return String(title || '')
		.trim()
		.toLowerCase()
		.replace(/[\s/\\:*?"<>|]+/g, '-')
		.replace(/^[.\-]+|[.\-]+$/g, '')
		.slice(0, 80)
		.replace(/[.\-]+$/g, '')
}

/** Whether a name can go into a path exactly as it is (so the target never needs escaping). */
export function isSlugOk(slug) {
	const name = String(slug || '').trim()
	if (name === '' || name.length > 80) {
		return false
	}
	// no separators, no control characters, no leading dot (a hidden file), no trailing dot or dash
	return !/[/\\:*?"<>|\u0000-\u001f]/.test(name) && !name.startsWith('.') && !/[.\-]$/.test(name)
}

/**
 * The meta keys the panel shows as its own fields, or computes for display. Every other key of the
 * block is "additional metadata": the read-only table, and the rows of the metadata dialog.
 */
export const KNOWN_META_KEYS = [
	'title',
	'tags',
	'categories',
	'date',
	'excerpt',
	'modified_date',
	'author',
	'permalink',
	'url',
	'keys',
]

/**
 * The additional metadata of a server `meta` object, in the file's own key order (the order `keys`
 * reports), so the table and the dialog show the block the way the author wrote it.
 */
export function extrasOf(meta) {
	const source = meta && typeof meta === 'object' ? meta : {}
	const out = {}
	const order = Array.isArray(source.keys) ? source.keys.map(String) : Object.keys(source)
	for (const key of order) {
		if (KNOWN_META_KEYS.includes(key)) {
			continue
		}
		if (Object.prototype.hasOwnProperty.call(source, key)) {
			out[key] = source[key]
		}
	}
	return out
}

/** One additional-metadata value as the compact table shows it. */
export function displayMetaValue(value) {
	if (Array.isArray(value)) {
		return value.map(String).join(', ')
	}
	if (typeof value === 'boolean') {
		return value ? 'true' : 'false'
	}
	return String(value ?? '')
}

/** A target split back into the parts a rename field can edit: date, name and extension. */
export function splitTarget(path) {
	const base = String(path || '').split('/').pop() || ''
	const match = /^(\d{4}-\d{2}-\d{2})-(.+)\.(md|markdown|html)$/i.exec(base)
	if (!match) {
		const plain = /^(.+)\.(md|markdown|html)$/i.exec(base)
		return plain ? { day: '', name: plain[1], ext: plain[2] } : { day: '', name: base, ext: 'md' }
	}
	return { day: match[1], name: match[2], ext: match[3] }
}

/**
 * The target of the same page under a new name: it keeps its folder, and a page whose name carries a
 * day keeps that day (the day is part of the name the author chose, not a post's date any more).
 * `''` means the new name cannot be used at all.
 */
export function renameArticleTarget(path, newName) {
	const { day, ext } = splitTarget(path)
	const slug = slugify(newName)
	if (!isSlugOk(slug)) {
		return ''
	}
	const dir = String(path || '').split('/').slice(0, -1).join('/')
	if (dir === '') {
		return ''
	}

	return day === '' ? `${dir}/${slug}.${ext}` : `${dir}/${day}-${slug}.${ext}`
}

/**
 * The minimal front matter of a new *page*: the title and nothing else.
 *
 * A page of an author area is a template page, not a post: its layout comes from the site (a page
 * without `layout` still renders through the site's `_layouts/page.html` when the site declares one),
 * and its address is decided by the site's author mirror - so no date and no `layout: post` are
 * written here. The title is quoted, so a title containing `:` or `#` survives the server's
 * front-matter parser unchanged (an unquoted `a: b` would read as a nested map, and `a # b` would
 * lose everything from the `#` on).
 */
export function pageFrontMatter(title) {
	const quote = (value) => String(value ?? '').replace(/\\/g, '\\\\').replace(/"/g, '\\"')
	const name = String(title ?? '').trim().replace(/\.[A-Za-z0-9]+$/, '')
	// `layout` is what makes the page render: the template serves a page without one as bare HTML, so
	// a new page starts with the plain layout and the panel lets it be changed.
	const lines = ['---', `title: "${quote(name)}"`, 'layout: page', '---', '', '']

	return lines.join('\n')
}
