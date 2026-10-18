<!--
	Read: every page of the site, as a reader sees it. Open to every signed-in user — reading needs no
	capability — so this view is never gated on a grant, and it filters nothing by visibility: each row
	says which of the three states it is in (内部 / 待审核 / 外部), and votes and comments stay available
	on every entry.

	Two steps in one view: the list (with a tag filter and a sort switch, both of which re-request from
	the server rather than sorting a local copy), and the article itself — its own built page in the
	existing preview iframe, the vote widget and the comment thread. The iframe is fed by the preview
	token the app already holds, so a reader who never opened the site preview still gets one: the
	parent fetches it when this view is entered (App.vue's `view` watcher), and that is the one place
	that asks — fetching here as well would ask twice on a direct visit.
-->
<template>
	<div class="pages-read">
		<!-- ---------------------------------------------------------------- one article -->
		<template v-if="selected">
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
		<template v-else>
			<div class="pages-read__head">
				<h2 class="pages-read__heading">{{ t('pages', 'Read') }}</h2>
				<span v-if="total" class="pages-read__summary">{{ t('pages', '%s articles', [String(total)]) }}</span>
				<span class="pages-read__spacer" />
				<NcButton variant="tertiary" :disabled="loading" @click="reload">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('pages', 'Refresh') }}
				</NcButton>
			</div>

			<div class="pages-read__filters">
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

			<NcNoteCard v-if="banner" :type="banner.type" class="pages-read__notice">
				{{ banner.message }}
			</NcNoteCard>

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
						<span class="pages-read__badge" :class="'pages-read__badge--' + stateKind(article.state)">
							{{ stateLabel(article.state) }}
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
import { formatDate, stateKind, stateLabel } from './articles.js'
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
		/** The parent's banner, used for a refused vote the same way every other failure is reported */
		showBanner: {
			type: Function,
			default: () => {},
		},
	},

	data() {
		return {
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
			/** Local notices about a refused action, kept apart from the parent's banner */
			banner: null,
			bannerTimer: null,

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

	beforeUnmount() {
		window.clearTimeout(this.bannerTimer)
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
		stateKind,

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

		/** A local notice for something the parent's banner should not own (a refused vote). */
		showNotice(type, message) {
			this.banner = { type, message }
			window.clearTimeout(this.bannerTimer)
			this.bannerTimer = window.setTimeout(() => {
				this.banner = null
			}, 10000)
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

.pages-read__notice {
	margin: 0;
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
</style>
