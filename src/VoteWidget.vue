<!--
	The vote widget of one article: the two counts as buttons, with the caller's own vote highlighted.

	Clicking the vote one already holds withdraws it, which is what the API expects to hear (`'none'`).
	The numbers shown come from the server's answer to every click; the optimistic step exists only to
	keep the button responsive, and it is rolled back the moment the server refuses — the widget never
	claims a vote that was not stored.
-->
<template>
	<div class="pages-vote">
		<NcButton
			:variant="current.mine === 'up' ? 'primary' : 'tertiary'"
			:disabled="busy"
			:title="current.mine === 'up' ? t('pages', 'Withdraw your vote') : t('pages', 'Vote up')"
			:aria-pressed="current.mine === 'up'"
			@click="cast('up')">
			<template #icon>
				<NcLoadingIcon v-if="busy && pending === 'up'" :size="20" />
				<ThumbUp v-else :size="20" />
			</template>
			{{ t('pages', 'Up') }} {{ current.up }}
		</NcButton>

		<NcButton
			:variant="current.mine === 'down' ? 'primary' : 'tertiary'"
			:disabled="busy"
			:title="current.mine === 'down' ? t('pages', 'Withdraw your vote') : t('pages', 'Vote down')"
			:aria-pressed="current.mine === 'down'"
			@click="cast('down')">
			<template #icon>
				<NcLoadingIcon v-if="busy && pending === 'down'" :size="20" />
				<ThumbDown v-else :size="20" />
			</template>
			{{ t('pages', 'Down') }} {{ current.down }}
		</NcButton>

		<span class="pages-vote__score">{{ scoreText }}</span>
	</div>
</template>

<script>
import { t } from './i18n.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ThumbDown from 'vue-material-design-icons/ThumbDown.vue'
import ThumbUp from 'vue-material-design-icons/ThumbUp.vue'

import api from './api.js'
import { mineOf, optimisticVote, toggleVote, voteContextOf } from './reading.js'

export default {
	name: 'VoteWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		ThumbDown,
		ThumbUp,
	},

	props: {
		/** The article being read; only the vote fields are used */
		article: {
			type: Object,
			required: true,
		},
	},

	emits: ['updated', 'failed'],

	data() {
		return {
			/** A local copy: the numbers follow the server's answers, not the prop alone */
			current: voteContextOf(this.article),
			busy: false,
			/** Which button is in flight, so only that one shows a spinner */
			pending: '',
		}
	},

	computed: {
		scoreText() {
			const score = this.current.up - this.current.down
			return score > 0 ? `+${score}` : String(score)
		},
	},

	watch: {
		/**
		 * Every answer about this article replaces the numbers — including the parent's list refresh
		 * after a vote, which is the server's own view of them. `immediate` keeps a re-mounted widget
		 * in step with an article that was already loaded.
		 */
		article: {
			handler(value) {
				this.current = voteContextOf(value)
			},
			deep: true,
			immediate: true,
		},
	},

	methods: {
		t,

		/** @param {'up'|'down'} clicked */
		async cast(clicked) {
			if (this.busy) {
				return
			}
			const path = String(this.article?.path || '')
			if (path === '') {
				this.$emit('failed', t('pages', 'This article cannot be voted on.'))
				return
			}
			const before = { ...this.current }
			const next = toggleVote(this.current.mine, clicked)
			// responsive first, honest second: this is replaced by the server's numbers below
			this.current = { ...this.current, ...optimisticVote(this.current, next) }
			this.busy = true
			this.pending = clicked

			const result = await api.vote(path, next)
			this.busy = false
			this.pending = ''

			if (!result.ok) {
				// the server refused it, so the numbers go back: the UI must not claim the vote
				this.current = before
				this.$emit('failed', result.message || t('pages', 'The vote was not saved.'))
				return
			}
			const updated = result.data?.article || {}
			this.current = {
				up: Number(updated.up) || 0,
				down: Number(updated.down) || 0,
				// the endpoint answers with the number the column holds (1 / -1 / 0); `mineOf` is the one
				// place that understands both shapes, so the button shows as held straight away instead of
				// only after the next list refresh
				mine: mineOf(updated),
			}
			this.$emit('updated', { path, up: this.current.up, down: this.current.down, mine: this.current.mine })
		},
	},
}
</script>

<style scoped>
.pages-vote {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.pages-vote__score {
	font-size: 13px;
	font-weight: 600;
	opacity: 0.75;
}
</style>
