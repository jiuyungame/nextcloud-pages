/**
 * Translation helpers for this app.
 *
 * `@nextcloud/l10n` hands back the source string unchanged when a string has no translation for the
 * current language — an English interface, or a string that was added after the last translation pass.
 * The placeholders are left in it, which is how a literal `%s` reached the screen ("Submitted for
 * review: %s", "%s articles"). Filling in whatever the library left behind means a message reads
 * correctly whether or not a translation exists, instead of depending on the current language.
 */
import { n as ncN, t as ncT } from '@nextcloud/l10n'

/*
 * Everything else the library offers is passed through unchanged: components import more than `t`
 * from this module, and a missing named export would be a module that fails to load — a blank page
 * instead of a broken label. Only the two functions that interpolate are replaced.
 */
export * from '@nextcloud/l10n'

/**
 * Replace `%s`, `%1$s`… and `%n` with the values that were handed in. A placeholder without a value
 * is left alone rather than turned into "undefined": showing `%s` is at least a visible bug.
 *
 * @param {string} text the (possibly translated) string
 * @param {Array|Object} values the same values the caller passed to `t()`
 * @returns {string}
 */
function fill(text, values) {
	const list = Array.isArray(values) ? values : Object.values(values || {})
	let next = 0

	return String(text)
		.replace(/%(\d+)\$s/g, (match, index) => {
			const value = list[Number(index) - 1]

			return value === undefined ? match : String(value)
		})
		.replace(/%s|%n/g, (match) => {
			if (next >= list.length) {
				return match
			}
			const value = list[next]
			next += 1

			return String(value)
		})
}

/**
 * `t()` with the placeholders always filled in.
 *
 * @param {string} app
 * @param {string} text
 * @param {Array|Object} [vars]
 * @param {number} [number]
 * @param {Object} [options]
 * @returns {string}
 */
export function t(app, text, vars, number, options) {
	return fill(ncT(app, text, vars, number, options), vars)
}

/**
 * `n()` with the placeholders always filled in: the count is `%n`, the rest are `%s` in order.
 *
 * @param {string} app
 * @param {string} singular
 * @param {string} plural
 * @param {number} count
 * @param {Array|Object} [vars]
 * @param {Object} [options]
 * @returns {string}
 */
export function n(app, singular, plural, count, vars, options) {
	const translated = ncN(app, singular, plural, count, vars, options)
	const rest = Array.isArray(vars) ? vars : Object.values(vars || {})

	return fill(translated, [String(count), ...rest])
}
