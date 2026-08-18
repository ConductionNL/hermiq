<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  EvalDatasetFormModal — create or edit an EvalDataset (agent-evals).

  Own file per ADR-004 modal-isolation. Persists a `hermiq`/`evaldataset` OpenRegister
  object through the createObjectStore in src/store/store.js (no bespoke store, no
  bespoke CRUD backend). A dataset is a name + an ordered list of cases; each case is a
  prompt plus one expectation (contains / notContains / jsonPathEquals / rubric). Every
  NcSelect carries an `inputLabel` (nc-input-labels accessibility gate).

  @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="$emit('close')">
		<div class="eval-form">
			<h2 class="eval-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save dataset')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:placeholder="t('hermiq', 'Support-tone regression set')"
				required />

			<h3 class="eval-form__subhead">
				{{ t('hermiq', 'Cases') }}
			</h3>

			<div
				v-for="(evalCase, index) in form.cases"
				:key="index"
				class="eval-form__case">
				<div class="eval-form__case-head">
					<strong>{{ t('hermiq', 'Case {n}', { n: index + 1 }) }}</strong>
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'Remove case')"
						@click="removeCase(index)">
						{{ t('hermiq', 'Remove') }}
					</NcButton>
				</div>

				<NcTextArea
					v-model="evalCase.prompt"
					:label="t('hermiq', 'Prompt')"
					:placeholder="
						t('hermiq', 'The input given to the agent for this case')
					"
					resize="vertical" />

				<div class="eval-form__field">
					<NcSelect
						:modelValue="expectationOptionFor(evalCase)"
						:inputLabel="t('hermiq', 'Expectation')"
						:options="expectationOptions"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							(opt) => setExpectation(evalCase, opt)
						" />
				</div>

				<NcTextField
					v-if="
						evalCase.expectationType === 'contains'
						|| evalCase.expectationType === 'notContains'
					"
					v-model="evalCase.expectedSubstring"
					:label="t('hermiq', 'Expected substring')" />

				<template v-if="evalCase.expectationType === 'jsonPathEquals'">
					<NcTextField
						v-model="evalCase.jsonPath"
						:label="t('hermiq', 'JSON path')"
						placeholder="result.status" />
					<NcTextField
						v-model="evalCase.expectedValue"
						:label="t('hermiq', 'Expected value')" />
				</template>

				<NcTextArea
					v-if="evalCase.expectationType === 'rubric'"
					v-model="evalCase.rubric"
					:label="t('hermiq', 'Rubric (graded by an LLM judge)')"
					:placeholder="
						t(
							'hermiq',
							'e.g. The reply is polite and answers the question.',
						)
					"
					resize="vertical" />
			</div>

			<NcButton type="secondary" @click="addCase">
				{{ t('hermiq', 'Add case') }}
			</NcButton>

			<div class="eval-form__actions">
				<NcButton :disabled="saving" @click="$emit('close')">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !form.name || form.cases.length === 0"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useEvalDatasetStore } from '../store/store.js'

export default {
	name: 'EvalDatasetFormModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The dataset being edited, or null when creating a new one. */
		dataset: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.blankForm(),
			saving: false,
			error: '',
			expectationOptions: [
				{
					label: this.t('hermiq', 'Output contains a substring'),
					value: 'contains',
				},
				{
					label: this.t('hermiq', 'Output does NOT contain a substring'),
					value: 'notContains',
				},
				{
					label: this.t('hermiq', 'JSON path equals a value'),
					value: 'jsonPathEquals',
				},
				{
					label: this.t('hermiq', 'LLM judge scores a rubric'),
					value: 'rubric',
				},
			],
		}
	},

	computed: {
		/**
		 * Modal heading — differs for create vs edit.
		 *
		 * @return {string} The localised heading.
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		heading() {
			return this.dataset
				? this.t('hermiq', 'Edit dataset')
				: this.t('hermiq', 'Create dataset')
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.resetForm()
			}
		},
	},

	/**
	 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
	 */
	created() {
		this.store = useEvalDatasetStore()
		this.store.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
	},

	methods: {
		/**
		 * An empty dataset form with one starter case.
		 *
		 * @return {object} The blank form model.
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		blankForm() {
			return { name: '', cases: [this.blankCase()] }
		},

		/**
		 * An empty case (defaults to a substring assertion).
		 *
		 * @return {object} The blank case model.
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		blankCase() {
			return {
				prompt: '',
				expectationType: 'contains',
				expectedSubstring: '',
				jsonPath: '',
				expectedValue: '',
				rubric: '',
			}
		},

		/**
		 * Seed the form from the `dataset` prop (edit) or blank (create).
		 *
		 * @return {void}
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		resetForm() {
			this.error = ''
			if (!this.dataset) {
				this.form = this.blankForm()
				return
			}
			const cases = Array.isArray(this.dataset.cases) ? this.dataset.cases : []
			this.form = {
				name: this.dataset.name || '',
				cases:
					cases.length > 0
						? cases.map((c) => ({ ...this.blankCase(), ...c }))
						: [this.blankCase()],
			}
		},

		/**
		 * The NcSelect option object for a case's current expectationType.
		 *
		 * @param {object} evalCase The case.
		 * @return {object|null} The matching option.
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		expectationOptionFor(evalCase) {
			return (
				this.expectationOptions.find(
					(o) => o.value === evalCase.expectationType,
				) || null
			)
		},

		/**
		 * Apply an expectation-type selection to a case.
		 *
		 * @param {object} evalCase The case to mutate.
		 * @param {object} option The chosen option.
		 * @return {void}
		 */
		setExpectation(evalCase, option) {
			evalCase.expectationType = option ? option.value : 'contains'
		},

		/**
		 * Append a blank case.
		 *
		 * @return {void}
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		addCase() {
			this.form.cases.push(this.blankCase())
		},

		/**
		 * Remove a case by index (keeps at least one).
		 *
		 * @param {number} index The case index.
		 * @return {void}
		 */
		removeCase(index) {
			this.form.cases.splice(index, 1)
			if (this.form.cases.length === 0) {
				this.form.cases.push(this.blankCase())
			}
		},

		/**
		 * Build the payload, dropping the fields irrelevant to each case's expectation type.
		 *
		 * @return {object} The evaldataset payload.
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		buildPayload() {
			const base = this.dataset ? { ...this.dataset } : {}
			delete base['@self']
			const payload = {
				...base,
				name: this.form.name,
				cases: this.form.cases.map((c) => {
					const trimmed = {
						prompt: c.prompt,
						expectationType: c.expectationType,
					}
					if (
						c.expectationType === 'contains'
						|| c.expectationType === 'notContains'
					) {
						trimmed.expectedSubstring = c.expectedSubstring
					} else if (c.expectationType === 'jsonPathEquals') {
						trimmed.jsonPath = c.jsonPath
						trimmed.expectedValue = c.expectedValue
					} else if (c.expectationType === 'rubric') {
						trimmed.rubric = c.rubric
					}
					return trimmed
				}),
			}
			if (this.dataset && this.dataset.id) {
				payload.id = this.dataset.id
			}
			return payload
		},

		/**
		 * Persist the dataset via the createObjectStore and notify the parent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/agent-evals/tasks.md#task-9-evaldatasetformmodal--evaldatasetsvue
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const saved = await this.store.saveObject(
					'evaldataset',
					this.buildPayload(),
				)
				if (saved === null) {
					this.error =
						this.store.errors?.evaldataset?.message
						|| this.t('hermiq', 'Could not save dataset')
					return
				}
				this.$emit('saved', saved)
				this.$emit('close')
			} catch (e) {
				this.error = e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.eval-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.eval-form__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.eval-form__subhead {
	margin: 8px 0 0;
	font-size: 15px;
}

.eval-form__case {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.eval-form__case-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.eval-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
