/**
 * The geometry of an article as a *page* rather than a file: which path is one, which fields a save
 * actually changed, what the payload looks like, and what the automatic excerpt would be.
 *
 * Deliberately free of Vue and of the network: everything here is a pure function, so the rules that
 * decide "did the user change the title" and "what exactly is sent" can be tested without a browser.
 * The server owns the file format — this module only decides *what to ask for*.
 */

/** The post extensions the app treats as articles (the same set the server indexes). */
const POST_EXTENSIONS = ['md', 'markdown', 'html']

/** The retired directory whose files are articles, as it appears at the front of a target. */
const POSTS_DIR = '_posts'

/**
 * Whether an article target is one of the structured articles.
 *
 * The rule is deliberately narrow — a renderable extension, and either a post of the site's own
 * `_posts/…` or an author target `<author_root>/<uid>/…` — so a layout, a stylesheet or
 * `_config.yml` can never be pulled into the fields panel: those files are the editor's own content
 * and must stay byte-exact in the raw editor. An author target is recognised by its depth (the root
 * and the uid in front of the file), which is what every mirrored author file has.
 */
export function isArticlePath(path) {
	const clean = String(path || '').trim().replace(/^\/+/, '')
	const extension = clean.includes('.') ? clean.split('.').pop().toLowerCase() : ''
	if (!POST_EXTENSIONS.includes(extension)) {
		return false
	}
	if (clean.startsWith(POSTS_DIR + '/')) {
		return true
	}
	// `<author_root>/<uid>/…`: three segments at least, the file being the last one
	return clean.split('/').filter((segment) => segment !== '').length >= 3
}

/** The `_posts/<uid>/<file>` prefix of a target, so a new file can be placed next to another one. */
export function postsDirOf(path) {
	const clean = String(path || '').trim().replace(/^\/+/, '')
	const cut = clean.lastIndexOf('/')
	return cut === -1 ? POSTS_DIR : clean.slice(0, cut)
}

/**
 * A front-matter list as a trimmed, deduplicated, order-preserving array.
 * The server may answer with a real list, a space-separated string or a single word.
 */
export function parseTags(value) {
	const raw = Array.isArray(value) ? value : String(value ?? '').split(/[\s,]+/)
	const out = []
	for (const item of raw) {
		const tag = String(item ?? '').trim()
		if (tag !== '' && !out.includes(tag)) {
			out.push(tag)
		}
	}
	return out
}

/** Tags as the one line a human edits; tags cannot contain a comma or a space, so both separate. */
export function formatTags(tags) {
	return parseTags(tags).join(', ')
}

/** Tags in the order the server is meant to read them, so "a, b" and "b, a" are the same set. */
export function sameTags(a, b) {
	const left = parseTags(a).slice().sort()
	const right = parseTags(b).slice().sort()
	return left.length === right.length && left.every((tag, index) => tag === right[index])
}

/**
 * The categories of one side of a comparison, as the server reads them: a list of names, or the one
 * line a human typed (commas or spaces). Categories are single-level folder names, so the parse is
 * the same as for tags.
 */
function categoriesOf(fields) {
	return parseCategories(fields)
}

/** Whether two category lists name the same set: one copy per category, so order is not a change. */
export function sameCategories(a, b) {
	const left = categoriesOf(a).slice().sort()
	const right = categoriesOf(b).slice().sort()
	return left.length === right.length && left.every((category, index) => category === right[index])
}

/** Categories as the one line a human edits; the server accepts commas or spaces. */
export function formatCategories(categories) {
	return categoriesOf(categories).join(', ')
}

/** A text field as it is compared: `undefined` and `null` mean "the field is not set". */
export function normalizeText(value) {
	return value === undefined || value === null ? '' : String(value)
}

/**
 * The `YYYY-MM-DD` prefix of a post file name, or '' — the fallback for a post that has no date yet,
 * which is also how the server derives one.
 */
export function dateFromFilename(path) {
	const match = /(?:^|\/)(\d{4}-\d{2}-\d{2})-/.exec(String(path || ''))
	return match ? match[1] : ''
}

/** A date value as an `<input type="date">` needs it: `YYYY-MM-DD`, taken from the text itself. */
export function isoDate(value) {
	const match = /(\d{4}-\d{2}-\d{2})/.exec(String(value ?? ''))
	return match ? match[1] : ''
}

/** Today as `YYYY-MM-DD` in the browser's own timezone. */
export function dayToday(now = new Date()) {
	const pad = (value) => String(value).padStart(2, '0')
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * Whether a field value is a day after today.
 *
 * The date field refuses such a value instead of clamping it, and the server applies the same rule
 * with its own clock (`BrowseController::articleSave()`): a timezone difference within the day is
 * accepted, tomorrow is not. Anything that is not an ISO day is not this rule's business — a date
 * input cannot produce one, and the server has its own answer for text that is not a day.
 */
export function isFutureDay(value, now = new Date()) {
	const day = String(value ?? '').trim()
	if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) {
		return false
	}
	// both sides are fixed-width ISO days, so text order is date order
	return day > dayToday(now)
}

/** Categories as a list, the shape the server writes: one name per site folder. */
export function parseCategories(fields) {
	if (fields === null || fields === undefined) {
		return []
	}
	const value = fields.categories !== undefined ? fields.categories : fields.categoriesText
	return parseTags(value)
}

/** Every whitespace-separated word in a value, in order. */
function words(value) {
	return String(value ?? '').split(/\s+/).filter((word) => word !== '')
}

/**
 * The tags of one side of a comparison. The panel works with one text field, the server with a list,
 * so both spellings are accepted — and reading the wrong one would make a tag edit look unchanged.
 */
function tagsOf(fields) {
	if (fields === null || fields === undefined) {
		return []
	}
	const value = fields.tags !== undefined ? fields.tags : fields.tagsText
	return parseTags(value)
}

/**
 * Whether one meta field differs from the baseline the server gave us. Tags and categories compare as
 * a set, every other field compares as text — so reordering a list is not an edit, and an unset field
 * is not an edit of an empty one.
 */
export function isMetaChanged(key, current, original) {
	if (key === 'tags') {
		return !sameTags(tagsOf(current), tagsOf(original))
	}
	if (key === 'categories') {
		return !sameCategories(current, original)
	}
	return normalizeText(current?.[key]).trim() !== normalizeText(original?.[key]).trim()
}

/**
 * The `meta` object to send: **only** the fields the user actually changed, per the frozen contract
 * (absent = leave unchanged, `''`/`[]` = remove the key). An unchanged field is not sent at all, which
 * is what keeps a save from rewriting front matter the panel never showed.
 *
 * @param {{title?:string,tags?:unknown,tagsText?:string,categories?:unknown,categoriesText?:string,date?:string,excerpt?:string}} current what the panel holds
 * @param {{title?:string,tags?:unknown,tagsText?:string,categories?:unknown,categoriesText?:string,date?:string,excerpt?:string}} original what the server gave
 * @returns {{title?:string,tags?:string[],categories?:string[],date?:string,excerpt?:string}}
 */
export function changedMeta(current, original) {
	const meta = {}
	if (isMetaChanged('title', current, original)) {
		meta.title = normalizeText(current?.title).trim()
	}
	if (isMetaChanged('tags', current, original)) {
		meta.tags = tagsOf(current)
	}
	if (isMetaChanged('categories', current, original)) {
		// the server takes a list, one name per site folder; an empty list removes the key
		meta.categories = parseCategories(current)
	}
	if (isMetaChanged('date', current, original)) {
		meta.date = normalizeText(current?.date).trim()
	}
	if (isMetaChanged('excerpt', current, original)) {
		meta.excerpt = normalizeText(current?.excerpt).trim()
	}
	// Additional metadata: any other key the dialog holds. A key that is no longer there is sent as
	// an empty value, which is the contract's "remove this key"; a changed one is sent as it is.
	const currentExtra = current?.extra && typeof current.extra === 'object' ? current.extra : {}
	const originalExtra = original?.extra && typeof original.extra === 'object' ? original.extra : {}
	for (const [key, value] of Object.entries(currentExtra)) {
		if (JSON.stringify(value) !== JSON.stringify(originalExtra[key])) {
			meta[key] = value
		}
	}
	for (const key of Object.keys(originalExtra)) {
		if (!Object.prototype.hasOwnProperty.call(currentExtra, key)) {
			meta[key] = ''
		}
	}
	return meta
}

/**
 * The exact body of `PUT /api/article/content`. Pure, so the payload of a save can be asserted in a
 * test instead of guessed from the code that builds it.
 *
 * @param {string} path article target
 * @param {string} body the markdown body, without front matter
 * @param {string} baseEtag the etag the content was loaded with ('' when there is none)
 * @param {object} current the panel's fields
 * @param {object} original the fields as loaded
 */
export function articlePut(path, body, baseEtag, current, original) {
	const payload = {
		path: String(path || ''),
		body: String(body ?? ''),
		meta: changedMeta(current, original),
	}
	if (normalizeText(baseEtag) !== '') {
		payload.baseEtag = String(baseEtag)
	}
	return payload
}

/** Markdown stripped to something readable in one line; links and images keep their text. */
function plainText(markdown) {
	return String(markdown ?? '')
		.replace(/```[\s\S]*?```/g, ' ')
		.replace(/~~~[\s\S]*?~~~/g, ' ')
		.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
		.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
		.replace(/^\s{0,3}#{1,6}\s*/gm, '')
		.replace(/^\s{0,3}>\s?/gm, '')
		.replace(/^\s{0,3}([-*+]|\d+\.)\s+/gm, '')
		.replace(/[*_`~]/g, '')
		.replace(/<[^>]+>/g, '')
		.replace(/\s+/g, ' ')
		.trim()
}

/** Opening or closing line of a fenced code block. */
const FENCE = /^\s*(```|~~~)/

/**
 * The first paragraph of the source: leading blank lines, leading headings and leading fenced code
 * blocks are skipped, and the paragraph ends at the next blank line. A heading is a title rather than
 * a summary, and a code block is not prose, which is why neither becomes the excerpt.
 */
function firstParagraph(markdown) {
	const lines = String(markdown ?? '').split(/\r\n|\n|\r/)
	let index = 0
	while (index < lines.length) {
		const line = lines[index].trim()
		if (line === '' || /^#{1,6}\s/.test(line)) {
			index += 1
			continue
		}
		if (FENCE.test(lines[index])) {
			// skip the whole block, whatever it contains, up to and including its closing fence
			index += 1
			while (index < lines.length && !FENCE.test(lines[index])) {
				index += 1
			}
			index += 1
			continue
		}
		break
	}
	const paragraph = []
	while (index < lines.length && lines[index].trim() !== '') {
		paragraph.push(lines[index])
		index += 1
	}
	return paragraph.join('\n')
}

/**
 * The first paragraph of the body, trimmed to a line — what the site shows when the article carries
 * no excerpt of its own. Used as the excerpt field's placeholder, so "empty means automatic" is
 * visible instead of implied, and the text is only ever a hint (an empty field is never sent).
 */
export function autoExcerpt(body, limit = 200) {
	const text = plainText(firstParagraph(body))
	if (text === '') {
		return ''
	}
	return text.length <= limit ? text : text.slice(0, limit).trim() + '…'
}

/**
 * The parts of a page view a save has to care about. One place decides "is the body different", so
 * the button state and the payload cannot drift apart.
 */
export function hasChanges(body, current, original, baselineBody) {
	return String(body ?? '') !== normalizeText(baselineBody)
		|| Object.keys(changedMeta(current, original)).length > 0
}
