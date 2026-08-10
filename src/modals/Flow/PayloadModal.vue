<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal
		:show="show"
		size="large"
		:name="heading"
		@close="$emit('close')">
		<div class="payload" data-testid="flow-payload">
			<h2 class="payload__title">
				{{ heading }}
			</h2>

			<p v-if="!entry" class="payload__hint">
				{{ t('hermiq', 'No record for this connection in the selected run.') }}
			</p>

			<template v-else>
				<!--
					The bound is stated, always. A truncated list that does not
					say it is truncated is worse than a count: a reader
					comparing five items against a node that processed ten
					thousand concludes the flow dropped data.
				-->
				<NcNoteCard v-if="entry.output && entry.output.truncated" type="warning">
					{{ n('hermiq',
						'Showing the first item of %n.',
						'Showing the first items of %n.',
						entry.output.count) }}
				</NcNoteCard>

				<p class="payload__hint">
					{{ t('hermiq', 'This is what {node} returned — the input of whatever comes next.', { node: entry.transition }) }}
				</p>

				<pre class="payload__json">{{ pretty(entry.output) }}</pre>

				<h3 class="payload__subtitle">
					{{ t('hermiq', 'What {node} received', { node: entry.transition }) }}
				</h3>
				<pre class="payload__json">{{ pretty(entry.input) }}</pre>
			</template>

			<div class="payload__actions">
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcNoteCard } from '@nextcloud/vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { useFlowEditorStore } from '../../store/flowEditor.js'

/**
 * PayloadModal — the JSON that passed along one connection in one run.
 *
 * Shows the OUTPUT of the node the connection leaves, which is by definition
 * the input of the node it reaches, and the input that node itself received —
 * so a reader can see the transformation rather than infer it.
 *
 * @spec openspec/specs/flow-canvas/spec.md#requirement-selecting-a-run-replays-its-path-on-the-canvas
 */
export default {
	name: 'PayloadModal',

	components: {
		NcButton,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** Whether the modal is open. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	setup() {
		return { editor: useFlowEditorStore() }
	},

	computed: {
		/**
		 * The log entry for the node this connection leaves.
		 *
		 * A connection has no record of its own — a run records TRANSITIONS —
		 * so the payload on a line is the entry of the node it leaves.
		 *
		 * @return {object|null} The log entry.
		 */
		entry() {
			const edge = (this.editor.edges || []).find((candidate) => candidate.id === this.editor.payloadEdgeId)
			const detail = this.editor.replayRunId === null ? null : this.editor.runDetail[this.editor.replayRunId]
			if (!edge || !detail) {
				return null
			}

			return (detail.log || []).find((line) => edge.from.includes(line.transition)) || null
		},

		/**
		 * The modal heading.
		 *
		 * @return {string} The heading.
		 */
		heading() {
			return this.t('hermiq', 'What passed along this connection')
		},
	},

	methods: {
		t,
		n,

		/**
		 * Format a payload envelope for reading.
		 *
		 * Shows the ITEMS rather than the envelope: `count` and `truncated`
		 * are reported above in words, and printing them again inside the JSON
		 * would put bookkeeping in the middle of the data an operator came to
		 * read.
		 *
		 * @param {object} envelope The `{count, truncated, items}` envelope.
		 * @return {string} Pretty JSON.
		 */
		pretty(envelope) {
			if (!envelope) {
				return this.t('hermiq', 'Not recorded.')
			}

			return JSON.stringify(envelope.items ?? envelope, null, 2)
		},
	},
}
</script>

<style scoped>
.payload {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.payload__title,
.payload__subtitle {
	margin: 0;
}

.payload__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.payload__json {
	max-height: 40vh;
	overflow: auto;
	padding: 10px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-dark);
	font-family: monospace;
	font-size: 0.85em;
	white-space: pre;
}

.payload__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
