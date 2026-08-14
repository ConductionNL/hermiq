<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('hermiq', 'Saved, but this flow cannot finish')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="dead-end">
			<NcNoteCard type="warning">
				{{
					n(
						'hermiq',
						'One node has nowhere to send its work.',
						'%n nodes have nowhere to send their work.',
						nodeIds.length,
					)
				}}
			</NcNoteCard>

			<!-- The save already happened. Saying so first matters: an author
			     who reads "cannot finish" and assumes their work was rejected
			     will redo it. -->
			<p class="dead-end__intro">
				{{
					t(
						'hermiq',
						'Your changes are saved. This is about what will happen when the flow RUNS.',
					)
				}}
			</p>

			<ul class="dead-end__list">
				<li v-for="id in nodeIds" :key="id" class="dead-end__item">
					{{ id }}
				</li>
			</ul>

			<p class="dead-end__hint">
				{{
					t(
						'hermiq',
						'A run that reaches one of these stops there and is still recorded as completed — so it would look like it worked. Connect the node onward, give it a step that ends the flow, or mark it as an exit if stopping there is deliberate.',
					)
				}}
			</p>
		</div>

		<template #actions>
			<NcButton variant="primary" @click="$emit('close')">
				{{ t('hermiq', 'Got it') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'

/**
 * DeadEndWarningDialog — what the save said about the flow's connectivity.
 *
 * Shown AFTER a successful save, never instead of one. A half-wired graph is
 * the normal state of one being authored, so the editor stores it and then
 * tells the author what it will do; refusing the save would force the flow to
 * be built in an order that is never disconnected, which no editor can require.
 *
 * The node ids come from the save response's `warnings` (reason
 * `node-dead-end`), which is OpenRegister's own verdict — the same one that
 * will refuse the run. Recomputing it here would let the dialog and the engine
 * disagree.
 */
export default {
	name: 'DeadEndWarningDialog',

	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	props: {
		/**
		 * The ids of the nodes that cannot pass their work on.
		 *
		 * @type {Array<string>}
		 */
		nodeIds: {
			type: Array,
			required: true,
		},
	},

	emits: ['close'],
}
</script>

<style scoped>
.dead-end {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dead-end__intro {
	margin: 0;
}

.dead-end__list {
	margin: 0;
	padding-left: 20px;
}

.dead-end__item {
	font-family: var(--font-face-monospace, monospace);
	font-weight: 600;
}

.dead-end__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
