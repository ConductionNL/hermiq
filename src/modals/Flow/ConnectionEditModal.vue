<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcModal :show="show" size="normal" :name="heading" @close="$emit('close')">
		<div class="connection-edit" data-testid="flow-connection-edit">
			<h2 class="connection-edit__title">
				{{ heading }}
			</h2>

			<template v-if="edge">
				<p class="connection-edit__hint">
					{{
						t(
							'hermiq',
							'A connection carries sequence and the words a reader needs. What a step DOES is on the node.',
						)
					}}
				</p>

				<NcTextField
					:modelValue="edge.title || ''"
					:label="t('hermiq', 'Title')"
					:placeholder="
						t('hermiq', 'The words on the line, e.g. “approved”')
					"
					@update:modelValue="editor.setEdgeField('title', $event)" />

				<NcTextArea
					:modelValue="edge.description || ''"
					:label="t('hermiq', 'Description')"
					:placeholder="
						t(
							'hermiq',
							'What this connection means — when the flow takes it.',
						)
					"
					rows="3"
					@update:modelValue="
						editor.setEdgeField('description', $event)
					" />

				<NcTextArea
					:modelValue="edge.notes || ''"
					:label="t('hermiq', 'Notes')"
					:placeholder="
						t(
							'hermiq',
							'Anything the next person should know about this connection.',
						)
					"
					rows="4"
					@update:modelValue="editor.setEdgeField('notes', $event)" />

				<!--
					How the line is DRAWN. None of these reach the engine: it
					reads `from`/`to` off an edge and nothing else, so styling a
					connection cannot change what the flow does.

					They earn their place on a busy graph — a colour separates
					an error path from a happy one, a dashed line says "not the
					normal route", and dropping the arrowhead marks a line whose
					direction is not the point.
				-->
				<h3 class="connection-edit__section">
					{{ t('hermiq', 'Appearance') }}
				</h3>

				<div class="connection-edit__row">
					<NcSelect
						:modelValue="option(LINE_STYLES, edge.lineStyle || 'solid')"
						:inputLabel="t('hermiq', 'Line')"
						:options="LINE_STYLES"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							editor.setEdgeField(
								'lineStyle',
								$event ? $event.value : 'solid',
							)
						" />

					<NcSelect
						:modelValue="option(WIDTHS, String(edge.width || 2))"
						:inputLabel="t('hermiq', 'Thickness')"
						:options="WIDTHS"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							editor.setEdgeField(
								'width',
								$event ? Number($event.value) : 2,
							)
						" />
				</div>

				<div class="connection-edit__row">
					<NcSelect
						:modelValue="option(MARKERS, edge.startMarker || 'none')"
						:inputLabel="t('hermiq', 'Start symbol')"
						:options="MARKERS"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							editor.setEdgeField(
								'startMarker',
								$event ? $event.value : 'none',
							)
						" />

					<NcSelect
						:modelValue="option(MARKERS, edge.endMarker || 'arrow')"
						:inputLabel="t('hermiq', 'End symbol')"
						:options="MARKERS"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							editor.setEdgeField(
								'endMarker',
								$event ? $event.value : 'arrow',
							)
						" />
				</div>

				<!--
					A native colour input: NC ships no colour picker, and a text
					field for a hex value is a validation problem for something
					every browser already does well. Cleared back to the theme
					default by the button beside it — an empty string, not a
					colour that merely looks like the default, so the line keeps
					following the theme.
				-->
				<div class="connection-edit__row connection-edit__row--colour">
					<label
						class="connection-edit__colour-label"
						for="connection-colour">
						{{ t('hermiq', 'Colour') }}
					</label>
					<input
						id="connection-colour"
						type="color"
						class="connection-edit__colour"
						:value="edge.colour || '#8b8b9e'"
						@input="
							editor.setEdgeField('colour', $event.target.value)
						" />
					<NcButton
						type="tertiary"
						@click="editor.setEdgeField('colour', '')">
						{{ t('hermiq', 'Use theme colour') }}
					</NcButton>
				</div>
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
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcModal, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
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
		NcSelect,
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

	/**
	 * @spec openspec/specs/flow-canvas/spec.md
	 */
	setup() {
		return { editor: useFlowEditorStore() }
	},

	data() {
		return {
			// Vocabularies, not free text: a line is one of a few shapes, and a
			// typed value would be a rendering bug nobody could see the cause of.
			LINE_STYLES: [
				{ label: t('hermiq', 'Solid'), value: 'solid' },
				{ label: t('hermiq', 'Dashed'), value: 'dashed' },
				{ label: t('hermiq', 'Dotted'), value: 'dotted' },
			],

			WIDTHS: [
				{ label: t('hermiq', 'Thin'), value: '1' },
				{ label: t('hermiq', 'Normal'), value: '2' },
				{ label: t('hermiq', 'Thick'), value: '4' },
			],

			MARKERS: [
				{ label: t('hermiq', 'None'), value: 'none' },
				{ label: t('hermiq', 'Arrow'), value: 'arrow' },
				{ label: t('hermiq', 'Dot'), value: 'dot' },
			],
		}
	},

	computed: {
		/**
		 * The connection being edited.
		 *
		 * @return {object|null} The selected edge.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		edge() {
			return this.editor.selectedEdge
		},

		/**
		 * The modal heading: where the line goes, which is what identifies it —
		 * a connection has no name of its own.
		 *
		 * @return {string} The heading.
		 * @spec openspec/specs/flow-canvas/spec.md
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
		 * The option object an NcSelect needs for a stored scalar.
		 *
		 * @param {Array<object>} options The vocabulary.
		 * @param {string}        value   The stored value.
		 * @return {object|null} The matching option.
		 * @spec openspec/specs/flow-canvas/spec.md
		 */
		option(options, value) {
			return (
				options.find((candidate) => candidate.value === String(value))
				|| null
			)
		},

		/**
		 * Remove the connection and close — a modal left open on a connection
		 * that no longer exists would render an empty form over the canvas.
		 *
		 * @return {void}
		 * @spec openspec/specs/flow-canvas/spec.md
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

.connection-edit__section {
	margin: 8px 0 0;
	font-size: 1em;
}

.connection-edit__row {
	display: flex;
	gap: 12px;
}

.connection-edit__row > * {
	flex: 1;
}

.connection-edit__row--colour {
	align-items: center;
}

.connection-edit__colour-label {
	flex: 0 0 auto;
}

/* A native colour swatch, sized to sit on the same line as the label and the
   reset button rather than stretching across the dialog. */
.connection-edit__colour {
	flex: 0 0 auto;
	width: 48px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: none;
}
</style>
