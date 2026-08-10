<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal
		:show="show"
		size="normal"
		:name="heading"
		@close="$emit('close')">
		<div class="connection-edit" data-testid="flow-connection-edit">
			<h2 class="connection-edit__title">
				{{ heading }}
			</h2>

			<template v-if="edge">
				<p class="connection-edit__hint">
					{{ t('hermiq', 'A connection carries sequence and the words a reader needs. What a step DOES is on the node.') }}
				</p>

				<NcTextField
					:model-value="edge.title || ''"
					:label="t('hermiq', 'Title')"
					:placeholder="t('hermiq', 'The words on the line, e.g. “approved”')"
					@update:model-value="editor.setEdgeField('title', $event)" />

				<NcTextArea
					:model-value="edge.description || ''"
					:label="t('hermiq', 'Description')"
					:placeholder="t('hermiq', 'What this connection means — when the flow takes it.')"
					rows="3"
					@update:model-value="editor.setEdgeField('description', $event)" />

				<NcTextArea
					:model-value="edge.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="t('hermiq', 'Anything the next person should know about this connection.')"
					rows="4"
					@update:model-value="editor.setEdgeField('notes', $event)" />
			</template>

			<div class="connection-edit__actions">
				<NcButton type="error" @click="onRemove">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('hermiq', 'Remove connection') }}
				</NcButton>
				<NcButton type="primary" @click="$emit('close')">
					{{ t('hermiq', 'Done') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextArea, NcTextField } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { translate as t } from '@nextcloud/l10n'
import { useFlowEditorStore } from '../../store/flowEditor.js'

/**
 * ConnectionEditModal — a line's title, description and notes.
 *
 * This was a permanent sidebar TAB. It is a modal now because the fields are
 * three text areas an author opens rarely, and a tab for them took a quarter of
 * the strip — beside Nodes, Runs and Flow, which are opened constantly — while
 * the thing being edited was already selected on the canvas.
 *
 * Writes go straight to the draft, so there is no Save: the flow's own Save
 * persists, and a second one here would imply the connection was written
 * separately.
 *
 * @spec openspec/specs/flow-canvas/spec.md
 */
export default {
	name: 'ConnectionEditModal',

	components: {
		Delete,
		NcButton,
		NcModal,
		NcTextArea,
		NcTextField,
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
		 * The connection being edited.
		 *
		 * @return {object|null} The selected edge.
		 */
		edge() {
			return this.editor.selectedEdge
		},

		/**
		 * The modal heading: where the line goes, which is what identifies it —
		 * a connection has no name of its own.
		 *
		 * @return {string} The heading.
		 */
		heading() {
			if (!this.edge) {
				return this.t('hermiq', 'Connection')
			}

			return this.t('hermiq', '{from} → {to}', {
				from: this.edge.from.join(', '),
				to: this.edge.to.join(', '),
			})
		},
	},

	methods: {
		t,

		/**
		 * Remove the connection and close — a modal left open on a connection
		 * that no longer exists would render an empty form over the canvas.
		 *
		 * @return {void}
		 */
		onRemove() {
			const id = this.edge?.id
			if (id) {
				this.editor.removeEdge(id)
			}
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.connection-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.connection-edit__title {
	margin: 0;
}

.connection-edit__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.connection-edit__actions {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-top: 8px;
}
</style>
