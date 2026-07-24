<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Step result — {step}', { step: title })"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="step-result">
			<NcNoteCard v-if="halted" type="warning">
				{{ t('hermiq', 'This step halted the graph — nothing after it ran.') }}
			</NcNoteCard>

			<p class="step-result__hint">
				{{ hintText }}
			</p>

			<pre class="step-result__json">{{ pretty }}</pre>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="copy">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('hermiq', 'Copy JSON') }}
			</NcButton>
			<NcButton type="primary" @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import { showSuccess } from '@nextcloud/dialogs'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

/**
 * StepResultDialog — one step's output from the last run, as JSON.
 *
 * Opened from the badge sitting on an edge: the edge leaves the node that
 * produced this result, so the badge reads as "what came out of here and
 * flows onward". Own file per the modal-isolation rule.
 */
export default {
	name: 'StepResultDialog',

	components: {
		ContentCopy,
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	props: {
		/** Label of the step that produced this result. */
		title: {
			type: String,
			default: '',
		},
		/** The step's trace entry. */
		result: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * Explanatory line. Built here rather than inline because the sentence
		 * contains a placeholder token in double braces, which the template
		 * compiler would try to parse as an interpolation.
		 *
		 * @return {string} The hint.
		 */
		hintText() {
			const token = '{{key}}'
			return this.t(
				'hermiq',
				'What this step put on the run state. Later nodes read these values via {token} placeholders.',
				{ token },
			)
		},

		/** @return {string} The result, pretty-printed. */
		pretty() {
			try {
				return JSON.stringify(this.result, null, 2)
			} catch (e) {
				return String(this.result)
			}
		},

		/** @return {boolean} Whether this step stopped the walk. */
		halted() {
			return this.result?.continue === false
		},
	},

	methods: {
		/**
		 * Copy the JSON to the clipboard.
		 *
		 * @return {Promise<void>}
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.pretty)
				showSuccess(this.t('hermiq', 'Copied.'))
			} catch (e) {
				// Clipboard is unavailable on insecure origins; the JSON is still
				// selectable in the dialog, so this is not worth an error toast.
			}
		},
	},
}
</script>

<style scoped>
.step-result {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 12px 12px;
}

.step-result__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.step-result__json {
	margin: 0;
	padding: 12px;
	max-height: 50vh;
	overflow: auto;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-large, 8px);
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
	white-space: pre;
}
</style>
