<!--
	The article table, shared by "My articles" and the request panel: they show the same article shape
	and only differ in which columns and which row actions they need.

	"My articles" renders title, state, date and actions; the request panel adds author, waiting and
	review note (title, author, state, date, waiting, review note, actions). The state column is the
	one a caller can fold away with `grouped` (a table drawn per state needs no badge of its own).

	One CSS grid for the whole table (header and rows share the same template, computed from the
	visible columns), so the columns line up without a <table> and without a fixed width a long title
	could push out of shape. Every value is rendered as text by Vue — nothing here builds markup from
	article data.

	Whether a cell exists is decided by a column-level flag alone (the `show-*` props, and `grouped`
	for the state column), never by a rule about the row: the header and each row are separate grids
	sharing one template, so a row that renders one cell fewer does not leave a hole — it shifts every
	later cell one column left. A row-level condition belongs *inside* the cell, where it can only
	change the content.
-->
<template>
	<div class="pages-articles">
		<div v-if="rows.length" class="pages-articles__head" :style="gridStyle">
			<span>{{ t('pages', 'Title') }}</span>
			<span v-if="showAuthor">{{ t('pages', 'Author') }}</span>
			<span v-if="!grouped">{{ t('pages', 'State') }}</span>
			<span>{{ t('pages', 'Date') }}</span>
			<span v-if="showWaiting">{{ waitingHeader }}</span>
			<span v-if="showReviewNote">{{ t('pages', 'Review note') }}</span>
			<span class="pages-articles__actions-head">{{ t('pages', 'Actions') }}</span>
		</div>

		<div v-for="row in rows" :key="rowKey(row)" class="pages-articles__row" :style="gridStyle">
			<div class="pages-articles__cell pages-articles__cell--title">
				<span class="pages-articles__title">{{ row.title || row.path }}</span>
				<span class="pages-articles__path">{{ row.pathLabel || row.path }}</span>
				<span v-if="hasTags(row)" class="pages-articles__tags">
					<span class="pages-articles__tag">{{ (row.tags || []).join(' · ') }}</span>
				</span>
				<!--
					A request nobody decided yet. It belongs next to the article, not in a column: the
					article is still exactly what the other columns say, plus a decision waiting.
				-->
				<span v-if="requestMarker(row)" class="pages-articles__request">{{ requestMarker(row) }}</span>
				<!--
					The source file of this entry was deleted by its author. The row still exists because
					the deletion filed a take-off request that waits for a reviewer: the sentence is the
					one fact about the author that the decision needs.
				-->
				<span v-if="row.sourceDeleted === true" class="pages-articles__source-deleted">
					{{ t('pages', 'The author deleted the file.') }}
				</span>
				<!--
					The last save of this article did not reach the bridge. Rendered by the table, acted on by
					the page that owns the API: the click emits `retryPush` and the parent repeats the push
					(no request is made here - this component only draws rows). The review tables turn the
					mark off (`allowRetry`): there `pushFailed` is the server's publish-push flag, not the
					author's own configuration push this button retries.
				-->
				<button
					v-if="row.pushFailed && allowRetry"
					type="button"
					class="pages-articles__push"
					@click.stop.prevent="$emit('retryPush', row)">
					{{ t('pages', 'not pushed · retry') }}
				</button>
			</div>

			<div v-if="showAuthor" class="pages-articles__cell">
				<span class="pages-articles__author">{{ row.author || row.owner || t('pages', '(site)') }}</span>
			</div>

			<div v-if="!grouped" class="pages-articles__cell">
				<span class="pages-articles__badge" :class="'pages-articles__badge--' + stateKind(row.state, row)">
					{{ entryStateLabel(row.state, row) }}
				</span>
			</div>

			<div class="pages-articles__cell pages-articles__cell--tight">{{ formatDate(dateOf(row)) }}</div>

			<div v-if="showWaiting" class="pages-articles__cell pages-articles__cell--tight">
				{{ waitingText(row) }}
			</div>

			<div v-if="showReviewNote" class="pages-articles__cell">
				<span v-if="noteOf(row)" class="pages-articles__note">{{ noteOf(row) }}</span>
				<span v-else class="pages-articles__muted">—</span>
			</div>

			<div class="pages-articles__cell pages-articles__cell--actions">
				<slot name="actions" :article="row" />
			</div>
		</div>
	</div>
</template>

<script>
import { t } from './i18n.js'
import { entryStateLabel, formatDate, intentOf, rowKey, stateKind, stateLabel, waitingDays } from './articles.js'

/**
 * Column widths in order; the template is assembled from the same list the markup follows.
 *
 * Every track is fractional, and none is `auto`: an `auto` track is sized from the content of its own
 * row, so one row with two action buttons pushed all of its columns left of the rows with one — the
 * badges looked misaligned because the columns really were in different places. Sharing the width by
 * ratio makes every row's columns land on the same x, whatever a row happens to contain.
 *
 * The action column is the widest of the secondary ones on purpose: its buttons carry their labels
 * (NcButton ellipsizes a squeezed label into "Take of…"), and the widest rows - "Edit" next to
 * "Request the update online" and "Request offline", or the manage view's long "Take off the site" -
 * need room for three of them side by side at a normal content width. A row that still needs more
 * room wraps in its own cell instead of truncating a label or spilling into the neighbouring column.
 */
const COLUMNS = {
	title: 'minmax(0, 3fr)',
	author: 'minmax(0, 1fr)',
	state: 'minmax(0, 1.2fr)',
	date: 'minmax(0, 1fr)',
	waiting: 'minmax(0, 0.8fr)',
	actions: 'minmax(0, 3.2fr)',
}

export default {
	name: 'ArticleRows',

	props: {
		/** Articles as the API returns them */
		rows: {
			type: Array,
			default: () => [],
		},
		/** Show the author column (the review queue does, one's own list does not) */
		showAuthor: {
			type: Boolean,
			default: false,
		},
		/** Show how long an article has been waiting for a decision */
		showWaiting: {
			type: Boolean,
			default: false,
		},
		/**
		 * What the waiting column counts from. The request panel waits for the *request* to be
		 * decided, so it names its own moment there.
		 */
		waitingHeader: {
			type: String,
			default: () => t('pages', 'Requested'),
		},
		/** Show the rejection note column */
		showReviewNote: {
			type: Boolean,
			default: false,
		},
		/**
		 * The caller renders one table per state with its own heading in between, so the state column
		 * is redundant there — but the grid template still has to match, hence the flag.
		 */
		grouped: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether a row carrying `pushFailed` offers the "not pushed · retry" mark. "My articles"
		 * does: the flag there is the caller's own configuration push that failed. The review tables
		 * switch it off, because there `pushFailed` is the server's publish-push flag on any live
		 * row - a retry of the author's own push could not clear it.
		 */
		allowRetry: {
			type: Boolean,
			default: true,
		},
	},

	/**
	 * The push retry of a row. A row with `pushFailed` (set by the page that saved it) draws the
	 * "not pushed · retry" mark; clicking it asks the parent to repeat the push, because only the parent
	 * holds the API and the author whose targets it covers. Emitted only while `allowRetry` is on.
	 */
	emits: ['retryPush'],

	computed: {
		gridStyle() {
			const columns = [COLUMNS.title]
			if (this.showAuthor) {
				columns.push(COLUMNS.author)
			}
			if (!this.grouped) {
				columns.push(COLUMNS.state)
			}
			columns.push(COLUMNS.date)
			if (this.showWaiting) {
				columns.push(COLUMNS.waiting)
			}
			if (this.showReviewNote) {
				columns.push(COLUMNS.state)
			}
			columns.push(COLUMNS.actions)
			return { gridTemplateColumns: columns.join(' ') }
		},
	},

	methods: {
		t,
		stateLabel,
		entryStateLabel,
		stateKind,
		formatDate,
		rowKey,

		hasTags(row) {
			return Array.isArray(row?.tags) && row.tags.length > 0
		},

		/** The review note, whatever the server called it */
		noteOf(row) {
			return String(row?.reviewNote ?? row?.review_note ?? '').trim()
		},

		/**
		 * The pending snapshot on a row, as one line: whether the entry waits for a first publication
		 * (`Online requested`), for an update of the version the site serves (`Pending update`, only
		 * when it is published), or for a take-off (`Take-off request`). The direction is the server's
		 * derived `intent`; the two snapshot flags say which kind of request it is.
		 */
		requestMarker(row) {
			if (row?.downRequest === true) {
				return this.withDay(t('pages', 'Take-off request'), row)
			}
			if (row?.candidate === true && row?.published === true) {
				return this.withDay(t('pages', 'Pending update'), row)
			}
			const intent = intentOf(row)
			if (intent === 'online') {
				return t('pages', 'Online requested on %s', [this.requestDay(row)])
			}
			if (intent === 'offline') {
				return t('pages', 'Offline requested on %s', [this.requestDay(row)])
			}
			return ''
		},

		/** A label with the day the request was filed, when there is one. */
		withDay(label, row) {
			const day = this.requestDay(row)
			return day === '' ? label : `${label} · ${day}`
		},

		/** The day the request was made, in the reader's own locale; '' when there is no moment. */
		requestDay(row) {
			// formatDate already scales seconds to milliseconds; scaling here too produced year 58766
			return formatDate(Number(row?.submittedAt) > 0 ? Number(row.submittedAt) : '')
		},

		/**
		 * The moment to show in the date column. The reading endpoints report the article's own `date`;
		 * the request panel reports `submittedAt`/`publishedAt` instead (it does not read the files),
		 * so the first value that is actually there is used.
		 */
		dateOf(row) {
			return row?.date || row?.publishedAt || row?.published_at || row?.submittedAt || row?.submitted_at || ''
		},

		/** How long ago the request was made; empty while nothing is waiting. */
		waitingText(row) {
			const moment = Number(row?.submittedAt ?? row?.submitted_at)
			if (!Number.isFinite(moment) || moment <= 0) {
				return ''
			}
			// the API reports the wait in days already; it is derived from the same moment if not
			const reported = Number(row?.waitingDays)
			const days = Number.isFinite(reported) ? reported : waitingDays(moment)
			if (days === null) {
				return ''
			}
			return days === 0 ? t('pages', 'today') : t('pages', '%s days', [String(days)])
		},
	},
}
</script>

<style scoped>
.pages-articles {
	display: flex;
	flex-direction: column;
}

.pages-articles__head,
.pages-articles__row {
	display: grid;
	gap: 8px;
	align-items: center;
}

.pages-articles__head {
	padding: 6px 10px;
	font-size: 12px;
	font-weight: bold;
	opacity: 0.65;
	border-bottom: 1px solid var(--color-border);
}

.pages-articles__row {
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.pages-articles__row:last-child {
	border-bottom: 0;
}

.pages-articles__row:hover {
	background-color: var(--color-background-hover);
}

.pages-articles__cell {
	min-width: 0;
	overflow-wrap: anywhere;
}

.pages-articles__cell--title {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.pages-articles__title {
	font-weight: 600;
}

.pages-articles__path,
.pages-articles__muted {
	font-family: var(--font-face-monospace, monospace);
	font-size: 11px;
	opacity: 0.55;
	overflow-wrap: anywhere;
}

.pages-articles__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.pages-articles__tag,
.pages-articles__author {
	font-size: 12px;
	opacity: 0.75;
}

.pages-articles__badge {
	display: inline-block;
	/* One line, always: a label that wrapped doubled its row's height and broke the table's rhythm.
	   The column is sized by ratio (`minmax(0, 1.2fr)`), so the badge gets the same width on every
	   row and an extreme one is ellipsized instead of wrapping or spilling. The `minmax(0, …)` part
	   is load-bearing: the header and each row are separate grids, and a `min-content` track would
	   size itself from its own content and move the columns of other rows. */
	max-width: 100%;
	box-sizing: border-box;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 12px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	border: 1px solid transparent;
}

/* An unknown state is drawn neutral, never as an error: the front end must not invent a verdict. */
.pages-articles__badge--neutral {
	background-color: var(--color-background-dark);
}

.pages-articles__badge--info {
	background-color: var(--color-primary-element-light);
}

.pages-articles__badge--success {
	background-color: var(--color-success);
	color: var(--color-success-text, #fff);
}

/* A pending request sits under the article it is about, small and quiet: it is a note, not a state. */
.pages-articles__request {
	font-size: 11px;
	font-style: italic;
	opacity: 0.75;
	overflow-wrap: anywhere;
}

/* The author deleted the source file: a fact about the request, not a state - same quiet shape. */
.pages-articles__source-deleted {
	font-size: 11px;
	font-style: italic;
	opacity: 0.75;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	overflow-wrap: anywhere;
}

/*
 * "not pushed · retry" under the title: the same shape as the request note, but a real button - it is
 * the action that clears the state it describes. Quiet on purpose (the state badge is the loud thing)
 * and full width of its own line, so a narrow column wraps instead of overflowing.
 */
.pages-articles__push {
	align-self: flex-start;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 12px);
	background: transparent;
	color: var(--color-error-text, var(--color-error));
	font-size: 11px;
	line-height: 16px;
	padding: 0 6px;
	cursor: pointer;
	white-space: nowrap;
}

.pages-articles__push:hover,
.pages-articles__push:focus-visible {
	background-color: var(--color-background-hover);
}

.pages-articles__cell--tight {
	font-size: 12px;
	opacity: 0.8;
	white-space: nowrap;
}

.pages-articles__note {
	color: var(--color-error);
	font-size: 12px;
	overflow-wrap: anywhere;
}

/*
 * The action buttons never shrink: a squeezed NcButton ellipsizes its label ("Take of…", "De…"),
 * because its text is `white-space: nowrap` with `text-overflow: ellipsis` inside a flex item that
 * may be compressed. Keeping their own width and wrapping the cell instead means every action stays
 * fully readable, whatever a row offers — and the cell still ends at its column's edge, so nothing
 * spills into a neighbouring column or widens the table.
 */
.pages-articles__cell--actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: flex-end;
	gap: 6px;
}

.pages-articles__cell--actions :deep(> *) {
	flex-shrink: 0;
}

.pages-articles__actions-head {
	text-align: end;
}
</style>
