<!--
	The request panel: what waits for a reviewer.

	One list, two kinds of pending entry, because both are answered by the same decision endpoint.
	Since the folders are the truth, neither kind is a state of its own any more, and the two are
	read through two filters of the same list endpoint:

	  - **candidate** (`candidate: true`, `?awaiting=1`): the bytes frozen when an author asked - a
	    first publication on an internal entry, or an update of a page the site already serves
	    (`published: true`). The site keeps serving the old version until the candidate is approved;
	    the preview button shows the version that would replace it (`?tree=onlinepreview`).
	    "Approve" publishes the candidate with the next publish, "Reject" discards it and leaves the
	    entry as it was.
	  - **downRequest** (`downRequest: true`, `state: 'requested'`, `?state=requested`): the author
	    asked for a page the site serves to come off it. "Approve take-off" takes it off with the next
	    publish; "Reject" leaves it online (or transfers it to the orphan owner directory when the
	    author already deleted the file).

	An entry never carries both requests, so the two lists cannot overlap in practice; the panel
	still merges them by target, because one endpoint answering both shapes must not produce two rows
	for the same file.

	Every row shows the complete stored target, exactly as the list reports `article.path` - not the
	author's own `Pages/…` reading of it. Requests of several authors share one table, so the prefix
	is itself the information (`authors-src/alice/…` next to `tmp/bob/…`), and the author column
	already names whose file each row is.

	The section is only mounted when the caller holds the review grant (审核与发布, which is also the
	publishing grant), and every call can still answer 403 (the grant can be taken away while the page
	is open), so all actions route through the same guard: a 403 hides the section and says so, and never
	leaves a half-updated list behind. Its publish button is a request to the page (`$emit('publish')`),
	so the one publish implementation - and its confirmation - stays in App.vue.

	Rejecting asks for the reason inline (a text field inside the row) — never window.prompt(), which
	cannot be styled, cannot be translated in place, and blocks the page. A rejected candidate or
	take-off keeps the same field: the note is stored on the entry, so the author can see why their
	request did not go through.

	The preview needs the site's preview token, so the panel asks for it once (`api.session()`) and
	builds the iframe source from that `base` plus the row's own `url`. A row without a `url` (a file
	the site does not render) has no preview button.
-->
<template>
	<div class="pages-requests">
		<div class="pages-requests__head">
			<h2 class="pages-requests__title">{{ t('pages', 'Requests to go live') }}</h2>
			<!-- How many entries wait, split by what they would do: an online/update request or a take-off. -->
			<span v-if="summary" class="pages-requests__summary">{{ summary }}</span>
			<!-- The other count a reviewer acts on: how much is already approved for the site. -->
			<span v-if="approvedCount !== null" class="pages-requests__summary">
				{{ t('pages', 'Approved for the site: %s', [String(approvedCount)]) }}
			</span>
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
				page (App.vue) to run its one publish path, so the confirmation, the result toast and
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

		<!--
			No push-retry mark here: `pushFailed` on these rows is the server's "the last publish's
			push failed" flag on a live entry, not the author's own configuration push the shared
			table's retry repeats. That retry endpoint is addressed to the caller's own author area,
			so it cannot clear the flag - a push is only attempted by the publish flow.
		-->
		<ArticleRows
			v-if="rows.length"
			:rows="rows"
			show-author
			show-waiting
			show-review-note
			:allow-retry="false"
			:waiting-header="t('pages', 'Requested')">
			<template #actions="{ article }">
				<!--
					The candidate's preview: the frozen version that would replace what the site serves.
					It is a button of its own so the reviewer can look before deciding; the iframe opens
					below the table (`previewing`), never in a second window that a popup blocker eats.
				-->
				<NcButton
					v-if="previewable(article)"
					variant="tertiary"
					:disabled="!previewBase"
					@click="startPreview(article)">
					<template #icon><Monitor :size="20" /></template>
					{{ t('pages', 'Preview') }}
				</NcButton>
				<!--
					Both kinds of pending row are answered by the same decision endpoint. The label says
					what the answer does: a candidate is approved for publication, a take-off is approved
					to come off the site; a take-off whose file the author deleted is rejected by
					transferring the page, and the button says that instead of a plain "Reject".
				-->
				<NcButton
					variant="primary"
					:disabled="busy.has(rowKey(article))"
					@click="decide(article, 'approve')">
					<template #icon><Check :size="20" /></template>
					{{ approveLabel(article) }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="busy.has(rowKey(article)) || rejecting.has(rowKey(article))"
					:title="rejectHint(article)"
					@click="startReject(article)">
					<template #icon><Undo :size="20" /></template>
					{{ rejectLabel(article) }}
				</NcButton>
			</template>
		</ArticleRows>

		<!--
			The candidate's preview, inline under the table: the iframe points at the frozen version
			(`?tree=onlinepreview`), so the reviewer compares it with what the site serves without
			leaving the queue. The heading says which version is on screen.
		-->
		<div v-if="previewing" class="pages-requests__preview">
			<div class="pages-requests__preview-head">
				<strong class="pages-requests__preview-label">{{ t('pages', 'Preview of the pending version') }}</strong>
				<span class="pages-requests__preview-title">{{ previewing.title || rowKey(previewing) }}</span>
				<span class="pages-requests__spacer" />
				<NcButton variant="tertiary" @click="stopPreview">
					{{ t('pages', 'Close the preview') }}
				</NcButton>
			</div>
			<iframe
				class="pages-requests__preview-frame"
				:src="previewUrl(previewing)"
				:title="t('pages', 'Preview of the pending version')"
				sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
				referrerpolicy="no-referrer" />
		</div>

		<!--
			The inline reason field. It replaces nothing: the row above stays visible, so the reviewer
			still sees the title and the author while writing the note.
		-->
		<div v-for="article in rows.filter((row) => rejecting.has(rowKey(row)))"
			:key="'reject-' + rowKey(article)"
			class="pages-requests__reject">
			<label class="pages-requests__reject-label" :for="'pages-reject-' + rowKey(article)">
				{{ rejectReasonLabel(article) }}
			</label>
			<!--
				The rejection of a take-off request whose author deleted the file does more than decline
				it: the page is handed to the orphan owner directory. Saying so here, where the reviewer
				is about to commit to the answer, is the one place it cannot be missed.
			-->
			<p v-if="rejectHint(article)" class="pages-requests__muted pages-requests__reject-hint">
				{{ rejectHint(article) }}
			</p>
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
					{{ rejectConfirmLabel(article) }}
				</NcButton>
				<NcButton variant="tertiary" @click="cancelReject(article)">
					{{ t('pages', 'Cancel') }}
				</NcButton>
			</div>
		</div>

		<!--
			The batch confirmation: the two batch actions touch many entries at once, so each one names
			what it will do and which entries it will do it to before a single call is made. The
			breakdown counts both kinds, because the batch covers candidates and take-off requests.
		-->
		<NcDialog
			v-if="batch"
			:name="batchName"
			:buttons="batchButtons"
			@closing="batch = null">
			<p>{{ batchHint }}</p>
			<p v-if="batchBreakdown" class="pages-requests__muted">{{ batchBreakdown }}</p>
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
import Monitor from 'vue-material-design-icons/Monitor.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

import api from './api.js'
import ArticleRows from './ArticleRows.vue'
import { intentOf, mergeRows, reasonFor, rowKey } from './articles.js'

export default {
	name: 'RequestsView',

	components: {
		ArticleRows,
		Check,
		CheckAll,
		Monitor,
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

	data() {
		return {
			/** The rows as the pending list reports them: candidates and take-off requests */
			articles: [],
			/**
			 * How many entries are approved for the site (`counts.external` of the same list answer,
			 * i.e. the visibility manifest, not this filtered view). Null until the first answer.
			 */
			approvedCount: null,
			/** The base URL the built site is served from through the preview token; '' until loaded. */
			previewBase: '',
			/** The row whose candidate is on screen in the inline iframe, or null. */
			previewing: null,
			loading: false,
			/** Keys of the rows with a decision in flight */
			busy: new Set(),
			/** Keys of the rows whose reason field is open */
			rejecting: new Set(),
			/** Key → the reason being typed, kept across re-renders */
			notes: {},
			/** The batch confirmation: `{action, paths, updates, takeOffs, note}` while the dialog is open */
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
		 * The rows as the table shows them: the target path exactly as the list reports it, the intent
		 * of the request, and the folder-derived flags the actions read. The path is not shortened to
		 * `Pages/…` - a reviewer reads where the file really is, whoever wrote it.
		 */
		rows() {
			return this.articles.map((article) => ({
				...article,
				intent: intentOf(article),
				// the API's own fact, normalised once: this row's request was filed by the author
				// deleting the source file, so a rejection hands the page to the orphan owner
				sourceDeleted: article?.sourceDeleted === true,
			}))
		},

		/**
		 * Every path that can be decided in one go. Both kinds of pending row go through the same
		 * decision endpoint, so a batch covers both; the dialog states the split before it runs.
		 */
		paths() {
			return this.rows
				.map((row) => rowKey(row))
				.filter((path) => path !== '')
		},

		/**
		 * How many requests wait, split by what they would do: a candidate (a first publication or an
		 * update) or a take-off. The two flags are the split, not the state: a candidate is `internal`
		 * or `external` now.
		 */
		summary() {
			if (!this.rows.length) {
				return ''
			}
			const online = this.rows.filter((row) => row.candidate === true).length
			const offline = this.rows.filter((row) => row.downRequest === true).length
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

		/**
		 * What a batch rejection means, in one line. A batch can mix both kinds, so it cannot promise
		 * either state: the decision answers each request where it stands.
		 */
		batchHint() {
			return this.batch && this.batch.action === 'approve'
				? t('pages', 'An online request puts the page on the site with the next publish; an offline request takes it off the site with the next publish.')
				: t('pages', 'These requests will be rejected.')
		},

		/** How the batch splits over the two kinds of pending row; empty while none is open. */
		batchBreakdown() {
			if (!this.batch) {
				return ''
			}
			return t('pages', '%1$s update(s) and %2$s take-off request(s)', [
				String(this.batch.updates),
				String(this.batch.takeOffs),
			])
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
		this.loadPreviewBase()
	},

	methods: {
		t,
		rowKey,

		/**
		 * The panel's rows. A candidate and a take-off request are two different answers of the same
		 * list endpoint now, because the state is derived from the folders:
		 *
		 *   `?awaiting=1`        the entries with a frozen candidate in `pending/`
		 *   `?state=requested`   the entries whose author asked for a take-off (`downRequest`)
		 *
		 * Both are asked for in parallel and merged by target; the two cannot overlap on an entry
		 * (nothing ever carries both requests), but merging is what keeps a row from appearing twice
		 * if a server answer ever does. The review counts ride along on both answers, so one of them
		 * is enough for the header.
		 */
		async refresh() {
			this.loading = true
			const [awaiting, takeOffs] = await Promise.all([
				api.awaitingArticles(),
				api.visibilityRequests(),
			])
			this.loading = false
			// No grant (any more): the section is not for this caller, so it goes away quietly
			const denied = [awaiting, takeOffs].find((result) => result.status === 403 || result.status === 401)
			if (denied) {
				this.hideSection(t('pages', 'You do not have permission to decide about requests.'))
				return
			}
			const failure = [awaiting, takeOffs].find((result) => !result.ok)
			if (failure) {
				this.error = reasonFor(failure)
				return
			}
			this.error = ''
			this.articles = mergeRows(
				Array.isArray(awaiting.data?.articles) ? awaiting.data.articles : [],
				Array.isArray(takeOffs.data?.articles) ? takeOffs.data.articles : [],
			)
			const counts = awaiting.data?.counts || takeOffs.data?.counts
			if (counts && typeof counts.external === 'number') {
				this.approvedCount = counts.external
			}
			// A row that is no longer waiting cannot be waiting for a reason any more
			const present = new Set(this.articles.map((row) => rowKey(row)))
			this.rejecting = new Set(Array.from(this.rejecting).filter((key) => present.has(key)))
			// The previewed candidate was decided or withdrawn: the iframe has nothing to show
			if (this.previewing && !this.articles.some((row) => rowKey(row) === rowKey(this.previewing) && row.candidate === true)) {
				this.previewing = null
			}
		},

		/** The preview token of the site, asked for once; the iframes are built from its `base`. */
		async loadPreviewBase() {
			const result = await api.session()
			if (result.ok) {
				this.previewBase = String(result.data?.base || '')
			}
		},

		/** Whether the candidate of this row has a page to show: a url and a known preview base. */
		previewable(article) {
			return article?.candidate === true && String(article?.url || '') !== ''
		},

		/**
		 * The address of the frozen candidate: the preview base plus the row's own site url, marked
		 * with `tree=onlinepreview` so the proxy serves the candidate tree and not the published one.
		 */
		previewUrl(article) {
			const url = String(article?.url || '').replace(/^\/+/, '')
			if (url === '' || this.previewBase === '') {
				return ''
			}
			const base = this.previewBase.endsWith('/') ? this.previewBase : this.previewBase + '/'
			return base + url + '?tree=onlinepreview'
		},

		startPreview(article) {
			if (!this.previewable(article)) {
				return
			}
			this.previewing = article
		},

		stopPreview() {
			this.previewing = null
		},

		/** What the approve button does: a candidate is published, a take-off is approved. */
		approveLabel(article) {
			return article?.downRequest === true
				? t('pages', 'Approve take-off')
				: t('pages', 'Approve')
		},

		/**
		 * What the reject button says. A take-off request whose author deleted the file is not merely
		 * declined: rejecting it transfers the published copy to the orphan owner directory, and the
		 * button names that instead of the harmless "Reject" the other rows carry.
		 */
		rejectLabel(article) {
			return article?.downRequest === true && article?.sourceDeleted === true
				? t('pages', 'Reject and transfer')
				: t('pages', 'Reject')
		},

		/**
		 * What rejecting this row does, when it is not the ordinary rejection: a take-off request whose
		 * source file the author deleted is rejected by handing the page to the orphan owner directory
		 * instead of keeping it online. '' for every other row, so the hint never appears there.
		 */
		rejectHint(article) {
			return article?.downRequest === true && article?.sourceDeleted === true
				? t('pages', 'Rejecting transfers the page to the orphan directory.')
				: ''
		},

		/** The reason field is about the update when the candidate replaces a served version. */
		rejectReasonLabel(article) {
			const name = article.title || rowKey(article)
			return article?.candidate === true && article?.published === true
				? t('pages', 'Reason for rejecting the update in “%s”', [name])
				: t('pages', 'Reason for rejecting “%s”', [name])
		},

		/** The inline confirmation names what the rejection answers. */
		rejectConfirmLabel(article) {
			if (article?.downRequest === true) {
				return article?.sourceDeleted === true
					? t('pages', 'Reject and transfer')
					: t('pages', 'Reject the request')
			}
			return t('pages', 'Reject')
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

		/**
		 * One decision call, with the shared busy flag and the failure handling.
		 *
		 * The success toast says what the decision did, and that follows the kind of pending entry: a
		 * refused take-off leaves the page on the site, a refused publication leaves it internal, and
		 * a refused update keeps the version the site already serves.
		 */
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
			// the row as it was decided: its kind is the only thing that says what the answer means
			const row = this.articles.find((article) => rowKey(article) === path) || null
			// An approval changes what the site has, so the bridge is part of the outcome: a failure
			// there is reported instead of being reported as a success that did not happen.
			const bridge = result.data?.bridge
			if (bridge && (bridge.pushed === false || bridge.notified === false)) {
				this.showNotice('warning', t('pages', 'The decision is recorded, but the bridge could not be updated: %s', [String(bridge.message || bridge.error || '')]))
			} else if (action === 'approve') {
				this.showNotice('success', t('pages', 'Approved: %s', [path]))
			} else if (row?.downRequest === true) {
				// the author asked for the page to come off the site; a refusal keeps it where it is
				this.showNotice('success', t('pages', 'Take-offline declined: %s stays online.', [path]))
			} else if (row?.candidate === true && row?.published === true) {
				// a refused update does not touch the version the site serves
				this.showNotice('success', t('pages', 'Rejected: %s keeps the published version.', [path]))
			} else if (intentOf(row) === 'offline') {
				// defensive: an old-shaped take-off row with no downRequest flag
				this.showNotice('success', t('pages', 'Take-offline declined: %s stays online.', [path]))
			} else if (row?.state === 'external') {
				// no request to answer (a row this panel does not normally carry): the reviewer took
				// the page off the site directly
				this.showNotice('success', t('pages', 'Took “%s” off the site.', [path]))
			} else {
				this.showNotice('success', t('pages', 'Rejected: %s is back to internal.', [path]))
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
			const rows = this.rows.filter((row) => rowKey(row) !== '')
			if (!rows.length) {
				return
			}
			this.batch = {
				action,
				paths: rows.map((row) => rowKey(row)),
				// the two kinds are counted apart, so the dialog can say what the batch is made of
				updates: rows.filter((row) => row.candidate === true).length,
				takeOffs: rows.filter((row) => row.downRequest === true).length,
				note: '',
			}
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
			this.busy = new Set()
			this.rejecting = new Set()
			this.previewing = null
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

.pages-requests__preview {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
	padding: 8px 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-dark);
}

.pages-requests__preview-head {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.pages-requests__preview-label {
	font-size: 13px;
}

.pages-requests__preview-title {
	font-size: 12px;
	opacity: 0.75;
	overflow-wrap: anywhere;
}

.pages-requests__preview-frame {
	width: 100%;
	min-height: 60vh;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: #fff;
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

/* The one-sentence warning above the reason field: it belongs to the decision, not to the label. */
.pages-requests__reject-hint {
	margin: 0;
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
