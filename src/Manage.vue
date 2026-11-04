<!--
	The manage view: every article the site is serving, whoever wrote it.

	Taking a page off the site is a decision about the site, not a property of the caller's own
	files, so it lives here instead of in the editor's action menu: a reviewer sees the whole list -
	all authors, every live entry - and takes one down from its own row. The author keeps the file;
	the next publish drops it from the site.

	Every row shows the complete stored target, exactly as the list reports `article.path` - not the
	author's own `Pages/…` reading of it. Rows of several authors share one table, so the prefix is
	itself the information (`authors-src/alice/…` next to `tmp/bob/…`), and the author column already
	names whose file each row is.

	The list is the reading list everybody gets, narrowed to the entries the site serves
	(`GET /api/articles?external=true`). Taking one down is the reviewer's own action
	(`POST /api/article/take-down`): an entry with nothing pending would answer a *decision* with
	409, so the endpoint of its own is what this view calls. It records who decided and sends the
	author the take-down notification.

	`window.confirm`, not a dialog: it is the same confirmation the delete action in App.vue uses,
	and it names the article before anything is sent.

	Only the review grant may mount this view (App.vue gates it), and every call can still answer 403
	when the grant is taken away while the page is open. That is handled like the request panel does
	it: the list and its buttons go away through `forbidden`, never a half-updated table.
-->
<template>
	<div class="pages-manage">
		<div class="pages-manage__head">
			<h2 class="pages-manage__title">{{ t('pages', 'Manage') }}</h2>
			<!--
				How many entries the site has approved, over the whole site - not over the rows below,
				which may be truncated. Null until the first answer.
			-->
			<span v-if="approvedCount !== null" class="pages-manage__summary">
				{{ t('pages', 'Approved for the site: %s', [String(approvedCount)]) }}
			</span>
			<span class="pages-manage__spacer" />

			<NcButton :disabled="loading" @click="refresh">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('pages', 'Refresh') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error" class="pages-manage__notice">
			{{ error }}
		</NcNoteCard>

		<p v-if="!loading && !error && articles.length === 0" class="pages-manage__empty">
			{{ t('pages', 'No article is online yet.') }}
		</p>

		<!--
			No push-retry mark here: `pushFailed` on these rows is the server's "the last publish's
			push failed" flag on a live entry, not the author's own configuration push the shared
			table's retry repeats - so a mark would be a button that cannot do what it says.
		-->
		<ArticleRows v-if="articles.length" :rows="articles" show-author :allow-retry="false">
			<template #actions="{ article }">
				<!--
					The one action of this view. It asks first (see `askTakeDown`) and is disabled while
					its own decision is in flight, so one row cannot be decided twice.
				-->
				<NcButton
					variant="secondary"
					:disabled="busy.has(rowKey(article))"
					@click="askTakeDown(article)">
					<template #icon>
						<NcLoadingIcon v-if="busy.has(rowKey(article))" :size="20" />
						<Close v-else :size="20" />
					</template>
					{{ t('pages', 'Take off the site') }}
				</NcButton>
			</template>
		</ArticleRows>

		<p v-if="truncated" class="pages-manage__muted">
			{{ t('pages', 'The list was cut short; not every article on the site is shown.') }}
		</p>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Close from 'vue-material-design-icons/Close.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import api from './api.js'
import ArticleRows from './ArticleRows.vue'
import { reasonFor, rowKey } from './articles.js'

export default {
	name: 'ManageView',

	components: {
		ArticleRows,
		Close,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
	},

	// The grant really was taken away: the page decides what that means for the view switch.
	emits: ['forbidden', 'decided'],

	props: {
		/** The parent's toast, used the same way every other decision reports its outcome. */
		showBanner: {
			type: Function,
			default: () => {},
		},
	},

	data() {
		return {
			/**
			 * The rows as the list reports them: every `external` entry, whatever its author, each
			 * with its complete stored target in `path`.
			 */
			articles: [],
			/**
			 * Whether the answer was cut short by the list limit. The notice under the table is the
			 * only place that says so; the table itself must never look like the whole site.
			 */
			truncated: false,
			/**
			 * How many entries are approved for the site (`counts.external` of the same answer). Null
			 * until the first answer; the list itself may be truncated, the count is not.
			 */
			approvedCount: null,
			loading: false,
			/** Keys of the rows with a decision in flight */
			busy: new Set(),
			error: '',
		}
	},

	mounted() {
		this.refresh()
	},

	methods: {
		t,
		rowKey,

		/**
		 * The list: `GET /api/articles?external=true`, i.e. every entry the site is serving - of every
		 * author, because the published scope filters by state and not by owner.
		 */
		async refresh() {
			this.loading = true
			const result = await api.readingList('', 'new', '', true)
			this.loading = false
			// No grant (any more): the view is not for this caller, so it goes away quietly
			if (result.status === 403 || result.status === 401) {
				this.hideSection(reasonFor(result))
				return
			}
			if (!result.ok) {
				// The rows already on screen stay: a refresh that fails says so, it does not throw
				// away a table the reviewer may still be reading (the request panel does the same).
				this.error = reasonFor(result)
				return
			}
			this.error = ''
			this.articles = Array.isArray(result.data?.articles) ? result.data.articles : []
			this.truncated = result.data?.truncated === true
			const counts = result.data?.counts
			this.approvedCount = counts && typeof counts.external === 'number' ? counts.external : this.articles.length
		},

		/**
		 * Take one article off the site, after asking.
		 *
		 * The confirmation names the article and says when the site stops serving it: the file stays,
		 * and only the next publish drops the page from the built site. A cancelled confirmation sends
		 * nothing. The call is `api.takeDown` and not a decision: an entry with nothing pending would
		 * be a 409 there, and the take-down is the reviewer's own action.
		 */
		async askTakeDown(article) {
			const path = rowKey(article)
			if (!path || this.busy.has(path)) {
				return
			}
			const name = String(article?.title || path)
			const question = t('pages', 'Take “%s” off the site? It disappears from the site with the next publish.', [name])
			if (!window.confirm(question)) {
				return
			}
			this.markBusy(path, true)
			const result = await api.takeDown(path)
			this.markBusy(path, false)
			if (!result.ok) {
				if (result.status === 403 || result.status === 401) {
					this.hideSection(reasonFor(result))
					return
				}
				this.showBanner('error', reasonFor(result))
				return
			}
			this.showBanner('success', t('pages', 'Took “%s” off the site.', [name]))
			// The entry is not `external` any more, so the list - and every badge the page derived
			// from it - moved: reload instead of patching the row in place.
			await this.refresh()
			this.$emit('decided')
		},

		markBusy(path, value) {
			const next = new Set(this.busy)
			if (value) {
				next.add(path)
			} else {
				next.delete(path)
			}
			this.busy = next
		},

		/** Losing the grant hides the whole view; the page keeps working without it. */
		hideSection(reason) {
			this.articles = []
			this.approvedCount = null
			this.truncated = false
			this.$emit('forbidden', reason)
		},
	},
}
</script>

<style scoped>
.pages-manage {
	display: flex;
	flex-direction: column;
	/* Takes the height left under the toolbar and scrolls itself, like the other views do. */
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
	padding: 12px 16px 24px;
}

.pages-manage__head {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.pages-manage__title {
	margin: 0;
	font-size: 18px;
}

.pages-manage__summary,
.pages-manage__muted {
	font-size: 12px;
	opacity: 0.7;
}

.pages-manage__spacer {
	flex: 1 1 auto;
}

.pages-manage__notice {
	margin-bottom: 8px;
}

.pages-manage__empty {
	opacity: 0.7;
}
</style>
