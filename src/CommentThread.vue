<!--
	The comment thread of one article: plain text in, plain text out.

	The API is explicit that a comment body is plain text, so it is rendered as text — never as markup.
	Every action (post, delete) re-reads the list from the server instead of patching it locally, which
	is what keeps the thread honest when a comment was deleted elsewhere or the count moved.

	A 429 is a wait rather than a failure, and the server says how long: that number is put into the
	message instead of a generic error.
-->
<template>
	<div class="pages-comments">
		<h3 class="pages-comments__title">
			{{ t('pages', 'Comments') }}
			<span v-if="count !== null" class="pages-comments__count">{{ count }}</span>
		</h3>

		<NcNoteCard v-if="notice" :type="notice.type" class="pages-comments__notice">
			{{ notice.message }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="20" />
		<p v-else-if="error" class="pages-comments__empty">{{ error }}</p>
		<p v-else-if="comments.length === 0" class="pages-comments__empty">
			{{ t('pages', 'No comments yet.') }}
		</p>

		<ul v-else class="pages-comments__list">
			<li v-for="comment in comments" :key="comment.id" class="pages-comments__item">
				<div class="pages-comments__head">
					<span class="pages-comments__author">{{ authorOf(comment) }}</span>
					<span class="pages-comments__time" :title="absoluteTime(comment.createdAt)">
						{{ relativeLabel(comment.createdAt) }}
					</span>
					<span v-if="isEdited(comment)" class="pages-comments__edited">
						{{ t('pages', '(edited)') }}
					</span>
					<span class="pages-comments__spacer" />
					<NcButton
						v-if="canDelete(comment)"
						variant="tertiary"
						:disabled="deleting.has(comment.id)"
						:title="t('pages', 'Delete this comment')"
						@click="remove(comment)">
						<template #icon>
							<NcLoadingIcon v-if="deleting.has(comment.id)" :size="20" />
							<Delete v-else :size="20" />
						</template>
					</NcButton>
				</div>
				<!-- Plain text: `white-space: pre-wrap` keeps the line breaks, nothing is interpreted. -->
				<p class="pages-comments__body">{{ comment.body }}</p>
			</li>
		</ul>

		<div class="pages-comments__form">
			<textarea
				v-model="draft"
				class="pages-comments__input"
				rows="3"
				:maxlength="MAX_LENGTH"
				:placeholder="t('pages', 'Write a comment…')"
				:aria-label="t('pages', 'Write a comment…')" />
			<div class="pages-comments__form-row">
				<span class="pages-comments__counter" :class="{ 'pages-comments__counter--over': draft.length > MAX_LENGTH }">
					{{ draft.length }} / {{ MAX_LENGTH }}
				</span>
				<NcButton
					variant="primary"
					:disabled="posting || draft.trim() === ''"
					@click="post">
					<template #icon>
						<NcLoadingIcon v-if="posting" :size="20" />
						<Send v-else :size="20" />
					</template>
					{{ t('pages', 'Post comment') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Delete from 'vue-material-design-icons/Delete.vue'
import Send from 'vue-material-design-icons/Send.vue'

import api from './api.js'
import { absoluteTime, commentFailure, relativeLabel } from './reading.js'

/** The API's limit; the textarea stops there and the counter shows where the writer stands. */
const MAX_LENGTH = 2000

export default {
	name: 'CommentThread',

	components: {
		Delete,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Send,
	},

	props: {
		/** The article target the thread belongs to */
		path: {
			type: String,
			required: true,
		},
		/** With the publish grant a caller may delete anybody's comment, not only their own */
		canModerate: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['changed'],

	data() {
		return {
			MAX_LENGTH,
			comments: [],
			count: null,
			loading: false,
			error: '',
			draft: '',
			posting: false,
			/** Ids with a delete in flight */
			deleting: new Set(),
			notice: null,
			noticeTimer: null,
		}
	},

	watch: {
		/** Another article: another thread. The draft is dropped with it, it belonged to that one. */
		path: {
			handler() {
				this.draft = ''
				this.notice = null
				this.refresh()
			},
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
		relativeLabel,
		absoluteTime,

		async refresh() {
			if (!this.path) {
				return
			}
			this.loading = true
			const result = await api.comments(this.path)
			this.loading = false
			if (!result.ok) {
				this.comments = []
				this.count = null
				this.error = result.status === 403
					? t('pages', 'You may not read the comments of this article.')
					: (result.message || t('pages', 'The comments could not be loaded.'))
				return
			}
			this.error = ''
			this.comments = Array.isArray(result.data?.comments) ? result.data.comments : []
			this.count = Number.isFinite(Number(result.data?.count)) ? Number(result.data.count) : this.comments.length
		},

		/** The display name when the server sends one, the uid otherwise. */
		authorOf(comment) {
			const name = String(comment?.authorName ?? '').trim()
			if (name !== '') {
				return name
			}
			return String(comment?.author ?? '').trim() || t('pages', '(unknown author)')
		},

		isEdited(comment) {
			const created = Number(comment?.createdAt)
			const updated = Number(comment?.updatedAt)
			return Number.isFinite(created) && Number.isFinite(updated) && updated > created
		},

		canDelete(comment) {
			return comment?.mine === true || this.canModerate === true
		},

		async post() {
			const body = this.draft.trim()
			if (body === '' || this.posting) {
				return
			}
			if (body.length > MAX_LENGTH) {
				this.showNotice('error', t('pages', 'The comment is too long (%1$s of %2$s characters).', [String(body.length), String(MAX_LENGTH)]))
				return
			}
			this.posting = true
			const result = await api.addComment(this.path, body)
			this.posting = false
			if (!result.ok) {
				this.showNotice('error', this.failureText(result))
				return
			}
			// only a stored comment clears the box; a refused one must not eat what was written
			this.draft = ''
			this.showNotice('success', t('pages', 'Comment posted.'))
			await this.refresh()
			this.$emit('changed')
		},

		async remove(comment) {
			const id = Number(comment?.id)
			if (!Number.isFinite(id) || this.deleting.has(id)) {
				return
			}
			this.markDeleting(id, true)
			const result = await api.deleteComment(id)
			this.markDeleting(id, false)
			if (!result.ok) {
				this.showNotice('error', this.failureText(result))
				return
			}
			this.showNotice('success', t('pages', 'Comment deleted.'))
			await this.refresh()
			this.$emit('changed')
		},

		/** The words for a failed write, with the wait the server asked for when it was a rate limit. */
		failureText(result) {
			const failure = commentFailure(result)
			if (failure.kind === 'rate') {
				return failure.seconds > 0
					? t('pages', 'Please wait %s seconds before commenting again.', [String(failure.seconds)])
					: (failure.message || t('pages', 'You are commenting too quickly. Please wait a moment.'))
			}
			if (failure.kind === 'forbidden') {
				return failure.message || t('pages', 'You may not do that with this comment.')
			}
			if (failure.kind === 'gone') {
				return t('pages', 'That comment no longer exists.')
			}
			return failure.message || t('pages', 'The comment could not be saved.')
		},

		markDeleting(id, value) {
			const next = new Set(this.deleting)
			if (value) {
				next.add(id)
			} else {
				next.delete(id)
			}
			this.deleting = next
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
.pages-comments {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pages-comments__title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 15px;
}

.pages-comments__count {
	font-size: 12px;
	opacity: 0.6;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	padding: 0 8px;
}

.pages-comments__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.pages-comments__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.pages-comments__head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pages-comments__author {
	font-weight: 600;
	font-size: 13px;
}

.pages-comments__time,
.pages-comments__edited {
	font-size: 12px;
	opacity: 0.6;
}

.pages-comments__spacer {
	flex: 1 1 auto;
}

/* Plain text with its line breaks kept: the API says the body is text, so it is never markup. */
.pages-comments__body {
	margin: 2px 0 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	font-size: 13px;
	line-height: 1.5;
}

.pages-comments__empty {
	opacity: 0.7;
	margin: 0;
}

.pages-comments__form {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 4px;
}

.pages-comments__input {
	width: 100%;
	font-family: inherit;
	font-size: 13px;
	resize: vertical;
}

.pages-comments__form-row {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
}

.pages-comments__counter {
	font-size: 12px;
	opacity: 0.6;
	margin-inline-end: auto;
}

.pages-comments__counter--over {
	color: var(--color-error);
	opacity: 1;
}

.pages-comments__notice {
	margin: 0;
}
</style>
