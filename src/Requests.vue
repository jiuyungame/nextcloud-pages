<!--
	The request panel: 上线请求 — the pages an author asked to put on the site or take off it.

	One list, because there is one decision: every row is a `requested` entry with the direction of the
	author's intent (`online` / `offline`) and the note a previous decision left. Approving applies the
	intent (online → external, offline → internal), rejecting puts the entry back to `internal` with the
	reason typed next to it. A whole batch is confirmed first, in a dialog that names what it touches.

	The section is only mounted when the caller holds the review grant (审核与发布, which is also the
	publishing grant), and every call can still answer 403 (the grant can be taken away while the page
	is open), so all actions route through the same guard: a 403 hides the section and says so, and never
	leaves a half-updated list behind. Its publish button is a request to the page (`$emit('publish')`),
	so the one publish implementation - and its confirmation - stays in App.vue.

	Rejecting asks for the reason inline (a text field inside the row) — never window.prompt(), which
	cannot be styled, cannot be translated in place, and blocks the page.
-->
<template>
	<div class="pages-requests">
		<div class="pages-requests__head">
			<h2 class="pages-requests__title">{{ t('pages', 'Requests to go live') }}</h2>
			<span v-if="summary" class="pages-requests__summary">{{ summary }}</span>
			<span class="pages-requests__spacer" />

			<NcButton :disabled="!rows.length || loading" @click="refresh">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('pages', 'Refresh') }}
			</NcButton>

			<NcButton
				variant="primary"
				:disabled="!paths.length || loading"
				@click="startBatch('approve')">
				<template #icon><CheckAll :size="20" /></template>
				{{ t('pages', 'Approve all (%s)', [String(paths.length)]) }}
			</NcButton>

			<NcButton
				variant="secondary"
				:disabled="!paths.length || loading"
				@click="startBatch('reject')">
				<template #icon><Undo :size="20" /></template>
				{{ t('pages', 'Reject all (%s)', [String(paths.length)]) }}
			</NcButton>

			<!--
				The publish that carries the decisions out. It does NOT call the endpoint: it asks the
				page (App.vue) to run its one publish path, so the confirmation, the result banner and
				the code that talks to /api/site/publish exist exactly once.
			-->
			<NcButton variant="primary" :disabled="loading" @click="$emit('publish')">
				<template #icon><Upload :size="20" /></template>
				{{ t('pages', 'Publish') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="notice" :type="notice.type" class="pages-requests__notice">
			{{ notice.message }}
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error" class="pages-requests__notice">
			{{ error }}
		</NcNoteCard>

		<p v-if="!loading && !error && rows.length === 0" class="pages-requests__empty">
			{{ t('pages', 'Nothing is waiting for a decision.') }}
		</p>

		<ArticleRows
			v-if="rows.length"
			:rows="rows"
			show-author
			show-waiting
			show-review-note
			show-live
			:waiting-header="t('pages', 'Requested')">
			<template #actions="{ article }">
				<NcButton
					variant="primary"
					:disabled="busy.has(rowKey(article))"
					@click="decide(article, 'approve')">
					<template #icon><Check :size="20" /></template>
					{{ t('pages', 'Approve') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="busy.has(rowKey(article)) || rejecting.has(rowKey(article))"
					@click="startReject(article)">
					<template #icon><Undo :size="20" /></template>
					{{ t('pages', 'Reject') }}
				</NcButton>
			</template>
		</ArticleRows>

		<!--
			The inline reason field. It replaces nothing: the row above stays visible, so the reviewer
			still sees the title and the author while writing the note.
		-->
		<div v-for="article in rows.filter((row) => rejecting.has(rowKey(row)))"
			:key="'reject-' + rowKey(article)"
			class="pages-requests__reject">
			<label class="pages-requests__reject-label" :for="'pages-reject-' + rowKey(article)">
				{{ t('pages', 'Reason for rejecting “%s”', [article.title || rowKey(article)]) }}
			</label>
			<textarea
				:id="'pages-reject-' + rowKey(article)"
				v-model="notes[rowKey(article)]"
				class="pages-requests__reject-input"
				rows="2"
				:placeholder="t('pages', 'What has to change?')" />
			<div class="pages-requests__reject-actions">
				<NcButton
					variant="primary"
					:disabled="busy.has(rowKey(article))"
					@click="decide(article, 'reject')">
					<template #icon>
						<NcLoadingIcon v-if="busy.has(rowKey(article))" :size="20" />
						<Undo v-else :size="20" />
					</template>
					{{ t('pages', 'Reject the request') }}
				</NcButton>
				<NcButton variant="tertiary" @click="cancelReject(article)">
					{{ t('pages', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<!--
			The batch confirmation: the two batch actions touch many entries at once, so each one names
			what it will do and which entries it will do it to before a single call is made.
		-->
		<NcDialog
			v-if="batch"
			:name="batchName"
			:buttons="batchButtons"
			@closing="batch = null">
			<p>{{ batchHint }}</p>
			<ul class="pages-requests__batch-list">
				<li v-for="path in batch.paths.slice(0, 10)" :key="path">{{ path }}</li>
			</ul>
			<p v-if="batch.paths.length > 10" class="pages-requests__muted">
				{{ t('pages', '%s more', [String(batch.paths.length - 10)]) }}
			</p>
			<label v-if="batch.action === 'reject'" class="pages-requests__reject-label" for="pages-batch-note">
				{{ t('pages', 'Reason for rejecting these requests') }}
			</label>
			<textarea
				v-if="batch.action === 'reject'"
				id="pages-batch-note"
				v-model="batch.note"
				class="pages-requests__reject-input"
				rows="2"
				:placeholder="t('pages', 'What has to change?')" />
			<p v-if="batchBusy" class="pages-requests__muted">{{ batchProgress }}</p>
		</NcDialog>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Check from 'vue-material-design-icons/Check.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

import api from './api.js'
import ArticleRows from './ArticleRows.vue'
import { intentOf, reasonFor, rowKey } from './articles.js'

export default {
	name: 'RequestsView',

	components: {
		ArticleRows,
		Check,
		CheckAll,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
		Undo,
		Upload,
	},

	// `publish` is a request to the page, not an action of its own: the one publish implementation
	// (and its confirmation) lives in App.vue, so this panel cannot grow a second code path.
	emits: ['forbidden', 'decided', 'publish'],

	props: {
		/** The `<author_root>/<uid>/` prefix the tree reports, so rows can show `Pages/…`. */
		targetPrefix: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The `requested` entries as the article list reports them */
			articles: [],
			loading: false,
			/** Keys of the rows with a decision in flight */
			busy: new Set(),
			/** Keys of the rows whose reason field is open */
			rejecting: new Set(),
			/** Key → the reason being typed, kept across re-renders */
			notes: {},
			/** The batch confirmation: `{action, paths, note}` while the dialog is open */
			batch: null,
			batchBusy: false,
			batchProgress: '',
			notice: null,
			noticeTimer: null,
			error: '',
		}
	},

	computed: {
		/**
		 * The rows as the table shows them: the target path, the label an author reads, and the
		 * intent of the request. A computed, not data, so the label follows the prefix.
		 */
		rows() {
			return this.articles.map((article) => ({
				...article,
				pathLabel: this.displayPath(article.path),
				intent: intentOf(article),
			}))
		},

		/** Every path that can be decided in one go. */
		paths() {
			return this.rows.map((row) => rowKey(row)).filter((path) => path !== '')
		},

		/** How many requests wait, split by the direction the authors asked for. */
		summary() {
			if (!this.rows.length) {
				return ''
			}
			const online = this.rows.filter((row) => row.intent === 'online').length
			const offline = this.rows.filter((row) => row.intent === 'offline').length
			return t('pages', '%1$s request(s): %2$s online · %3$s offline', [
				String(this.rows.length),
				String(online),
				String(offline),
			])
		},

		batchName() {
			if (!this.batch) {
				return ''
			}
			return this.batch.action === 'approve'
				? t('pages', 'Approve %s request(s)?', [String(this.batch.paths.length)])
				: t('pages', 'Reject %s request(s)?', [String(this.batch.paths.length)])
		},

		batchHint() {
			return this.batch && this.batch.action === 'approve'
				? t('pages', 'An online request puts the page on the site with the next publish; an offline request takes it off the site with the next publish.')
				: t('pages', 'A rejected request puts the page back to internal. It is not on the site afterwards.')
		},

		batchButtons() {
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => { this.batch = null },
				},
				{
					label: this.batch && this.batch.action === 'approve' ? t('pages', 'Approve all') : t('pages', 'Reject all'),
					variant: 'primary',
					callback: () => { this.runBatch() },
				},
			]
		},
	},

	beforeUnmount() {
		window.clearTimeout(this.noticeTimer)
	},

	mounted() {
		this.refresh()
	},

	methods: {
		t,
		rowKey,

		/** The path as a reviewer should read it: `Pages/<rest>`. The target stays what the actions use. */
		displayPath(path) {
			const value = String(path || '')
			const rest = this.targetPrefix !== '' && value.startsWith(this.targetPrefix)
				? value.slice(this.targetPrefix.length)
				: value
			return t('pages', 'Pages') + (rest === '' ? '' : '/' + rest.replace(/^\/+/, ''))
		},

		/** The pending requests, from the same list every reader sees (`?state=requested`). */
		async refresh() {
			this.loading = true
			const result = await api.visibilityRequests()
			this.loading = false
			if (!result.ok) {
				// No grant (any more): the section is not for this caller, so it goes away quietly
				if (result.status === 403 || result.status === 401) {
					this.hideSection(t('pages', 'You do not have permission to decide about requests.'))
					return
				}
				this.error = reasonFor(result)
				return
			}
			this.error = ''
			this.articles = Array.isArray(result.data?.articles) ? result.data.articles : []
			// A row that is no longer waiting cannot be waiting for a reason any more
			const present = new Set(this.articles.map((row) => rowKey(row)))
			this.rejecting = new Set(Array.from(this.rejecting).filter((key) => present.has(key)))
		},

		/** One row: approve, or reject with the reason typed next to it. */
		async decide(article, action) {
			const path = rowKey(article)
			if (!path || this.busy.has(path)) {
				return
			}
			const note = action === 'reject' ? String(this.notes[path] || '').trim() : ''
			await this.decideOne(path, action, note)
			if (action === 'reject') {
				delete this.notes[path]
				this.cancelReject(article)
			}
			await this.refresh()
			this.$emit('decided')
		},

		/** One decision call, with the shared busy flag and the failure handling. */
		async decideOne(path, action, note) {
			this.markBusy([path], true)
			const result = await api.decideRequest(path, action, note)
			this.markBusy([path], false)
			if (!result.ok) {
				if (result.status === 403 || result.status === 401) {
					this.hideSection(t('pages', 'You do not have permission to decide about requests.'))
					return false
				}
				this.showNotice('error', reasonFor(result))
				return false
			}
			// An approval changes what the site has, so the bridge is part of the outcome: a failure
			// there is reported instead of being reported as a success that did not happen.
			const bridge = result.data?.bridge
			if (bridge && (bridge.pushed === false || bridge.notified === false)) {
				this.showNotice('warning', t('pages', 'The decision is recorded, but the bridge could not be updated: %s', [String(bridge.message || bridge.error || '')]))
			} else {
				this.showNotice('success', action === 'approve'
					? t('pages', 'Approved: %s', [path])
					: t('pages', 'Rejected: %s is back to internal.', [path]))
			}

			return true
		},

		startReject(article) {
			const next = new Set(this.rejecting)
			next.add(rowKey(article))
			this.rejecting = next
			if (this.notes[rowKey(article)] === undefined) {
				this.notes[rowKey(article)] = ''
			}
		},

		cancelReject(article) {
			const next = new Set(this.rejecting)
			next.delete(rowKey(article))
			this.rejecting = next
		},

		/** Open the confirmation for the whole list; nothing runs until it is confirmed. */
		startBatch(action) {
			const paths = this.paths
			if (!paths.length) {
				return
			}
			this.batch = { action, paths, note: '' }
		},

		/**
		 * Run the confirmed batch one entry at a time: the server sees ordinary single-entry decisions,
		 * and one failure cannot hide the others.
		 */
		async runBatch() {
			const batch = this.batch
			this.batch = null
			if (!batch) {
				return
			}
			this.batchBusy = true
			const failures = []
			let done = 0
			try {
				for (const path of batch.paths) {
					done += 1
					this.batchProgress = t('pages', 'Working… %1$s/%2$s', [String(done), String(batch.paths.length)])
					const ok = await this.decideOne(path, batch.action, batch.note || '')
					if (!ok) {
						failures.push(path)
					}
				}
			} finally {
				this.batchBusy = false
				this.batchProgress = ''
			}
			if (failures.length === 0) {
				this.showNotice('success', t('pages', 'Done: %s request(s).', [String(batch.paths.length)]))
			} else {
				this.showNotice('error', t('pages', 'Done: %1$s succeeded, %2$s failed.', [
					String(batch.paths.length - failures.length),
					String(failures.length),
				]))
			}
			await this.refresh()
			this.$emit('decided')
		},

		markBusy(paths, value) {
			const next = new Set(this.busy)
			for (const path of paths) {
				if (value) {
					next.add(path)
				} else {
					next.delete(path)
				}
			}
			this.busy = next
		},

		/** Losing the grant hides the whole section; the page keeps working without it. */
		hideSection(reason) {
			this.articles = []
			this.$emit('forbidden', reason)
		},

		showNotice(type, message) {
			this.notice = { type, message }
			window.clearTimeout(this.noticeTimer)
			this.noticeTimer = window.setTimeout(() => {
				this.notice = null
			}, 10000)
		},
	},
}
</script>

<style scoped>
.pages-requests {
	display: flex;
	flex-direction: column;
	/* Takes the height left under the toolbar and scrolls itself, like the editor pane does. */
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
	padding: 12px 16px 24px;
}

.pages-requests__head {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.pages-requests__title {
	margin: 0;
	font-size: 18px;
}

.pages-requests__summary,
.pages-requests__muted {
	font-size: 12px;
	opacity: 0.7;
}

.pages-requests__spacer {
	flex: 1 1 auto;
}

.pages-requests__notice {
	margin-bottom: 8px;
}

.pages-requests__empty {
	opacity: 0.7;
}

.pages-requests__reject {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px 10px 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-dark);
}

.pages-requests__reject-label {
	font-size: 12px;
	font-weight: 600;
}

.pages-requests__reject-input {
	width: 100%;
	font-family: inherit;
	font-size: 13px;
	resize: vertical;
}

.pages-requests__reject-actions {
	display: flex;
	gap: 8px;
}

.pages-requests__batch-list {
	margin: 6px 0;
	padding-inline-start: 18px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	overflow-wrap: anywhere;
}
</style>
