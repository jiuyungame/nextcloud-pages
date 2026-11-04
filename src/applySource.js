/**
 * Apply source: rebuild the three derived trees from the Nextcloud folders and rebuild the preview.
 * No commit, no push, no config change.
 *
 * The flow lives here rather than in `admin.js` because that file owns the DOM and cannot be
 * imported by a test; this module receives the two things it needs from the page - the confirmation
 * dialog and the toast - so `tests/code/proof-frontend.mjs` can execute the whole request/answer
 * cycle, including the delete-guard confirmation, against a stubbed axios.
 *
 * The bridge's `mirror` op refuses a round that would delete most of a tree **before** it clears
 * anything and answers `{ok: false, error: 'MIRROR_DELETE_GUARD', tree, old, new, removed, guard}`.
 * That is a question, not an error: the administrator is shown how many files and which tree, and
 * the same request is sent again with `force: true`. `force` skips only that guard, never the
 * bridge's source check - `SOURCE_EMPTY`/`SOURCE_UNREADABLE` are data problems and are reported as
 * errors without a confirmation.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from './i18n.js'
import { buildFailure, deleteGuard, failureMessage, sourceCounts } from './settings.js'

const url = (path) => generateUrl('/apps/pages' + path)

/**
 * Run one Apply source.
 *
 * @param {object} io the page's own UI
 * @param {(title:string, lines:string[], label:string) => Promise<boolean>|boolean|undefined} io.confirm the confirmation dialog (resolves true on "apply anyway")
 * @param {(type:string, message:string) => void} io.toast the result toast
 * @param {(busy:boolean) => void} [io.setBusy] disables the button while a request runs
 * @returns {Promise<{applied:boolean, forced:boolean}>} what the page may report (a cancelled guard is `applied: false`)
 */
export async function applySource({ confirm, toast, setBusy }) {
	setBusy?.(true)
	let forced = false
	try {
		let { data } = await axios.post(url('/api/site/build'), { force: false })
		const guard = deleteGuard(data)
		if (guard !== null) {
			const confirmed = await confirm?.(
				t('pages', 'Apply source would delete most of a site tree'),
				[t(
					'pages',
					'This rebuild would delete %1$s files from the “%2$s” tree (%3$s → %4$s files). Continue?',
					[String(guard.removed), guard.tree || '?', String(guard.old), String(guard.new)],
				)],
				t('pages', 'Apply anyway'),
			)
			if (confirmed !== true) {
				return { applied: false, forced: false }
			}
			forced = true
			;({ data } = await axios.post(url('/api/site/build'), { force: true }))
		}
		const failure = buildFailure(data)
		if (failure) {
			toast('error', failure.stage === 'build'
				? t('pages', 'Build failed: %s', [failure.message || t('pages', '(no detail)')])
				: t('pages', 'Mirror failed: %s', [failure.message || t('pages', '(no detail)')]))
			return { applied: false, forced }
		}
		const counts = sourceCounts(data?.files)
		toast('success', t('pages', 'Rebuilt 3 trees: work %1$s, preview %2$s, onlinepreview %3$s', [
			String(counts.work),
			String(counts.preview),
			String(counts.onlinepreview),
		]))
		return { applied: true, forced }
	} catch (error) {
		toast('error', t('pages', 'Apply source failed: %s', [
			failureMessage(error, t('pages', 'The bridge did not answer.')),
		]))
		return { applied: false, forced }
	} finally {
		setBusy?.(false)
	}
}
