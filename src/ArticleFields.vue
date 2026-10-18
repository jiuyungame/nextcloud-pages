<!--
	The fields panel of an article: everything that lives in the front matter, as form fields.

	The editor below it holds the body only, so this panel is the only place a title, the tags, the
	date or the summary can be changed — and a save sends only the fields that actually differ from
	what the server handed over (absent = leave unchanged, empty = remove the key).

	Plain `<label>` + `<input>` rather than a component: Nextcloud's text field only renders its
	`label-outside` variant in some versions, and a panel whose fields have no visible names is worse
	than a plain one. The date, the tags and the file name share one row, the file name being what
	fills the space the two small fields leave; the only read-only fact left (the address in the
	built site) sits on a compact line below, because it is reference material, not a field.

	Additional metadata: every front-matter key this panel does not own is shown in a compact
	read-only table (five rows at most, with the number that was left out) and edited in an `NcDialog`
	with one editable row per key. The dialog works on a copy: Cancel or Esc changes nothing, Save
	emits the whole map and the app posts the delta with the next save (a row that was removed is sent
	as an empty value, which is how the server deletes a key).

	The date may not be in the future: the field says so on its own, and `App.save()` refuses to send
	a request while it does. The server applies the same rule with its own clock, so a client that
	skips this panel is still refused.
-->
<template>
	<div class="pages-fields">
		<!--
			The panel is laid out for reading at a glance: the title takes the whole width with the
			metadata trigger beside its label, the four compact facts share one row, the summary gets the
			next one. The explanations live behind a circled "!" (hover or focus shows them) instead of
			sitting under every input, which used to double the height of the panel.
		-->
		<div class="pages-fields__row">
			<label class="pages-fields__field pages-fields__field--wide" :for="id('title')">
				<span class="pages-fields__label">
					{{ t('pages', 'Title') }}
				</span>
				<input
					:id="id('title')"
					class="pages-fields__input"
					type="text"
					:value="current.title"
					:placeholder="t('pages', 'Article title')"
					@input="(event) => update('title', event.target.value)">
			</label>
			<button type="button" class="pages-fields__link pages-fields__meta-link" @click="openMeta">
				{{ t('pages', 'Edit metadata') }}
			</button>
		</div>

		<div class="pages-fields__row">
			<label class="pages-fields__field pages-fields__field--date" :for="id('date')">
				<span class="pages-fields__label">{{ t('pages', 'Date') }}</span>
				<input
					:id="id('date')"
					class="pages-fields__input"
					type="date"
					:value="current.date"
					:aria-invalid="dateInvalid"
					:aria-describedby="dateInvalid ? id('date-error') : null"
					@input="(event) => update('date', event.target.value)">
				<!-- the value is never clamped: the message says what to change -->
				<span v-if="dateInvalid" :id="id('date-error')" class="pages-fields__error" role="alert">
					{{ t('pages', 'The date may not be in the future.') }}
				</span>
			</label>
			<label class="pages-fields__field pages-fields__field--tags" :for="id('tags')">
				<span class="pages-fields__label">{{ t('pages', 'Tags') }}</span>
				<input
					:id="id('tags')"
					class="pages-fields__input"
					type="text"
					:value="current.tagsText"
					:placeholder="t('pages', 'first, second')"
					@input="(event) => update('tagsText', event.target.value)">
			</label>
			<label class="pages-fields__field pages-fields__field--layout" :for="id('layout')">
				<span class="pages-fields__label">
					{{ t('pages', 'Layout') }}
					<span
						class="pages-fields__info"
						role="img"
						:aria-label="t('pages', 'The template layout that renders this page. Empty means the default, page.')"
						:title="t('pages', 'The template layout that renders this page. Empty means the default, page.')">!</span>
				</span>
				<input
					:id="id('layout')"
					class="pages-fields__input"
					type="text"
					:value="layoutValue"
					:placeholder="t('pages', 'page')"
					@input="(event) => setLayout(event.target.value)">
			</label>
			<label class="pages-fields__field pages-fields__field--categories" :for="id('categories')">
				<span class="pages-fields__label">
					{{ t('pages', 'Categories') }}
					<span
						class="pages-fields__info"
						role="img"
						:aria-label="t('pages', 'One level, separated by commas, at most 5. A category only takes effect when the site has a page of that name at its root (e.g. guides.md): the page is then published under /<category>/<title>.html.')"
						:title="t('pages', 'One level, separated by commas, at most 5. A category only takes effect when the site has a page of that name at its root (e.g. guides.md): the page is then published under /<category>/<title>.html.')">!</span>
				</span>
				<input
					:id="id('categories')"
					class="pages-fields__input"
					type="text"
					:value="current.categoriesText"
					:placeholder="t('pages', 'guides, notes')"
					@input="(event) => update('categoriesText', event.target.value)">
			</label>
		</div>

		<div class="pages-fields__row">
			<label class="pages-fields__field pages-fields__field--wide" :for="id('excerpt')">
				<span class="pages-fields__label">
					{{ t('pages', 'Summary') }}
					<span
						class="pages-fields__info"
						role="img"
						:aria-label="t('pages', 'Leave it empty to let the site use the first paragraph as the summary.')"
						:title="t('pages', 'Leave it empty to let the site use the first paragraph as the summary.')">!</span>
				</span>
				<textarea
					:id="id('excerpt')"
					class="pages-fields__input pages-fields__textarea"
					rows="2"
					:value="current.excerpt"
					:placeholder="excerptHint"
					@input="(event) => update('excerpt', event.target.value)" />
			</label>
		</div>

		<!--
			The file name is not edited here: it is the page's address, and the left file tree is where
			it is renamed (inline, through Nextcloud's own move). Duplicating it in the panel made two
			places able to change the same thing.
		-->

		<!--
			The additional metadata is reached through the "Edit metadata" link beside the title and
			nowhere else: no read-only table in the panel, so the panel stays a panel and the dialog is
			the single place where those keys are seen and changed.
		-->

		<!-- the one read-only fact: the address the built site serves this article at -->
		<dl v-if="permalink" class="pages-fields__facts">
			<div class="pages-fields__fact pages-fields__fact--wide">
				<dt>{{ t('pages', 'Address in the built site') }}</dt>
				<!-- A permalink is site-relative (`/2026/05/hello.html`), so it is shown as text: the
				     app has no absolute base URL for the built site, only the preview's token URL. -->
				<dd class="pages-fields__code">{{ permalink }}</dd>
			</div>
		</dl>

		<NcDialog
			v-if="metaOpen"
			:name="t('pages', 'Additional metadata')"
			size="large"
			:buttons="metaButtons"
			@closing="metaOpen = false">
			<div class="pages-meta">
				<p class="pages-fields__hint">
					{{ t('pages', 'Keys other than title, date, tags, categories and the summary. New keys are appended at the end of the block; an empty value removes the key.') }}
				</p>
				<table class="pages-meta__table">
					<thead>
						<tr>
							<th scope="col">{{ t('pages', 'Key') }}</th>
							<th scope="col">{{ t('pages', 'Value') }}</th>
							<th scope="col">{{ t('pages', 'Type') }}</th>
							<th scope="col"><span class="hidden-visually">{{ t('pages', 'Remove') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in rows" :key="index">
							<td>
								<input
									v-model="row.key"
									class="pages-fields__input pages-meta__key"
									type="text"
									:aria-label="t('pages', 'Key')"
									placeholder="key">
							</td>
							<td>
								<input
									v-if="row.type === 'boolean'"
									v-model="row.value"
									class="pages-meta__check"
									type="checkbox"
									:aria-label="t('pages', 'Value')">
								<input
									v-else
									v-model="row.value"
									class="pages-fields__input"
									:type="row.type === 'number' ? 'number' : 'text'"
									:aria-label="t('pages', 'Value')"
									:placeholder="row.type === 'list' ? t('pages', 'one, two') : ''">
							</td>
							<td>
								<select v-model="row.type" class="pages-fields__input" :aria-label="t('pages', 'Type')" @change="onTypeChange(row)">
									<option value="text">{{ t('pages', 'Text') }}</option>
									<option value="number">{{ t('pages', 'Number') }}</option>
									<option value="boolean">{{ t('pages', 'Boolean') }}</option>
									<option value="list">{{ t('pages', 'List') }}</option>
								</select>
							</td>
							<td>
								<button type="button" class="pages-meta__remove" :aria-label="t('pages', 'Remove')" @click="removeRow(index)">
									×
								</button>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="pages-meta__actions">
					<button type="button" class="pages-fields__link" @click="addRow">
						{{ t('pages', 'Add row') }}
					</button>
				</div>
				<p v-if="metaError" class="pages-fields__error" role="alert">{{ metaError }}</p>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { t } from './i18n.js'
import { displayMetaValue, extrasOf, splitTarget } from './articles.js'
import { isFutureDay } from './articleGeometry.js'

/** How many additional-metadata rows the read-only table shows before it counts the rest. */
const EXTRA_TABLE_ROWS = 5

/** The shape a new or changed row starts with, per type. */
const TYPE_DEFAULTS = { text: '', number: '', boolean: false, list: '' }

export default {
	name: 'ArticleFields',

	components: {
		NcDialog,
	},

	props: {
		/** The field values being edited: {title, date, tagsText, excerpt, extra} */
		current: {
			type: Object,
			required: true,
		},
		/** Everything the server said about the article and this panel only displays */
		meta: {
			type: Object,
			default: () => ({}),
		},
		/** The article target, shown as the file name */
		path: {
			type: String,
			default: '',
		},
		/** The automatic excerpt, used as the summary placeholder while the field is empty */
		autoExcerpt: {
			type: String,
			default: '',
		},
	},

	emits: ['update', 'meta-save'],

	data() {
		return {
			/** Whether the metadata dialog is open. It edits a copy, never `meta` itself. */
			metaOpen: false,
			/** One editable row per additional key: {key, value, type} */
			rows: [],
			/** Why the dialog refused to save (a bad key, a duplicate, a non-number) */
			metaError: '',
		}
	},

	computed: {
		/** The address of the article in the built site, when the server knows one. */
		permalink() {
			return String(this.meta.permalink ?? '').trim()
		},

		/** The file name split into the parts a user may see: the date, the name and the extension. */
		parts() {
			return splitTarget(this.path)
		},

		day() {
			return this.parts.day
		},

		name() {
			return this.parts.name
		},

		ext() {
			return this.parts.ext
		},

		/** The unknown keys of the block, in the file's own order, with a value the table can show. */
		extraRows() {
			return Object.entries(extrasOf(this.meta)).map(([key, value]) => ({
				key,
				display: displayMetaValue(value),
			}))
		},

		visibleExtras() {
			return this.extraRows.slice(0, EXTRA_TABLE_ROWS)
		},

		hiddenExtras() {
			return Math.max(0, this.extraRows.length - EXTRA_TABLE_ROWS)
		},

		/**
		 * The date field holds a day after today: the field marks itself invalid and the save is
		 * refused before a request is made, instead of rewriting the value.
		 */
		dateInvalid() {
			return isFutureDay(this.current.date)
		},

		/**
		 * The layout this page renders with. It is not a first-class field: `layout` travels with the
		 * other front-matter keys the panel does not own, so the input reads and writes that map — the
		 * same map the metadata dialog edits, which is what keeps the two from disagreeing.
		 */
		layoutValue() {
			const extra = this.current && typeof this.current.extra === 'object' && this.current.extra !== null
				? this.current.extra
				: {}
			const value = extra.layout
			return value === undefined || value === null || value === '' ? 'page' : String(value)
		},

		/**
		 * The placeholder of the summary: the first paragraph of the body, so an empty field visibly
		 * means "the site decides" rather than "no summary".
		 */
		excerptHint() {
			return this.autoExcerpt || t('pages', 'Automatic: the first paragraph of the article')
		},

		/** The dialog's own footer: Cancel closes, Save validates and hands the rows to the app. */
		metaButtons() {
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => {
						this.metaOpen = false
					},
				},
				{
					label: t('pages', 'Save'),
					variant: 'primary',
					callback: () => this.applyMeta(),
				},
			]
		},
	},

	methods: {
		t,

		/** An id the labels can point at, unique per open file. */
		id(name) {
			return 'pages-field-' + name + '-' + String(this.path || 'article').replace(/[^a-zA-Z0-9]/g, '-')
		},

		/** @param {'title'|'date'|'tagsText'|'categoriesText'|'excerpt'|'fileName'} key @param {string} value */
		update(key, value) {
			this.$emit('update', { key, value })
		},

		/**
		 * Write the layout into the extra map. Empty means "no layout key at all", which the site
		 * renders with its default — the same contract the metadata dialog uses for an empty value.
		 */
		setLayout(value) {
			const extra = this.current && typeof this.current.extra === 'object' && this.current.extra !== null
				? { ...this.current.extra }
				: {}
			const next = String(value ?? '').trim()
			if (next === '' || next === 'page') {
				delete extra.layout
			} else {
				extra.layout = next
			}
			this.update('extra', extra)
		},

		/** Open the dialog on a copy of what the block holds now: Cancel leaves the file untouched. */
		openMeta() {
			this.rows = Object.entries(extrasOf(this.meta)).map(([key, value]) => ({
				key,
				type: this.typeOf(value),
				value: this.editValue(value),
			}))
			this.metaError = ''
			this.metaOpen = true
		},

		typeOf(value) {
			if (Array.isArray(value)) {
				return 'list'
			}
			if (typeof value === 'boolean') {
				return 'boolean'
			}
			if (typeof value === 'number') {
				return 'number'
			}
			return 'text'
		},

		/** A value as the row's input holds it: a list becomes the comma-separated line a human edits. */
		editValue(value) {
			if (Array.isArray(value)) {
				return value.map(String).join(', ')
			}
			if (typeof value === 'boolean') {
				return value
			}
			return String(value ?? '')
		},

		/** Switching the type starts the value from something that type can hold. */
		onTypeChange(row) {
			if (!Object.prototype.hasOwnProperty.call(TYPE_DEFAULTS, row.type)) {
				row.type = 'text'
			}
			row.value = TYPE_DEFAULTS[row.type]
		},

		addRow() {
			const used = new Set(this.rows.map((row) => String(row.key || '').trim()))
			let index = this.rows.length + 1
			while (used.has('key_' + index)) {
				index += 1
			}
			this.rows.push({ key: 'key_' + index, value: '', type: 'text' })
		},

		removeRow(index) {
			this.rows.splice(index, 1)
		},

		/**
		 * Validate the rows and hand the whole map to the app, which merges it into the save. Nothing
		 * is written here: a refused save is the app's banner, not a second error channel.
		 *
		 * @returns {boolean} false keeps the dialog open (NcDialogButton convention)
		 */
		applyMeta() {
			const map = {}
			for (const row of this.rows) {
				const key = String(row.key ?? '').trim()
				if (!/^[a-z0-9_]+$/.test(key) || key.length > 64) {
					this.metaError = t('pages', 'A key may only use lower-case letters, digits and underscore, and be at most 64 characters long.')
					return false
				}
				if (Object.prototype.hasOwnProperty.call(map, key)) {
					this.metaError = t('pages', 'Two rows use the same key: %s', [key])
					return false
				}
				if (row.type === 'number') {
					const number = Number(String(row.value ?? '').trim())
					if (String(row.value ?? '').trim() === '' || !Number.isFinite(number)) {
						this.metaError = t('pages', 'The value of “%s” is not a number.', [key])
						return false
					}
					map[key] = number
				} else if (row.type === 'boolean') {
					map[key] = row.value === true
				} else if (row.type === 'list') {
					map[key] = String(row.value ?? '')
						.split(/[\n,]/)
						.map((item) => item.trim())
						.filter((item) => item !== '')
				} else {
					map[key] = String(row.value ?? '').trim()
				}
			}
			this.metaError = ''
			this.metaOpen = false
			this.$emit('meta-save', map)
			return true
		},
	},
}
</script>

<style scoped>
.pages-fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	flex: 0 0 auto;
	/* the body editor keeps the room that is left: the panel is reference material, not the work */
	max-height: 38vh;
	overflow: auto;
}

.pages-fields__row {
	display: flex;
	/* the file name moves to its own line when the three fields no longer fit side by side */
	flex-wrap: wrap;
	gap: 12px;
	min-width: 0;
}

.pages-fields__field {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
	flex: 0 1 auto;
}

.pages-fields__field--wide {
	flex: 1 1 auto;
}

/* the date and the tags keep their own width; the file name takes what is left of the row */
.pages-fields__field--date {
	flex: 0 0 auto;
}

.pages-fields__field--tags {
	flex: 0 1 auto;
}

/* Layout and Categories share the second row with the date and the tags: each takes an equal share
   and wraps on its own line once the pane is narrow, so four facts read at a glance on a wide one. */
.pages-fields__field--layout,
.pages-fields__field--categories {
	flex: 1 1 120px;
}

.pages-fields__meta-link {
	margin-inline-start: auto;
	align-self: flex-end;
	white-space: nowrap;
}

/* The circled "!": the explanations of the fields, on hover and on focus instead of under every
   input. `cursor: help` is the one hint that it is hoverable. */
.pages-fields__info {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 14px;
	height: 14px;
	margin-inline-start: 4px;
	border: 1px solid currentColor;
	border-radius: 50%;
	font-size: 10px;
	font-weight: 700;
	line-height: 1;
	opacity: 0.55;
	cursor: help;
	vertical-align: middle;
}

.pages-fields__info:hover,
.pages-fields__info:focus {
	opacity: 1;
}

/*
 * The file name fills the free space of the date row. Its basis is deliberately smaller than that
 * space (about 600 px in the normal split at a 1500 px window), so the three fields stay on one line
 * there and only wrap once the pane is really narrow; growing then takes whatever is left.
 */
.pages-fields__field--name {
	flex: 1 1 170px;
}

.pages-fields__label {
	font-size: 12px;
	font-weight: 600;
	opacity: 0.75;
}

.pages-fields__input {
	width: 100%;
	min-width: 0;
	box-sizing: border-box;
	padding: 5px 8px;
	font-family: inherit;
	font-size: 13px;
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 3px);
}

.pages-fields__input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.pages-fields__textarea {
	resize: vertical;
	line-height: 1.4;
}

.pages-fields__hint {
	font-size: 11px;
	opacity: 0.6;
	line-height: 1.4;
}

/* the read-only facts: one compact block of label/value pairs, not more form rows */
.pages-fields__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 2px 20px;
	margin: 0;
	padding-top: 6px;
	border-top: 1px dashed var(--color-border);
	text-align: left;
}

.pages-fields__fact {
	display: flex;
	align-items: baseline;
	gap: 6px;
	min-width: 0;
	max-width: 100%;
	font-size: 12px;
	text-align: left;
}

.pages-fields__fact--wide {
	flex: 1 0 100%;
}

.pages-fields__fact dt {
	font-weight: 600;
	opacity: 0.7;
	white-space: nowrap;
}

.pages-fields__fact dd {
	margin: 0;
	opacity: 0.9;
	overflow-wrap: anywhere;
}

.pages-fields__code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 11px;
}

/* the editable name sits between the fixed date prefix and the fixed extension */
.pages-fields__name {
	display: flex;
	align-items: center;
	gap: 4px;
	min-width: 0;
	width: 100%;
	/*
	 * The date and the extension are fixed parts of the name and must never break: they used to wrap
	 * ("2026-10-" over "15") as soon as the row ran short, which reads as two values instead of one
	 * file name. Only the editable middle may shrink.
	 */
	white-space: nowrap;
}

.pages-fields__name .pages-fields__code {
	flex: 0 0 auto;
}

/* fills the free area of the row, so the name is as long as the space the tags left open */
.pages-fields__input--name {
	flex: 1 1 auto;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	padding: 3px 6px;
}

/* a refused value: the field says why instead of quietly rewriting what was typed */
.pages-fields__error {
	font-size: 11px;
	line-height: 1.4;
	color: var(--color-error-text, var(--color-error));
}

/* additional metadata: a compact two-column table, then the dialog behind the link */
.pages-fields__extra {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.pages-fields__extra-table {
	border-collapse: collapse;
	font-size: 12px;
	max-width: 100%;
}

.pages-fields__extra-table th,
.pages-fields__extra-table td {
	padding: 1px 10px 1px 0;
	text-align: left;
	vertical-align: top;
	max-width: 320px;
	overflow-wrap: anywhere;
}

.pages-fields__extra-table th {
	font-weight: 600;
	opacity: 0.7;
	white-space: nowrap;
}

.pages-fields__extra-footer {
	display: flex;
	align-items: center;
	gap: 10px;
}

.pages-fields__link {
	padding: 0;
	border: 0;
	background: none;
	color: var(--color-primary-element);
	font-size: 12px;
	cursor: pointer;
	text-decoration: underline;
}

/* the dialog: one row per key, the same table shape as the panel */
.pages-meta {
	display: flex;
	flex-direction: column;
	gap: 8px;
	/* fill whatever width the dialog gives us: the fixed min-width forced a horizontal scrollbar
	   inside an already narrow dialog */
	width: 100%;
}

.pages-meta__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.pages-meta__table th {
	font-size: 12px;
	font-weight: 600;
	opacity: 0.7;
	text-align: left;
	padding: 2px 8px 2px 0;
}

.pages-meta__table td {
	padding: 2px 8px 2px 0;
	vertical-align: middle;
}

.pages-meta__key {
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
}

.pages-meta__check {
	width: 18px;
	height: 18px;
	margin: 0;
}

.pages-meta__remove {
	border: 0;
	background: none;
	color: var(--color-error-text, var(--color-error));
	font-size: 18px;
	line-height: 1;
	cursor: pointer;
	padding: 0 4px;
}

.pages-meta__actions {
	display: flex;
	gap: 10px;
}
</style>
