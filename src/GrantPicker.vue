<!--
	One capability of the Permissions panel: the groups that hold it, picked with Nextcloud's own
	select — search, several groups at once, and one removable tag per granted group.

	An empty list is not "nobody": it keeps the capability's documented default (authoring: every
	signed-in user; review and publishing: the administrators alone). That is why the default is the
	picker's placeholder and not an option in the list — picking such an option would mean the same as
	leaving the list empty, and it would read like a group that can be granted.

	NcSelect clears the placeholder for a multi-select (the search field is always there), so the same
	text is written below an empty picker as well; `placeholder` is still passed on, because that is
	the prop a future version would read.
-->
<template>
	<div class="pages-grant">
		<label class="pages-grant__label" :for="pickerId">{{ label }}</label>
		<NcSelect
			:input-id="pickerId"
			:model-value="modelValue"
			:options="options"
			:multiple="true"
			label="displayName"
			:reduce="option => option.id"
			:placeholder="placeholder"
			label-outside
			class="pages-grant__select"
			@update:model-value="update" />
		<p v-if="isEmpty && placeholder" class="pages-grant__default">{{ placeholder }}</p>
	</div>
</template>

<script>
import { useId } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'GrantPicker',

	components: {
		NcSelect,
	},

	props: {
		/** The capability name, shown above the picker (translated by the server) */
		label: {
			type: String,
			required: true,
		},
		/** Every group of this instance, as `{ id, displayName }` */
		options: {
			type: Array,
			default: () => [],
		},
		/** The granted group ids; an empty array means the documented default */
		modelValue: {
			type: Array,
			default: () => [],
		},
		/** What an empty list means for this capability, shown while nothing is selected */
		placeholder: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	setup() {
		// the label is translated, so the id may not be derived from its text
		return { pickerId: 'pages-grant-' + useId() }
	},

	computed: {
		isEmpty() {
			return !Array.isArray(this.modelValue) || this.modelValue.length === 0
		},
	},

	methods: {
		/**
		 * The picker answers with what `reduce` produced — one id, or a list of them. The parent
		 * always gets a list of strings, which is the shape the API and the hidden input take.
		 *
		 * @param {Array|string|number|null} value the picker's selection
		 */
		update(value) {
			const list = Array.isArray(value) ? value : (value === null || value === undefined ? [] : [value])
			this.$emit('update:modelValue', list.map(String))
		},
	},
}
</script>

<style scoped>
/* The picker sits in the settings column where the native multi-select used to be */
.pages-grant {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0 4px;
	max-width: 520px;
}

.pages-grant__label {
	font-weight: 600;
}

.pages-grant__select {
	width: 100%;
}

/* What an empty list means: visible because the multi-select does not show its placeholder */
.pages-grant__default {
	margin: 0;
	font-size: 13px;
	opacity: 0.75;
}
</style>
