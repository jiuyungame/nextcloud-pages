/**
 * Admin settings panel: plain DOM + @nextcloud/axios, with Vue only for the two grant pickers and the
 * dialogs/toasts (src/SettingsUI.vue).
 *
 * The page has four blocks and a danger zone, and this script owns their behaviour:
 *
 *   ① Connection  the bridge URL is saved automatically (debounced), pairing/re-pairing and the secret
 *                  rotation run through the bridge endpoints.
 *   ② Site        the four fields are *written through*: leaving any of them saves the whole block and
 *                  pushes it to the bridge in one call; a bridge that refuses reverts all four and says
 *                  why. The data operations (Apply source, Create/Pull, Import) act on the source and
 *                  bridge copies, never on the config.
 *   ③ Authors     the author content directory is written through the same way.
 *   ④ Permissions a grant change is saved immediately (no button).
 *
 * Every result is a toast, every failure an error toast; there are no inline feedback lines and no save
 * buttons any more. The storage user and the folder are read-only once content exists - the server
 * renders them disabled and rejects a change - so this script simply does not save what it cannot edit.
 *
 * Built as the IIFE js/pages-admin.js (second Vite entry).
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { createApp, h, reactive } from 'vue'
import GrantPicker from './GrantPicker.vue'
import SettingsUI from './SettingsUI.vue'
import { applySource as runApplySource } from './applySource.js'
import { t } from './i18n.js'
import { AUTO_SAVE_DELAY, failureMessage, isUnreachable } from './settings.js'

import './admin.css'

const url = (path) => generateUrl('/apps/pages' + path)
const byId = (id) => document.getElementById(id)
const value = (id) => byId(id)?.value.trim() ?? ''
const panel = () => byId('pages-settings')

/** The dialogs/toasts component; null when the panel is not on this page. */
let ui = null

/**
 * The last value the server accepted, per written-through field. This is what a refused change is
 * reverted to: the page must never show a value the backend does not hold.
 */
const accepted = {
	site: { nc_user: '', nc_path: '', repo_url: '', branch: '' },
	authorRoot: '',
	orphanOwner: '',
	bridgeUrl: '',
	grants: null,
}

/** One result toast, or nothing when this page has no UI host (e.g. the bundle loaded elsewhere). */
function toast(type, message) {
	ui?.toast(type, message)
}

/**
 * Report a change the backend did not take: the two causes get the two sentences the plan names, and
 * the caller has already reverted the inputs.
 *
 * @param {unknown} error the rejected request
 * @param {string} fallback a sentence for an error that carries no message at all
 */
function reportKeptPrevious(error, fallback) {
	if (isUnreachable(error)) {
		toast('error', t('pages', 'The backend is unreachable; the previous value was kept.'))
		return
	}
	toast('error', t('pages', 'The backend rejected the change (%s); the previous value was kept.', [
		failureMessage(error, fallback),
	]))
}

/* ------------------------------------------------------------------ ② Site: write-through */

/** The four fields as the write-through sends them (snake_case, the shape PUT /api/site takes). */
function readSite() {
	return {
		nc_user: value('pages-site-nc-user'),
		nc_path: value('pages-site-nc-path'),
		repo_url: value('pages-site-repo'),
		branch: value('pages-site-branch') || 'main',
	}
}

/** Put a site block back into the four inputs (the revert path, and the server's own normalisation). */
function writeSite(site) {
	const next = {
		nc_user: String(site?.nc_user ?? ''),
		nc_path: String(site?.nc_path ?? ''),
		repo_url: String(site?.repo_url ?? ''),
		branch: String(site?.branch ?? 'main'),
	}
	for (const [key, id] of Object.entries({
		nc_user: 'pages-site-nc-user',
		nc_path: 'pages-site-nc-path',
		repo_url: 'pages-site-repo',
		branch: 'pages-site-branch',
	})) {
		const input = byId(id)
		if (input) {
			input.value = next[key]
		}
	}
}

function sameSite(a, b) {
	return ['nc_user', 'nc_path', 'repo_url', 'branch'].every((key) => String(a?.[key] ?? '') === String(b?.[key] ?? ''))
}

/**
 * Commit the whole site block in one call: validate, then `PUT /api/site {…, push: true}`.
 *
 * The server validates the candidate, pushes it to the bridge and only then stores it, so a refused
 * value never reaches the app configuration. `accepted.site` is only moved after the answer, which is
 * what makes "revert all four inputs" exact. An empty storage user is not a validation error: the
 * server resolves it to the current administrator and answers with the concrete uid, which
 * `writeSite()` then shows.
 */
async function saveSiteBlock() {
	const block = readSite()
	if (block.nc_path === '') {
		writeSite(accepted.site)
		toast('error', t('pages', 'Missing site folder'))
		return
	}
	if (sameSite(block, accepted.site)) {
		return
	}
	try {
		const { data } = await axios.put(url('/api/site'), { ...block, push: true })
		accepted.site = {
			nc_user: String(data?.site?.nc_user ?? block.nc_user),
			nc_path: String(data?.site?.nc_path ?? block.nc_path),
			repo_url: String(data?.site?.repo_url ?? block.repo_url),
			branch: String(data?.site?.branch ?? block.branch),
		}
		writeSite(accepted.site)
	} catch (error) {
		writeSite(accepted.site)
		reportKeptPrevious(error, t('pages', 'The site settings could not be saved.'))
	}
}

/* ------------------------------------------------------------------ ③ Authors: write-through */

/** The server's rule for a single folder name; checked here so a typo never reaches the bridge. */
const AUTHOR_ROOT_SHAPE = /^[A-Za-z0-9][A-Za-z0-9._-]*$/

/**
 * The server's two rules for the orphan owner directory: one folder name of at most 64 characters.
 *
 * Whether the name is already a real user cannot be checked here - the app does not hold the user
 * list on this page - so that half is the backend's 400, whose message is shown as it came.
 */
const ORPHAN_OWNER_SHAPE = /^[A-Za-z0-9][A-Za-z0-9._-]*$/
const ORPHAN_OWNER_MAX = 64

/**
 * Commit the author content directory through its own channel: `POST /api/site/author-root`.
 *
 * Shape only. When the name already exists inside the site, the bridge answers `409 CONFIRM_REQUIRED`
 * and the administrator confirms before it is used; anything else that fails puts the previous value
 * back and shows the bridge's reason.
 */
async function saveAuthorRoot() {
	const input = byId('pages-author-root')
	if (!input) {
		return
	}
	const root = input.value.trim()
	if (root === '') {
		input.value = accepted.authorRoot
		toast('error', t('pages', 'The author content directory must not be empty.'))
		return
	}
	if (root === accepted.authorRoot) {
		return
	}
	if (!AUTHOR_ROOT_SHAPE.test(root)) {
		input.value = accepted.authorRoot
		toast('error', t('pages', 'The author content directory must be a single folder name (letters, digits, dot, underscore, hyphen; no slash).'))
		return
	}
	const post = (confirm) => axios.post(url('/api/site/author-root'), { authorRoot: root, confirm })
	try {
		let response
		try {
			response = await post(false)
		} catch (error) {
			if (error?.response?.status === 409 && error?.response?.data?.error === 'CONFIRM_REQUIRED') {
				const ok = await ui?.confirm(
					t('pages', 'Author content directory exists'),
					[t('pages', 'The directory “%s” already exists in the site. Use it for author content?', [root])],
					t('pages', 'Use it'),
				)
				if (!ok) {
					input.value = accepted.authorRoot
					return
				}
				response = await post(true)
			} else {
				throw error
			}
		}
		accepted.authorRoot = String(response?.data?.author_root ?? root)
		input.value = accepted.authorRoot
	} catch (error) {
		input.value = accepted.authorRoot
		reportKeptPrevious(error, t('pages', 'The author content directory could not be saved.'))
	}
}

/**
 * Commit the orphan owner directory: `PUT /api/admin/config {orphanOwner}`.
 *
 * The folder receives every page whose author deleted it (转交孤儿所有者), so its name is checked for
 * shape here - one segment, at most 64 characters - before the request. Everything else is the
 * backend's answer: a name that is a real user, or one the site already uses differently, comes back
 * as a 400 whose message is what the administrator needs to read, so it is shown as it came and the
 * input reverts to the last accepted value.
 */
async function saveOrphanOwner() {
	const input = byId('pages-orphan-owner')
	if (!input) {
		return
	}
	const owner = input.value.trim()
	if (owner === accepted.orphanOwner) {
		return
	}
	if (owner === '' || owner.length > ORPHAN_OWNER_MAX || !ORPHAN_OWNER_SHAPE.test(owner)) {
		input.value = accepted.orphanOwner
		toast('error', t('pages', 'The orphan owner directory must be a single folder name (letters, digits, dot, underscore, hyphen; at most 64 characters).'))
		return
	}
	try {
		const { data } = await axios.put(url('/api/admin/config'), { orphanOwner: owner })
		accepted.orphanOwner = String(data?.config?.orphan_owner ?? owner)
		input.value = accepted.orphanOwner
	} catch (error) {
		input.value = accepted.orphanOwner
		// a name that is a real user is a 400 carrying the server's own sentence: it is the message
		reportKeptPrevious(error, t('pages', 'The orphan owner directory could not be saved.'))
	}
}

/* ------------------------------------------------------------------ ① Connection */

/**
 * Save the bridge URL: app-side only, debounced, so typing the address is one write and not ten.
 * A refused URL is put back to the last accepted one (the address is what the next request talks to).
 */
async function saveBridgeUrl() {
	const input = byId('pages-bridge-url')
	if (!input) {
		return
	}
	const next = input.value.trim()
	if (next === accepted.bridgeUrl) {
		return
	}
	try {
		const { data } = await axios.put(url('/api/admin/config'), { bridgeUrl: next })
		accepted.bridgeUrl = String(data?.config?.bridge_url ?? next)
		input.value = accepted.bridgeUrl
	} catch (error) {
		input.value = accepted.bridgeUrl
		toast('error', t('pages', 'The bridge URL could not be saved: %s', [
			failureMessage(error, t('pages', 'the bridge URL must start with http:// or https://')),
		]))
	}
}

/** The pairing flow of an unpaired bridge: URL + one-time code, then reload into the paired form. */
function wirePairing() {
	const pairButton = byId('pages-pair')
	const codeInput = byId('pages-pair-code')
	if (!pairButton) {
		return
	}
	const pair = async () => {
		const bridgeUrl = value('pages-bridge-url')
		const code = value('pages-pair-code')
		if (bridgeUrl === '' || code === '') {
			toast('error', t('pages', 'Missing bridge URL or pairing code'))
			return
		}
		pairButton.disabled = true
		try {
			const { data } = await axios.post(url('/api/bridge/pair'), { url: bridgeUrl, code })
			toast('success', t('pages', 'Paired') + ' · ' + t('pages', 'protocol v') + data.protocol)
			// the server renders a different form for a paired bridge, so reload into it
			window.setTimeout(() => window.location.reload(), 900)
		} catch (error) {
			toast('error', pairingFailureMessage(error))
		} finally {
			pairButton.disabled = false
		}
	}
	pairButton.addEventListener('click', pair)
	codeInput?.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			pair()
		}
	})
}

/** A failed pairing: the bridge's code stays the useful part (wrong code, attempts left, already paired). */
function pairingFailureMessage(error) {
	const data = error?.response?.data ?? {}
	let message = String(data.message || error?.message || t('pages', 'Pairing failed'))
	if (data.error_code === 'BAD_CODE' && typeof data.attemptsLeft === 'number') {
		message += ' · ' + t('pages', '%s attempts left', [String(data.attemptsLeft)])
	}
	if (data.error_code === 'ALREADY_PAIRED') {
		message += ' · ' + t('pages', 'The bridge is paired with another Nextcloud; clear its state and pair again.')
	}
	if (error?.response?.status === 503) {
		message += ' · ' + t('pages', 'The bridge did not answer; it may be starting. Try again in a moment.')
	}
	return message
}

/** The two secret actions of a paired bridge: rotate, or pair again with a new code. */
function wireBridgeActions() {
	const rotate = byId('pages-rotate')
	const pairAgain = byId('pages-pair-again')

	rotate?.addEventListener('click', async () => {
		const confirmed = await ui?.confirm(
			t('pages', 'Rotate secrets?'),
			[t('pages', 'Replace both secrets with new ones? The bridge is updated in the same request.')],
			t('pages', 'Rotate'),
		)
		if (!confirmed) {
			return
		}
		rotate.disabled = true
		try {
			const { data } = await axios.post(url('/api/bridge/push'), { rotate: true })
			applyFingerprints(data?.fingerprints)
			toast('success', t('pages', 'Secrets rotated and pushed'))
			if (data?.restart_required) {
				toast('warning', t('pages', 'Restart the bridge for this change to take effect.'))
			}
		} catch (error) {
			toast('error', failureMessage(error, t('pages', 'Rotating the secrets failed.')))
		} finally {
			rotate.disabled = false
		}
	})

	pairAgain?.addEventListener('click', async () => {
		const confirmed = await ui?.confirm(
			t('pages', 'Pair again?'),
			[t('pages', 'Pair again? This needs a new pairing code from bridge/install.sh.')],
			t('pages', 'Pair again'),
		)
		if (!confirmed) {
			return
		}
		// the code is a one-time secret, typed once: a prompt is enough, the confirmation is the dialog
		const code = window.prompt(t('pages', 'Pairing code'), '')
		const bridgeUrl = value('pages-bridge-url')
		if (!code || bridgeUrl === '') {
			toast('error', t('pages', 'Missing bridge URL or pairing code'))
			return
		}
		pairAgain.disabled = true
		try {
			const { data } = await axios.post(url('/api/bridge/pair'), { url: bridgeUrl, code })
			toast('success', t('pages', 'Paired') + ' · ' + t('pages', 'protocol v') + data.protocol)
			window.setTimeout(() => window.location.reload(), 900)
		} catch (error) {
			toast('error', pairingFailureMessage(error))
		} finally {
			pairAgain.disabled = false
		}
	})
}

/** Still fingerprints, never plaintext: the one line that shows what is in force. */
function applyFingerprints(fingerprints) {
	const line = byId('pages-fingerprints')
	if (!line || !fingerprints) {
		return
	}
	const shared = fingerprints.shared_secret || t('pages', '(not configured)')
	const signing = fingerprints.session_signing_secret || t('pages', '(not configured)')
	line.textContent = t('pages', 'Shared secret') + ' ' + shared
		+ ' · ' + t('pages', 'Signing secret') + ' ' + signing
}

/* ------------------------------------------------------------------ data operations */

/**
 * Apply source: rebuild the three derived trees and the preview. No commit, no push, no config
 * change.
 *
 * The request/answer cycle lives in `applySource.js` so the delete-guard confirmation can be
 * exercised without a browser; this wrapper only supplies what the page owns - the button, the
 * dialog and the toast. The answer names the three per-tree counts, because they are three
 * different trees and only one of them is what the site serves; a bridge that refuses the round
 * because it would delete most of a tree asks first, and only the confirmed second call carries
 * `force: true`.
 */
async function applySource() {
	const button = byId('pages-site-apply')
	return runApplySource({
		confirm: (title, lines, label) => ui?.confirm(title, lines, label),
		toast,
		setBusy: (busy) => {
			if (button) {
				button.disabled = busy
			}
		},
	})
}

/**
 * The one Create/Pull button: with no remote filled it creates the local repository, with one it
 * pulls. Which one it is depends only on the remote field, so the same button serves both.
 */
async function syncSite() {
	const button = byId('pages-site-sync')
	if (!button) {
		return
	}
	if (value('pages-site-repo') === '') {
		await createRepository(button)
		return
	}
	await pullRemote(button)
}

/** Create: git init + refs only; the Nextcloud folder is untouched. */
async function createRepository(button) {
	button.disabled = true
	try {
		await axios.post(url('/api/site/init'))
		toast('success', t('pages', 'Repository created locally.'))
		// the remote and the branch are locked now: reload into the initialised form
		window.setTimeout(() => window.location.reload(), 900)
	} catch (error) {
		toast('error', t('pages', 'Create failed: %s', [failureMessage(error, t('pages', 'The bridge did not answer.'))]))
	} finally {
		button.disabled = false
	}
}

/**
 * Pull: fetch the remote into the commit tree, let the app replace the Nextcloud folder with the
 * archived content and rebuild the preview.
 *
 * An empty Nextcloud folder is filled without a dialog; a folder that has content is only replaced
 * after a confirmation that names how far the remote is ahead and how many local changes the backup
 * keeps. The numbers come from the server right before the dialog opens.
 */
async function pullRemote(button) {
	button.disabled = true
	try {
		const { data: preview } = await axios.get(url('/api/site/pull/preview'))
		if (preview?.source_has_content === true) {
			const ahead = preview?.remote_ahead
			const local = Number(preview?.local_unpublished ?? 0)
			const aheadText = typeof ahead === 'number'
				? t('pages', 'The remote is %s commits ahead.', [String(ahead)])
				: t('pages', 'The number of new commits on the remote is unknown (the remote did not answer).')
			const confirmed = await ui?.confirm(
				t('pages', 'Pull from the remote?'),
				[
					aheadText,
					t('pages', 'The source in Nextcloud will be replaced with the remote content: %s local unpublished change(s) are backed up first, then overwritten.', [String(local)]),
				],
				t('pages', 'Pull'),
			)
			if (!confirmed) {
				return
			}
		}
		const { data } = await axios.post(url('/api/site/pull'))
		const bridge = data?.bridge
		toast(bridge && bridge.ok === false ? 'warning' : 'success',
			t('pages', 'Pulled %1$s files (%2$s skipped) · backup: %3$s', [
				String(data?.files ?? 0),
				String(data?.skipped ?? 0),
				String(data?.backed_up_to ?? '?'),
			])
				+ (bridge && bridge.ok === false
					? ' · ' + t('pages', 'The preview could not be rebuilt:') + ' ' + (bridge.message || '?')
					: ''))
		window.setTimeout(() => window.location.reload(), 1500)
	} catch (error) {
		toast('error', t('pages', 'Pull failed: %s', [failureMessage(error, t('pages', 'The remote could not be pulled.'))]))
	} finally {
		button.disabled = false
	}
}

/**
 * Import: the archive replaces the whole site folder.
 *
 * The confirmation comes first, before the upload: the folder is emptied, and a click that only meant
 * to inspect a file must not be able to do that.
 */
async function runImport() {
	const picker = byId('pages-site-import-file')
	const file = picker && picker.files && picker.files.length > 0 ? picker.files[0] : null
	if (!file) {
		toast('error', t('pages', 'Choose a zip or tar.gz archive first.'))
		return
	}
	const confirmed = await ui?.confirm(
		t('pages', 'Import source?'),
		[t('pages', 'Replace the whole site folder with this archive? It is backed up first, then emptied and written from the archive. Anything not in the archive is gone.')],
		t('pages', 'Import'),
	)
	if (!confirmed) {
		return
	}
	const button = byId('pages-site-import')
	if (button) {
		button.disabled = true
	}
	const form = new FormData()
	form.append('archive', file)
	try {
		const { data } = await axios.post(url('/api/site/source/import'), form, {
			headers: { 'Content-Type': 'multipart/form-data' },
		})
		const bridge = data?.bridge
		toast(bridge && bridge.ok === false ? 'warning' : 'success',
			t('pages', 'Imported %1$s files (%2$s skipped) · backup: %3$s', [
				String(data?.files ?? 0),
				String(data?.skipped ?? 0),
				String(data?.backed_up_to ?? '?'),
			])
				+ (bridge && bridge.ok === false
					? ' · ' + t('pages', 'The preview could not be rebuilt:') + ' ' + (bridge.message || '?')
					: ''))
		if (picker) {
			picker.value = ''
		}
		// the source changed underneath the page: the summary and the counts are stale
		window.setTimeout(() => window.location.reload(), 1500)
	} catch (error) {
		toast('error', t('pages', 'Import failed: %s', [failureMessage(error, t('pages', 'The archive was not accepted.'))]))
	} finally {
		if (button) {
			button.disabled = false
		}
	}
}

/* ------------------------------------------------------------------ danger zone */

/**
 * Reset Pages: the dialog explains both halves, takes the administrator's password and only then runs
 * the request. The server verifies the password and answers 401 without running anything on a mismatch;
 * the failure stays inside the dialog so it can be corrected. On success the page reloads, because
 * every control on it was configured a moment ago and no longer is.
 */
async function resetPages() {
	const button = byId('pages-reset')
	if (button) {
		button.disabled = true
	}
	let pending = false
	try {
		const confirmed = await ui?.openReset(async (password) => {
			const { data } = await axios.post(url('/api/admin/reset'), { password })
			pending = data?.pending === true
		})
		if (!confirmed) {
			return
		}
		if (pending) {
			// the app is reset and unpaired; the bridge cleanup is retried by running this again
			toast('warning', t('pages', 'Pages was reset, but the bridge could not be cleaned up. Start it and run the reset once more; both sides are idempotent.'))
			window.setTimeout(() => window.location.reload(), 5000)
			return
		}
		window.location.reload()
	} finally {
		if (button) {
			button.disabled = false
		}
	}
}

/* ------------------------------------------------------------------ permissions */

/** The groups of one capability, from the hidden input the pickers write into. */
function grantGroups(inputId) {
	try {
		const value = JSON.parse(byId(inputId)?.value || '[]')
		return Array.isArray(value) ? value.map(String) : []
	} catch {
		// unreadable means "no grant stored here", which is the same default an empty list means
		return []
	}
}

/** Write one capability's list into the hidden input the save path reads. */
function writeGrantValue(inputId, groups) {
	const input = byId(inputId)
	if (input) {
		input.value = JSON.stringify(Array.isArray(groups) ? groups : [])
	}
}

let grantRows = {}

/**
 * Save both grant lists immediately: one PUT, the shape the endpoint expects, no button.
 *
 * Both lists always travel together (they are what the two pickers show). A failure puts both pickers
 * back to the last accepted lists, so the page cannot show a grant the server does not hold.
 */
async function saveGrants() {
	const payload = {
		authorGroups: grantGroups('pages-author-groups'),
		reviewGroups: grantGroups('pages-review-groups'),
	}
	try {
		await axios.put(url('/api/admin/config'), payload)
		accepted.grants = { author: [...payload.authorGroups], review: [...payload.reviewGroups] }
	} catch (error) {
		const fallback = accepted.grants ?? { author: [], review: [] }
		for (const [id, groups] of [['pages-author-groups', fallback.author], ['pages-review-groups', fallback.review]]) {
			writeGrantValue(id, groups)
			if (grantRows[id]) {
				grantRows[id].value = [...groups]
			}
		}
		toast('error', t('pages', 'The permissions could not be saved: %s', [
			failureMessage(error, t('pages', 'the group list was refused')),
		]))
	}
}

/**
 * The capability pickers (src/GrantPicker.vue) mounted into #pages-grants.
 *
 * The server renders the grants twice: into the hidden inputs (what the save path reads) and into the
 * JSON block the pickers start from. That repetition is the fallback — a picker that never mounts
 * leaves the hidden inputs carrying what the server rendered, so a grant save sends the stored values
 * instead of clearing them.
 */
function mountGrants() {
	const host = byId('pages-grants')
	if (!host) {
		return
	}
	let state
	try {
		state = JSON.parse(byId('pages-grants-state')?.textContent || '{}')
	} catch {
		// unreadable state: nothing to hand to the pickers, the hidden inputs stay as rendered
		return
	}
	const options = Array.isArray(state?.options) ? state.options : []
	/*
	 * The rows are reactive on purpose: NcSelect is controlled through modelValue, so the list it
	 * emits has to come back down as the new prop — otherwise the tag it just added would disappear
	 * again on the next render.
	 */
	const rows = reactive((Array.isArray(state?.grants) ? state.grants : []).map((grant) => ({
		id: String(grant?.id || ''),
		label: String(grant?.label || ''),
		placeholder: String(grant?.placeholder || ''),
		value: Array.isArray(grant?.value) ? grant.value.map(String) : [],
	})).filter((row) => row.id !== ''))
	if (rows.length === 0) {
		return
	}
	grantRows = Object.fromEntries(rows.map((row) => [row.id, row]))
	accepted.grants = {
		author: grantGroups('pages-author-groups'),
		review: grantGroups('pages-review-groups'),
	}

	try {
		createApp({
			name: 'PagesGrants',
			setup() {
				return () => h('div', { class: 'pages-grants__rows' }, rows.map((row) => h(GrantPicker, {
					key: row.id,
					label: row.label,
					options,
					placeholder: row.placeholder,
					modelValue: row.value,
					'onUpdate:modelValue': (value) => {
						row.value = value
						writeGrantValue(row.id, value)
						saveGrants()
					},
				})))
			},
		}).mount(host)
	} catch (error) {
		// the pickers are a convenience; the hidden inputs keep the save path working without them
		console.error('Pages: the permission pickers did not mount; saving the rendered grants.', error)
	}
}

/* ------------------------------------------------------------------ summary */

/**
 * The facts-only summary under the data operations: the commit tree's HEAD and how far the remote is
 * ahead. Both come from the bridge's own status answer (no fetch, no build), so opening the page does
 * not touch the remote; a failure leaves the placeholders, which is honest and cannot block the page.
 */
async function refreshFacts() {
	const head = byId('pages-site-head')
	const ahead = byId('pages-site-ahead')
	if (!head || !ahead) {
		return
	}
	try {
		const { data } = await axios.get(url('/api/site/status'))
		head.textContent = String(data?.head || '') || '—'
		if (data?.remote_configured === false) {
			ahead.textContent = t('pages', 'no remote')
		} else if (typeof data?.remote_ahead === 'number') {
			ahead.textContent = String(data.remote_ahead)
		} else {
			ahead.textContent = '—'
		}
	} catch (error) {
		// the summary is decoration on a page that must keep working: its placeholders stay
		console.debug('Pages: the site summary could not be read', error)
	}
}

/* ------------------------------------------------------------------ wiring */

/** Mount the dialogs/toasts component once; its public methods are the page's only UI channel. */
function mountUI() {
	const host = byId('pages-settings-ui')
	if (!host) {
		return null
	}
	if (host.dataset.mounted === '1') {
		return null
	}
	host.dataset.mounted = '1'
	return createApp(SettingsUI).mount(host)
}

/** Seed the accepted values from what the server rendered: the inputs are the truth on first paint. */
function seedAccepted() {
	accepted.site = readSite()
	accepted.authorRoot = value('pages-author-root')
	accepted.orphanOwner = value('pages-orphan-owner')
	accepted.bridgeUrl = value('pages-bridge-url')
}

/** The site block: all four fields commit the whole block when one of them loses focus. */
function wireSite() {
	const ids = ['pages-site-nc-user', 'pages-site-nc-path', 'pages-site-repo', 'pages-site-branch']
	for (const id of ids) {
		const input = byId(id)
		if (!input) {
			continue
		}
		input.addEventListener('blur', saveSiteBlock)
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault()
				input.blur()
			}
		})
	}
}

function wireAuthorRoot() {
	const input = byId('pages-author-root')
	input?.addEventListener('blur', saveAuthorRoot)
	input?.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			input.blur()
		}
	})
}

/** The orphan owner directory: the same write-through as the author directory, one field. */
function wireOrphanOwner() {
	const input = byId('pages-orphan-owner')
	input?.addEventListener('blur', saveOrphanOwner)
	input?.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault()
			input.blur()
		}
	})
}

/** The bridge URL: one debounced write after the last keystroke. */
function wireBridgeUrl() {
	const input = byId('pages-bridge-url')
	if (!input) {
		return
	}
	let timer = null
	input.addEventListener('input', () => {
		window.clearTimeout(timer)
		timer = window.setTimeout(saveBridgeUrl, AUTO_SAVE_DELAY)
	})
	input.addEventListener('blur', () => {
		window.clearTimeout(timer)
		saveBridgeUrl()
	})
}

function wireDataOps() {
	byId('pages-site-apply')?.addEventListener('click', applySource)
	byId('pages-site-sync')?.addEventListener('click', syncSite)
	byId('pages-site-import')?.addEventListener('click', runImport)
	byId('pages-reset')?.addEventListener('click', resetPages)
}

/**
 * The Create/Pull label follows the remote field until the repository is initialised (after that the
 * remote is locked, so the label cannot change any more).
 */
function wireSyncLabel() {
	const repo = byId('pages-site-repo')
	const button = byId('pages-site-sync')
	const site = byId('pages-site')
	if (!repo || !button || site?.dataset.initialized === '1') {
		return
	}
	const update = () => {
		button.textContent = repo.value.trim() === '' ? t('pages', 'Create') : t('pages', 'Pull')
	}
	repo.addEventListener('input', update)
	update()
}

function wire() {
	if (!panel()) {
		return
	}
	ui = mountUI()
	seedAccepted()
	wireBridgeUrl()
	wirePairing()
	wireBridgeActions()
	wireSite()
	wireSyncLabel()
	wireAuthorRoot()
	wireOrphanOwner()
	wireDataOps()
	mountGrants()
	refreshFacts()
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', wire)
} else {
	wire()
}
