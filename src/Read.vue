<!--
	Read: every page of the site, as a reader sees it. Open to every signed-in user — reading needs no
	capability — so this view is never gated on a grant, and it hides nothing: each row says which of
	the three states it is in (内部 / 待审核 / 外部), and votes and comments stay available on every
	entry. The state row only narrows the list when the reader asks for it.

	Three panes in one view, only one of them at a time: the site preview (the whole built site,
	index page first, with the address the frame is showing above it), the list (with a tag filter, a
	state filter and a sort switch, all of which re-request from the server rather than filtering a
	local copy), and one article — its own built page in the same preview iframe, the vote widget and
	the comment thread. The iframes are fed by the preview token the app already holds, so a reader
	who never opened the article preview still gets one: the parent fetches it when this view is
	entered (App.vue's `view` watcher), and that is the one place that asks — fetching here as well
	would ask twice on a direct visit.
-->
<template>
	<div class="pages-read">
		<!-- ---------------------------------------------------------------- the site preview -->
		<div v-if="sitePreview" class="pages-read__site">
			<div class="pages-read__site-head">
				<!--
					The address is an input, not a label: a site has more pages than the links in it
					lead to, and a reader who knows where they are going should not have to hunt for a
					link first. Only site-relative paths are accepted (no full URLs), because the frame
					is served through this app's own preview mount.

					The field shows — and the reader edits — the readable form of the path (`中文.html`),
					never the percent-encoded form the URL needs (`%E4%B8%AD%E6%96%87.html`); `goToPath`
					encodes it on the way out.
				-->
				<label class="pages-read__address-label" for="pages-read-address">{{ t('pages', 'Address') }}</label>
				<input
					id="pages-read-address"
					ref="addressInput"
					v-model="pathInput"
					type="text"
					class="pages-read__address"
					autocomplete="off"
					spellcheck="false"
					:disabled="previewBase === ''"
					@keyup.enter="goToPath">
				<NcButton variant="secondary" :disabled="previewBase === ''" @click="goToPath">
					{{ t('pages', 'Go') }}
				</NcButton>
				<span class="pages-read__spacer" />
				<!--
					Reload the page the frame is showing. The key on the iframe rebuilds it, which makes
					the browser request the current address again through the current token — for a
					page that failed to load, or whose assets were rebuilt, without retyping anything.
				-->
				<NcButton
					variant="tertiary"
					:disabled="frameUrl === ''"
					:aria-label="t('pages', 'Refresh')"
					:title="t('pages', 'Refresh')"
					@click="reloadSitePreview">
					<template #icon><Refresh :size="20" /></template>
					{{ t('pages', 'Refresh') }}
				</NcButton>
				<NcButton variant="tertiary" @click="closeSitePreview">
					{{ t('pages', 'Close the preview') }}
				</NcButton>
			</div>
			<!-- The refusal of an address belongs next to the field, never in a toast. -->
			<NcNoteCard v-if="pathError" type="error" class="pages-read__path-error">
				{{ pathError }}
			</NcNoteCard>
			<iframe
				ref="siteFrame"
				:key="frameReloadKey"
				class="pages-read__frame pages-read__site-frame"
				:src="frameUrl"
				sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
				referrerpolicy="no-referrer"
				@load="onSiteFrameLoad" />
		</div>

		<template v-if="!sitePreview && selected">
			<div class="pages-read__bar">
				<NcButton variant="tertiary" :disabled="loadingArticle" @click="closeArticle">
					<template #icon><ArrowLeft :size="20" /></template>
					{{ t('pages', 'Back to the list') }}
				</NcButton>
				<span class="pages-read__spacer" />
				<NcButton variant="tertiary" :disabled="loadingArticle" @click="reloadArticle">
					<template #icon>
						<NcLoadingIcon v-if="loadingArticle" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('pages', 'Reload') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="articleError" type="error">
				{{ articleError }}
			</NcNoteCard>

			<article class="pages-read__article">
				<h2 class="pages-read__title">{{ selected.title || selected.path }}</h2>
				<p class="pages-read__meta">
					<span v-if="selected.author">{{ selected.author }}</span>
					<span v-if="selected.date">{{ formatDate(selected.date) }}</span>
					<span v-if="hasTags(selected)">{{ (selected.tags || []).join(' · ') }}</span>
					<span class="pages-comments__count">{{ t('pages', '%s comments', [String(selected.comments || 0)]) }}</span>
				</p>
				<p v-if="selected.summary" class="pages-read__summary">{{ selected.summary }}</p>
			</article>

			<div v-if="articleFrameSrc" class="pages-read__frame-wrap">
				<iframe
					ref="articleFrame"
					class="pages-read__frame"
					:src="articleFrameSrc"
					sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
					referrerpolicy="no-referrer" />
			</div>
			<p v-else class="pages-read__muted">
				{{ t('pages', 'This article has no address in the built site yet.') }}
			</p>

			<VoteWidget :article="selected" @updated="onVoted" @failed="(message) => showBanner('error', message)" />

			<CommentThread
				:path="selected.path"
				:can-moderate="canModerate"
				@changed="onCommentsChanged" />
		</template>

		<!-- ---------------------------------------------------------------- the list -->
		<template v-else-if="!sitePreview">
			<div class="pages-read__head">
				<h2 class="pages-read__heading">{{ t('pages', 'Read') }}</h2>
				<span v-if="total && !sitePreview" class="pages-read__summary">{{ t('pages', '%s articles', [String(total)]) }}</span>
				<span class="pages-read__spacer" />
				<NcButton
					variant="tertiary"
					:disabled="previewBase === ''"
					:title="t('pages', 'Open the built site at index.html')"
					@click="openSitePreview">
					{{ t('pages', 'Preview the site') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="loading" @click="reload">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('pages', 'Refresh') }}
				</NcButton>
			</div>

			<div v-if="!sitePreview" class="pages-read__filters">
				<div class="pages-read__tags">
					<NcButton
						:variant="tag === '' ? 'primary' : 'tertiary'"
						@click="selectTag('')">
						{{ t('pages', 'All tags') }}
					</NcButton>
					<NcButton
						v-for="entry in tags"
						:key="entry.tag"
						:variant="tag === entry.tag ? 'primary' : 'tertiary'"
						@click="selectTag(entry.tag)">
						{{ entry.tag }} ({{ entry.count }})
					</NcButton>
				</div>

				<div class="pages-read__sorts" role="group" :aria-label="t('pages', 'Sort')">
					<NcButton
						v-for="mode in SORTS"
						:key="mode"
						:variant="sort === mode ? 'primary' : 'tertiary'"
						@click="selectSort(mode)">
						{{ t('pages', sortLabelKey(mode)) }}
					</NcButton>
				</div>
			</div>

			<!--
				The state filter. Every entry sends its own `state=` value and the server filters, so a
				truncated list cannot be mistaken for a complete one — the client never narrows the rows
				it was given.
			-->
			<div class="pages-read__states" role="group" :aria-label="t('pages', 'State')">
				<NcButton
					v-for="value in STATE_FILTERS"
					:key="value === '' ? 'all' : value"
					:variant="state === value ? 'primary' : 'tertiary'"
					@click="selectState(value)">
					{{ value === '' ? t('pages', 'All states') : stateLabel(value) }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loading && !articles.length" :size="20" />
			<p v-else-if="error" class="pages-read__muted">{{ error }}</p>
			<p v-else-if="articles.length === 0" class="pages-read__muted">{{ emptyMessage }}</p>

			<ul v-else class="pages-read__list">
				<li v-for="article in articles" :key="article.path" class="pages-read__row">
					<button type="button" class="pages-read__open" @click="openArticle(article)">
						<span class="pages-read__row-title">{{ article.title || article.path }}</span>
						<span class="pages-read__row-meta">
							<span v-if="article.author">{{ article.author }}</span>
							<span v-if="article.date">{{ formatDate(article.date) }}</span>
							<span v-if="hasTags(article)" class="pages-read__row-tags">{{ (article.tags || []).join(' · ') }}</span>
						</span>
						<span v-if="summaryOf(article)" class="pages-read__row-summary">{{ summaryOf(article) }}</span>
					</button>
					<span class="pages-read__row-side">
						<!-- The review state, with the same label and colour family the article tables use. -->
						<span class="pages-read__badge" :class="'pages-read__badge--' + stateKind(article.state, article)">
							{{ entryStateLabel(article.state, article) }}
						</span>
						<span class="pages-read__row-counts">
							<span :title="t('pages', 'Upvotes')">↑ {{ article.up || 0 }}</span>
							<span :title="t('pages', 'Downvotes')">↓ {{ article.down || 0 }}</span>
							<!-- The total: the API's own score, so a reader sees the sum, not only both halves. -->
							<span class="pages-read__score" :title="t('pages', 'Total score')">
								{{ scoreLabel(article.up, article.down) }}
							</span>
							<span :title="t('pages', 'Comments')">💬 {{ article.comments || 0 }}</span>
						</span>
					</span>
				</li>
			</ul>

			<p v-if="truncated" class="pages-read__muted">
				{{ t('pages', 'The list was cut short; narrow it with a tag or a state to see the rest.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import api from './api.js'
import CommentThread from './CommentThread.vue'
import VoteWidget from './VoteWidget.vue'
import { entryStateLabel, formatDate, stateKind, stateLabel } from './articles.js'
import {
	STATE_FILTERS,
	SORTS,
	emptyListMessage,
	isStateFilter,
	listFailureMessage,
	normalizeSort,
	scoreLabel,
	sortLabelKey,
	summaryOf,
} from './reading.js'

export default {
	name: 'ReadView',

	components: {
		CommentThread,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ArrowLeft,
		Refresh,
		VoteWidget,
	},

	props: {
		/** The signed-in caller may decide about anybody's comments (publish grant) */
		canModerate: {
			type: Boolean,
			default: false,
		},
		/**
		 * The base URL the built site is served from through the app's preview token. The parent
		 * fetches the token when this view is entered; empty while that is in flight, or when the
		 * site has no built output — the article then says it has no address yet.
		 */
		previewBase: {
			type: String,
			default: '',
		},
		/** The parent's toast, used for a refused vote the same way every other failure is reported */
		showBanner: {
			type: Function,
			default: () => {},
		},
	},

	data() {
		return {
			/** True while the built site itself is on screen (index.html). */
			sitePreview: false,
			/**
			 * The site-relative path the frame last loaded, in the **encoded** form a URL needs:
			 * `onSiteFrameLoad` writes it from the frame's own (encoded) pathname, and `followBase`
			 * spells it into the next `frameUrl` as is. `pathInput` holds the readable form of the
			 * same path; this one is never displayed.
			 */
			sitePreviewPath: 'index.html',
			/**
			 * The actual iframe src: the base plus the path that was asked for. Only opening the
			 * preview and `goToPath` write it — `onSiteFrameLoad` follows the frame but never
			 * re-points it, or every internal link click would reload the frame onto itself.
			 */
			frameUrl: '',
			/**
			 * Bumped by `reloadSitePreview`. It is the iframe's key, so a bump destroys and recreates
			 * the frame: the address is requested again, while path, field and error stay untouched.
			 */
			frameReloadKey: 0,
			/**
			 * What the address field holds, in **readable** form (中文, not %E4%B8%AD…): it follows
			 * the frame unless the reader is typing, and `goToPath` encodes it before building a URL.
			 */
			pathInput: 'index.html',
			/** Why the address in the field was refused; shown under the field, never as a toast. */
			pathError: '',
			SORTS,
			STATE_FILTERS,
			articles: [],
			truncated: false,
			tags: [],
			tag: '',
			/** The review-state filter (`state=` on the request); '' means every state */
			state: '',
			sort: SORTS[0],
			loading: false,
			error: '',

			/** The article being read, or null while the list is shown */
			selected: null,
			loadingArticle: false,
			articleError: '',
		}
	},

	computed: {
		total() {
			return this.articles.length
		},

		/** Which sentence an empty list deserves, given what was filtered for. */
		emptyMessage() {
			return emptyListMessage({ tag: this.tag, state: this.state }, t)
		},

		/**
		 * The article's own page through the preview token. `article.url` is site-relative
		 * (`/test.html`), which is exactly what the preview base expects; the parent keeps asking for
		 * a fresh token when the old one is about to expire, and the URL follows.
		 */
		articleFrameSrc() {
			const relative = String(this.selected?.url || '').replace(/^\/+/, '')
			if (relative === '' || this.previewBase === '') {
				return ''
			}
			return this.previewBase + relative
		},
	},

	watch: {
		/**
		 * The parent rotates the preview token while the frame may be open; the address the frame
		 * holds carries the old token, and the old token answers 403. The rule lives in
		 * `followBase`, so the proof can exercise it without the watcher's plumbing.
		 */
		previewBase(nextBase, previousBase) {
			this.followBase(nextBase, previousBase)
		},
	},

	mounted() {
		this.reload()
		this.loadTags()
	},

	methods: {
		t,
		formatDate,
		summaryOf,
		sortLabelKey,
		scoreLabel,
		stateLabel,
		entryStateLabel,
		stateKind,

		/** Show the built site itself, from its index page. */
		openSitePreview() {
			if (this.previewBase === '') {
				this.showBanner('error', t('pages', 'The preview is not available yet.'))
				return
			}
			this.selected = null
			this.sitePreviewPath = 'index.html'
			this.pathInput = 'index.html'
			this.pathError = ''
			this.frameUrl = this.previewBase + 'index.html'
			this.sitePreview = true
		},

		closeSitePreview() {
			this.sitePreview = false
			// the next open starts at the site's front page again
			this.sitePreviewPath = 'index.html'
			this.pathInput = 'index.html'
			this.pathError = ''
		},

		/**
		 * Load the current address again. The iframe's key changes, so the browser builds a new
		 * frame for the same `frameUrl` and requests it — through whatever token `frameUrl` holds
		 * now. Nothing else is touched: the address the reader typed, the tracked path and the
		 * inline error all stay as they are.
		 */
		reloadSitePreview() {
			if (this.frameUrl === '') {
				// no address, nothing to request (and no key bump that would imply a load happened)
				return
			}
			this.frameReloadKey += 1
		},

		/** 百分号编码的站点路径 → 人类可读形式（解码失败时原样返回，绝不抛错）。 */
		readablePath(path) {
			try {
				return decodeURIComponent(String(path || ''))
			} catch (error) {
				// a lone `%` (or any other invalid escape) is part of the file name: keep it as typed
				return String(path || '')
			}
		},

		/**
		 * 可读路径 → 可以拼进 iframe src 的路径：逐段 encodeURIComponent，保留 `/` 分隔。
		 * 查询串与锚点原样保留（按第一个 `?` 或 `#` 切开，只编码前面的路径部分）。
		 */
		urlPath(readable) {
			const text = String(readable ?? '')
			const cut = text.search(/[?#]/)
			const path = cut === -1 ? text : text.slice(0, cut)
			const suffix = cut === -1 ? '' : text.slice(cut)
			// one segment at a time: `encodeURIComponent` would escape the `/` separators too
			return path.split('/').map((segment) => encodeURIComponent(segment)).join('/') + suffix
		},

		/**
		 * The address in the field, as a site-relative path, or the reason it is not one. The path
		 * returned is the **readable** form: a pasted `%E4%B8%AD…` address is decoded first, so the
		 * field always ends up holding something a reader can edit, and `goToPath` encodes it again.
		 *
		 * Only paths inside the built site can be previewed: the frame is served through this app's
		 * own preview mount, so a full URL would either be refused by the browser or take the frame
		 * somewhere the token does not reach. A `..` segment is refused for the same reason — the
		 * mount is not a place to climb out of.
		 */
		normalizeAddress(raw) {
			const value = this.readablePath(String(raw ?? '').trim())
			if (value === '') {
				return { path: '', error: t('pages', 'Enter a page address.') }
			}
			if (value.includes('://') || value.split('/').includes('..')) {
				return { path: '', error: t('pages', 'Only paths inside the site can be previewed, not a full URL.') }
			}
			let path = value.replace(/^\/+/, '')
			if (path === '' || path.endsWith('/')) {
				// a directory address is the friendliest form: `docs/` means `docs/index.html`
				path += 'index.html'
			}
			return { path, error: '' }
		},

		/**
		 * Load the address in the field. This and `openSitePreview` are the only two places the
		 * frame is pointed anywhere; the frame's own navigations are followed, not driven.
		 */
		goToPath() {
			const { path, error } = this.normalizeAddress(this.pathInput)
			if (error !== '') {
				this.pathError = error
				return false
			}
			const encoded = this.urlPath(path)
			this.pathError = ''
			this.sitePreviewPath = encoded
			this.pathInput = path
			if (this.previewBase === '') {
				// the field is disabled without a token; a programmatic call must not build a
				// URL that can only end in a 404
				return false
			}
			this.frameUrl = this.previewBase + encoded
			return true
		},

		/**
		 * Re-point the frame at a rotated preview token. The parent replaces the token while the
		 * frame may be open, and an address carrying the old one answers 403.
		 *
		 * The path kept is the page the reader is **on** — `sitePreviewPath`, which
		 * `onSiteFrameLoad` keeps in step with the frame's own navigations — not the address the
		 * frame was opened with: a token rotation must not send somebody back to the front page
		 * mid-read. The old `frameUrl` is only the fallback for the moment the tracked path is not
		 * known yet, and an empty path still means the site's front page.
		 */
		followBase(nextBase, previousBase) {
			if (!this.sitePreview || this.frameUrl === '') {
				return
			}
			const base = String(nextBase || '')
			if (base === '') {
				// no token, no address: a frame on the stale one would only show an error page
				this.frameUrl = ''
				return
			}
			let relative = String(this.sitePreviewPath || '').replace(/^\/+/, '')
			if (relative === '') {
				const previous = String(previousBase || '')
				relative = (previous !== '' && this.frameUrl.startsWith(previous)
					? this.frameUrl.slice(previous.length)
					: this.frameUrl.replace(/^.*?\/preview\/[^/]+\//, ''))
					.replace(/^\/+/, '')
			}
			if (relative === '') {
				relative = 'index.html'
			}
			this.frameUrl = base + relative
			/*
			 * The field shows the page the frame is being reloaded onto, unless the reader is typing
			 * in it: a rotation must not pull a half-typed address out from under the cursor. The
			 * path kept is encoded (it is a URL segment); the field is readable text.
			 */
			if (typeof document === 'undefined' || document.activeElement !== this.$refs?.addressInput) {
				this.pathInput = this.readablePath(relative)
			}
		},

		/**
		 * Follow the preview where the reader goes. The frame is served through the app's own
		 * `/preview/<token>/` mount, so its location is same-origin and readable; the token segment is
		 * the app's plumbing and is stripped, leaving the site-relative path the header should show.
		 *
		 * A sandbox or origin refusal is not an error to report: the last known path stays and the
		 * frame keeps working. Only the address line is potentially stale, which is the honest failure.
		 */
		onSiteFrameLoad() {
			try {
				const pathname = String(this.$refs.siteFrame?.contentWindow?.location?.pathname || '')
				if (pathname === '') {
					return
				}
				// the mount is /preview/<token>/<path>: everything before the path is the app's own URL.
				// Non-greedy: a site may itself have a folder called `preview`, and the first mount
				// segment is the token, not the last `/preview/` in the address.
				const path = pathname.replace(/^.*?\/preview\/[^/]+\//, '')
				// the pathname is percent-encoded (that is what the frame loaded): the tracked path
				// stays in that form, the field gets the readable form of the same page
				this.sitePreviewPath = path === '' ? 'index.html' : path
				/*
				 * The field follows the frame, so a link click shows where it went — but never while
				 * the reader is typing in it: pulling a half-typed address out from under the cursor
				 * is the one thing following along must not do. `document` does not exist in the
				 * server-side proof, which is also not somebody typing.
				 */
				if (typeof document === 'undefined' || document.activeElement !== this.$refs?.addressInput) {
					this.pathInput = this.readablePath(this.sitePreviewPath)
				}
			} catch (error) {
				// expected when the frame is not inspectable (cross-origin or sandbox refusal)
				console.debug('[pages] preview path not readable:', error)
			}
		},

		/** The list and its counts, always from the server: the filters and the sort are its business. */
		async reload() {
			this.loading = true
			const result = await api.readingList(this.tag, normalizeSort(this.sort), this.state)
			this.loading = false
			if (!result.ok) {
				this.articles = []
				this.truncated = false
				// a 400 is the filter's fault and says so; anything else is reported as it came
				this.error = listFailureMessage(result, t)
				return
			}
			this.error = ''
			this.articles = Array.isArray(result.data?.articles) ? result.data.articles : []
			this.truncated = result.data?.truncated === true
		},

		/** Tag counts. A failure only costs the filter, so it is not an error page. */
		async loadTags() {
			const result = await api.tagCounts()
			if (!result.ok) {
				this.tags = []
				return
			}
			this.tags = Array.isArray(result.data?.tags) ? result.data.tags : []
		},

		selectTag(value) {
			if (this.tag === value) {
				return
			}
			this.tag = value
			this.reload()
		},

		selectSort(mode) {
			if (this.sort === mode) {
				return
			}
			this.sort = mode
			this.reload()
		},

		/**
		 * The state filter re-requests; it never narrows the rows in hand. The list can be truncated,
		 * and filtering a truncated answer in the browser would claim "nothing matches" about rows
		 * that were never sent.
		 */
		selectState(value) {
			const next = String(value ?? '')
			if (this.state === next) {
				return
			}
			if (!isStateFilter(next)) {
				// the server answers an unknown value with 400; it is never sent in the first place
				this.error = t('pages', 'That state filter is not one of the known states.')
				return
			}
			this.state = next
			this.reload()
		},

		openArticle(article) {
			this.selected = article
			this.articleError = ''
			// the single-article endpoint is the authority for the numbers around it; the list row is
			// only what the list knew, and it may be a second old
			this.reloadArticle()
		},

		closeArticle() {
			this.selected = null
			this.articleError = ''
			// the list carries the counts, and voting or commenting may have moved them
			this.reload()
		},

		async reloadArticle() {
			const path = String(this.selected?.path || '')
			if (path === '') {
				return
			}
			this.loadingArticle = true
			const result = await api.readArticle(path)
			this.loadingArticle = false
			if (!result.ok) {
				// keep what the list knew on screen; say what went wrong instead of blanking the page
				this.articleError = result.status === 404
					? t('pages', 'This article is not available any more.')
					: (result.message || t('pages', 'The article could not be loaded.'))
				return
			}
			this.articleError = ''
			if (result.data?.article) {
				this.selected = result.data.article
			}
		},

		/**
		 * The vote widget reports the server's numbers. Both homes have to learn them: the list row,
		 * and the article the widget itself reads. Updating only the list left the widget believing the
		 * caller had not voted, so a second click voted again instead of withdrawing — and a vote that
		 * cannot be taken back is exactly what a vote must not be.
		 */
		onVoted({ path, up, down, mine }) {
			const voted = { up, down, mine, score: up - down }
			if (this.selected && this.selected.path === path) {
				this.selected = { ...this.selected, ...voted }
			}
			const index = this.articles.findIndex((article) => article.path === path)
			if (index === -1) {
				return
			}
			const next = this.articles.slice()
			next[index] = { ...next[index], ...voted }
			this.articles = next
		},

		/** A comment was posted or deleted: the thread recounted itself, the list has to follow. */
		onCommentsChanged() {
			this.reloadArticle()
			this.reload()
		},

		hasTags(article) {
			return Array.isArray(article?.tags) && article.tags.length > 0
		},
	},
}
</script>

<style scoped>
.pages-read {
	display: flex;
	flex-direction: column;
	gap: 12px;
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
	padding: 12px 16px 24px;
}

.pages-read__head,
.pages-read__bar {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.pages-read__heading {
	margin: 0;
	font-size: 18px;
}

.pages-read__spacer {
	flex: 1 1 auto;
}

.pages-read__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	justify-content: space-between;
}

.pages-read__tags,
.pages-read__sorts {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

/* The state filter is a row of its own: the three states plus "all" do not share a line gracefully. */
.pages-read__states {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.pages-read__muted,
.pages-read__summary {
	font-size: 12px;
	opacity: 0.7;
	margin: 0;
}

.pages-read__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.pages-read__row {
	display: flex;
	align-items: center;
	gap: 12px;
	border-bottom: 1px solid var(--color-border);
}

.pages-read__row:hover {
	background-color: var(--color-background-hover);
}

/* The whole row is the target, but it stays a real button: keyboard and screen readers get it too. */
.pages-read__open {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 auto;
	min-width: 0;
	padding: 8px 4px;
	border: 0;
	background: none;
	text-align: start;
	cursor: pointer;
	color: inherit;
	font: inherit;
}

.pages-read__row-title {
	font-weight: 600;
	overflow-wrap: anywhere;
}

.pages-read__row-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 12px;
	opacity: 0.7;
}

.pages-read__row-summary {
	font-size: 12px;
	opacity: 0.6;
	overflow-wrap: anywhere;
}

.pages-read__row-counts {
	display: flex;
	gap: 10px;
	font-size: 12px;
	white-space: nowrap;
	opacity: 0.85;
	padding-inline-end: 4px;
}

/* The state badge and the counters share the right-hand cell and stack when the row gets narrow. */
.pages-read__row-side {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex: 0 0 auto;
}

.pages-read__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 11px;
	white-space: nowrap;
	border: 1px solid transparent;
	background-color: var(--color-background-dark);
}

.pages-read__badge--info {
	background-color: var(--color-primary-element-light);
}

.pages-read__badge--success {
	background-color: var(--color-success);
	color: var(--color-success-text, #fff);
}

/* The total stands out a little: it is the number a reader is comparing between rows. */
.pages-read__score {
	font-weight: 600;
}

.pages-read__article {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pages-read__title {
	margin: 0;
	font-size: 20px;
}

.pages-read__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin: 0;
	font-size: 12px;
	opacity: 0.75;
}

.pages-read__summary {
	font-size: 13px;
}

/* The built page, in the same iframe the site preview uses (same sandbox, same token). */
.pages-read__frame-wrap {
	flex: 0 0 auto;
	height: 60vh;
	min-height: 240px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.pages-read__frame {
	width: 100%;
	height: 100%;
	border: 0;
	background: #fff;
}

/* the site preview fills the pane the same way an article does */
.pages-read__site {
	display: flex;
	flex-direction: column;
	gap: 8px;
	flex: 1 1 auto;
	min-height: 0;
}

.pages-read__site-head {
	display: flex;
	align-items: center;
	gap: 8px;
	/* three parts: the address bar (it shrinks), the reload button, the close button */
	flex-wrap: wrap;
}

/* "Address" is a label for the field, not a heading: small and quiet, like the rest of the head. */
.pages-read__address-label {
	font-size: 12px;
	opacity: 0.7;
	white-space: nowrap;
}

/*
 * The one editable control of this view. It grows with the pane (the two buttons keep their
 * widths) and is set in the monospace face: it is a path, not prose. Its text is the readable
 * form of the path — Chinese file names stay Chinese, they are only encoded on the way to `src`.
 */
.pages-read__address {
	flex: 1 1 14em;
	min-width: 8em;
	padding: 4px 8px;
	font-family: var(--font-family-monospace, monospace);
	font-size: 12px;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.pages-read__address:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

/*
 * A refused address is reported here, next to what was typed — never as a toast.
 * The card is NcNoteCard, as every other notice in this view: colours, icon, wrapping and the
 * `role="alert"` it sets for an error are the component's. Only its `margin: 1rem 0` is undone —
 * `.pages-read__site` already spaces its children by 8px.
 */
.pages-read__path-error {
	margin: 0;
}

.pages-read__site-frame {
	width: 100%;
	min-height: 0;
	flex: 1 1 auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}
</style>
