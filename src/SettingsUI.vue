<!--
	The transient UI of the admin settings page: result toasts and the two confirmation dialogs.

	The page itself is server-rendered HTML with a plain-DOM script (src/admin.js); this component owns
	only what needs a real dialog: the confirmations for the author content directory, rotating the
	secrets, pairing again, Import, Pull and the password-confirmed reset, plus the result toasts that
	replace the inline feedback lines of the old page.

	It is mounted once into #pages-settings-ui and driven through its methods, which return promises:
	`confirm(name, lines, label)` resolves true/false, and `openReset(submit)` resolves true once the
	reset ran (the caller reloads) or false when it was cancelled. The reset dialog keeps itself open on
	a failure and shows the server's message inline - a wrong password must be correctable without
	starting over.
-->
<template>
	<div class="pages-settings-ui">
		<div class="pages-settings-ui__toasts" role="status" aria-live="polite">
			<NcNoteCard v-for="entry in toasts" :key="entry.id" :type="entry.type" class="pages-settings-ui__toast">
				{{ entry.message }}
			</NcNoteCard>
		</div>

		<NcDialog
			v-if="confirmOpen"
			:name="confirmName"
			:buttons="confirmButtons"
			@closing="answerConfirm(false)">
			<p v-for="(line, index) in confirmLines" :key="index">{{ line }}</p>
		</NcDialog>

		<NcDialog
			v-if="resetOpen"
			:name="t('pages', 'Reset Pages?')"
			:buttons="resetButtons"
			@closing="answerReset(false)">
			<p>
				{{ t('pages', 'This clears the Pages configuration on both sides and returns the app to a fresh install.') }}
			</p>
			<ul>
				<li>{{ t('pages', 'Cleared here: the visibility manifest, the article cache, the site settings, the grants and the pairing.') }}</li>
				<li>{{ t('pages', 'Cleared on the bridge: its stored site configuration, and its work and preview copies of the site — deleted outright.') }}</li>
				<li>{{ t('pages', 'Kept: your files. The site folder and every author’s Pages folder are never touched.') }}</li>
				<li>{{ t('pages', 'Backed up first: the app state in its appdata folder. The bridge’s trees are derived data: deleted, and rebuilt from the Nextcloud folder by the mirror and the build.') }}</li>
			</ul>
			<label class="pages-settings-ui__label" for="pages-reset-password">
				{{ t('pages', 'Nextcloud password') }}
			</label>
			<input
				id="pages-reset-password"
				v-model="resetPassword"
				class="pages-settings-ui__input"
				type="password"
				autocomplete="current-password"
				:disabled="resetBusy" />
			<p class="pages-settings-ui__hint">
				{{ t('pages', 'The password is only used to confirm this action; it is not stored.') }}
			</p>
			<p v-if="resetError" class="pages-settings-ui__error">{{ resetError }}</p>
		</NcDialog>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'PagesSettingsUI',

	components: {
		NcDialog,
		NcNoteCard,
	},

	data() {
		return {
			/** The result toasts, oldest first; each one removes itself after a few seconds */
			toasts: [],
			toastSeq: 0,
			toastTimers: [],

			/** The confirmation dialog: `{name, lines, label}` while it is open */
			confirmOpen: false,
			confirmName: '',
			confirmLines: [],
			confirmLabel: '',
			/** The promise resolver of the open confirmation */
			confirmResolve: null,

			/** The reset dialog */
			resetOpen: false,
			resetPassword: '',
			resetError: '',
			resetBusy: false,
			/** The caller's submit function and the promise resolver of the open reset dialog */
			resetSubmit: null,
			resetResolve: null,
		}
	},

	computed: {
		confirmButtons() {
			return [
				{ label: t('pages', 'Cancel'), callback: () => this.answerConfirm(false) },
				{ label: this.confirmLabel, variant: 'primary', callback: () => this.answerConfirm(true) },
			]
		},

		resetButtons() {
			return [
				{ label: t('pages', 'Cancel'), callback: () => this.answerReset(false) },
				{ label: t('pages', 'Reset Pages'), variant: 'error', callback: () => this.submitReset() },
			]
		},
	},

	beforeUnmount() {
		for (const timer of this.toastTimers) {
			window.clearTimeout(timer)
		}
	},

	methods: {
		t,

		/**
		 * Show one result toast; it fades on its own, because a settings page should never need a
		 * dismiss click for "saved".
		 *
		 * @param {'success'|'error'|'warning'|'info'} type the Nextcloud note-card kind
		 * @param {string} message the sentence to show
		 * @param {number} [timeout] milliseconds; a failure stays longer than a success
		 */
		toast(type, message, timeout = 8000) {
			this.toastSeq += 1
			const id = this.toastSeq
			this.toasts = [...this.toasts, { id, type, message }]
			const timer = window.setTimeout(() => {
				this.toasts = this.toasts.filter((entry) => entry.id !== id)
			}, timeout)
			this.toastTimers.push(timer)
		},

		/**
		 * Ask one question and resolve with the answer.
		 *
		 * @param {string} name the dialog title
		 * @param {string[]} lines the explanatory paragraphs
		 * @param {string} label the confirm button
		 * @returns {Promise<boolean>}
		 */
		confirm(name, lines, label) {
			this.confirmName = name
			this.confirmLines = Array.isArray(lines) ? lines : [String(lines ?? '')]
			this.confirmLabel = label
			this.confirmOpen = true
			return new Promise((resolve) => {
				this.confirmResolve = resolve
			})
		},

		/** Close the confirmation; the first answer wins (closing fires another one). */
		answerConfirm(value) {
			if (!this.confirmOpen) {
				return
			}
			this.confirmOpen = false
			const resolve = this.confirmResolve
			this.confirmResolve = null
			if (resolve) {
				resolve(value === true)
			}
		},

		/**
		 * Open the reset dialog. `submit` is called with the password and must reject with the
		 * server's error; the dialog stays open and shows the message so a wrong password can be
		 * corrected in place.
		 *
		 * @param {(password: string) => Promise<void>} submit the caller's reset request
		 * @returns {Promise<boolean>}
		 */
		openReset(submit) {
			this.resetSubmit = submit
			this.resetPassword = ''
			this.resetError = ''
			this.resetBusy = false
			this.resetOpen = true
			return new Promise((resolve) => {
				this.resetResolve = resolve
			})
		},

		/** Send the reset once the password is there; failures stay inside the dialog. */
		async submitReset() {
			if (this.resetBusy) {
				return
			}
			if (this.resetPassword.trim() === '') {
				this.resetError = t('pages', 'Enter your Nextcloud password; nothing was reset.')
				return
			}
			this.resetBusy = true
			this.resetError = ''
			try {
				await this.resetSubmit(this.resetPassword)
				this.resetOpen = false
				const resolve = this.resetResolve
				this.resetResolve = null
				if (resolve) {
					resolve(true)
				}
			} catch (error) {
				this.resetError = String(
					error?.response?.data?.message || error?.message || t('pages', 'The reset failed.'),
				)
			} finally {
				this.resetBusy = false
			}
		},

		/** Cancel the reset; the caller's pending request (if any) is what it is. */
		answerReset(value) {
			if (!this.resetOpen) {
				return
			}
			this.resetOpen = false
			const resolve = this.resetResolve
			this.resetResolve = null
			if (resolve) {
				resolve(value === true)
			}
		},
	},
}
</script>

<style scoped>
.pages-settings-ui__toasts {
	position: fixed;
	inset-block-end: 24px;
	inset-inline-end: 24px;
	z-index: 10000;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 420px;
}

.pages-settings-ui__toast {
	margin: 0;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.pages-settings-ui__label {
	display: block;
	margin-block-start: 8px;
	font-weight: 600;
}

.pages-settings-ui__input {
	width: 100%;
	margin-block: 4px 2px;
}

.pages-settings-ui__hint {
	font-size: 12px;
	opacity: 0.75;
	margin-block: 2px 0;
}

.pages-settings-ui__error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
	margin-block-start: 6px;
}
</style>
