<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  CustomToolFormModal (tools-custom-tools-without-code).

  The create/edit dialog for an organisation's custom tool, mounted on the
  Custom tools index page through `slots.form-dialog` and the CnIndexPage slot
  contract (`{ show, item, schema, close }`). It has the name, the slug, the
  description the model reads, a small input builder, "What does this tool
  change?", the target (an OpenRegister flow, or an integriq source with method,
  path and body template), and "Try it" for a saved tool, which really runs the
  flow or calls the system with sample inputs and shows the answer.

  Writes go through hermiq's /api/custom-tools endpoints, which only an
  organisation admin or instance admin passes. Every NcSelect carries an
  `inputLabel` (WCAG 2.1 AA).

  @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
-->
<template>
	<NcModal :show="show" size="large" :name="heading" @close="handleClose">
		<div class="custom-tool-form">
			<h2 class="custom-tool-form__title">
				{{ heading }}
			</h2>

			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('hermiq', 'Could not save the custom tool')">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="form.name"
				:label="t('hermiq', 'Name')"
				:helperText="t('hermiq', 'The name people see.')"
				:required="true" />
			<NcTextField
				v-model="form.slug"
				:label="t('hermiq', 'Slug')"
				:helperText="t('hermiq', 'The tool id after hermiq.custom_: 3 to 40 lowercase letters, digits or underscores, unique on the instance.')"
				:required="true" />
			<NcTextArea
				v-model="form.description"
				:label="t('hermiq', 'Description')"
				:helperText="t('hermiq', 'What the tool does, as the model reads it when it decides to call the tool.')"
				:required="true" />

			<h3 class="custom-tool-form__subhead">
				{{ t('hermiq', 'Inputs') }}
			</h3>
			<div
				v-for="(input, index) in form.inputs"
				:key="input.key"
				class="custom-tool-form__input">
				<NcTextField v-model="input.name" :label="t('hermiq', 'Input name')" />
				<NcSelect
					:modelValue="optionFor(typeOptions, input.type)"
					:inputLabel="t('hermiq', 'Type')"
					:options="typeOptions"
					:clearable="false"
					label="label"
					trackBy="value"
					@update:modelValue="input.type = $event ? $event.value : 'string'" />
				<NcTextField v-model="input.description" :label="t('hermiq', 'Description')" />
				<NcTextField
					v-if="input.type === 'string'"
					v-model="input.enumText"
					:label="t('hermiq', 'Choices')"
					:helperText="t('hermiq', 'Optional: the only values allowed, separated by commas.')" />
				<NcCheckboxRadioSwitch v-model="input.required">
					{{ t('hermiq', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="tertiary" @click="form.inputs.splice(index, 1)">
					{{ t('hermiq', 'Remove input') }}
				</NcButton>
			</div>
			<NcButton @click="addInput">
				{{ t('hermiq', 'Add input') }}
			</NcButton>

			<h3 class="custom-tool-form__subhead">
				{{ t('hermiq', 'What does this tool change?') }}
			</h3>
			<NcSelect
				:modelValue="optionFor(changeOptions, form.changesData)"
				:inputLabel="t('hermiq', 'What does this tool change?')"
				:options="changeOptions"
				:clearable="false"
				label="label"
				trackBy="value"
				@update:modelValue="form.changesData = $event ? $event.value : 'yes'" />

			<h3 class="custom-tool-form__subhead">
				{{ t('hermiq', 'Target') }}
			</h3>
			<NcSelect
				:modelValue="optionFor(targetOptions, form.targetType)"
				:inputLabel="t('hermiq', 'Target type')"
				:options="targetOptions"
				:clearable="false"
				label="label"
				trackBy="value"
				@update:modelValue="form.targetType = $event ? $event.value : 'flow'" />
			<NcSelect
				v-if="form.targetType === 'flow'"
				:modelValue="optionFor(flowOptions, form.flowId)"
				:inputLabel="t('hermiq', 'Flow')"
				:options="flowOptions"
				:loading="flowsLoading"
				label="label"
				trackBy="value"
				@update:modelValue="form.flowId = $event ? $event.value : ''" />
			<template v-else>
				<NcTextField
					v-model="form.source"
					:label="t('hermiq', 'Integriq source')"
					:helperText="t('hermiq', 'The integriq source the tool calls.')" />
				<NcSelect
					:modelValue="optionFor(methodOptions, form.method)"
					:inputLabel="t('hermiq', 'Method')"
					:options="methodOptions"
					:clearable="false"
					label="label"
					trackBy="value"
					@update:modelValue="form.method = $event ? $event.value : 'GET'" />
				<NcTextField
					v-model="form.path"
					:label="t('hermiq', 'Path')"
					:helperText="t('hermiq', 'The path under the source, starting with /.')" />
				<NcTextArea
					v-model="form.bodyTemplate"
					:label="t('hermiq', 'Body template')"
					:helperText="t('hermiq', 'The JSON body; {{input.<name>}} inserts an argument. Empty sends the arguments as the body.')" />
				<NcButton class="custom-tool-form__preset" @click="applyN8nPreset">
					{{ t('hermiq', 'Fill in for an n8n workflow') }}
				</NcButton>
				<NcCheckboxRadioSwitch v-model="form.waitForCallback">
					{{ t('hermiq', 'Wait for the workflow to post its result back') }}
				</NcCheckboxRadioSwitch>
				<template v-if="form.waitForCallback">
					<NcTextField
						v-model="form.deadlineHours"
						type="number"
						:label="t('hermiq', 'Deadline in hours')"
						:helperText="t('hermiq', 'Empty is 24 hours; at most 168.')" />
					<NcNoteCard type="info" class="custom-tool-form__callback-help">
						<p>{{ t('hermiq', 'The workflow gets hermiqResultUrl and hermiqResultToken in its body. The result comes back in the conversation the tool ran in.') }}</p>
						<p>{{ n8nSteps }}</p>
					</NcNoteCard>
				</template>
			</template>

			<NcCheckboxRadioSwitch v-model="form.enabled">
				{{ t('hermiq', 'Offer this tool to agents') }}
			</NcCheckboxRadioSwitch>

			<section v-if="item && item.id" class="custom-tool-form__try">
				<h3 class="custom-tool-form__subhead">
					{{ t('hermiq', 'Try it') }}
				</h3>
				<NcNoteCard type="warning">
					{{ t('hermiq', 'This really runs the flow or calls the system.') }}
				</NcNoteCard>
				<NcTextField
					v-for="input in namedInputs"
					:key="input.key"
					:modelValue="samples[input.name] || ''"
					:label="input.name"
					@update:modelValue="samples = { ...samples, [input.name]: $event }" />
				<NcButton :disabled="trying" @click="tryIt">
					<template v-if="trying" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('hermiq', 'Try it') }}
				</NcButton>
				<pre v-if="answer" class="custom-tool-form__answer">{{ answer }}</pre>
			</section>

			<div class="custom-tool-form__actions">
				<NcButton :disabled="saving" @click="handleClose">
					{{ t('hermiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || !canSave"
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
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	createCustomTool,
	inputSchemaFrom,
	inputsFrom,
	integriqTargetFrom,
	sampleArguments,
	tryCustomTool,
	updateCustomTool,
} from '../api/customTools.js'
import { listFlows } from '../api/flowRuns.js'
import { errorMessage } from '../utils/errorMessage.js'
import { formDialogSlot } from './formDialogSlot.js'

let keySeed = 0

/**
 * The target fields of the editable form for a stored tool's target.
 *
 * @param {object} target The stored target, or an empty object.
 * @return {object}
 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
 */
function targetFormFrom(target) {
	return {
		targetType: target.type === 'integriq' ? 'integriq' : 'flow',
		flowId: target.flowId || '',
		source: target.source || '',
		method: target.method || 'GET',
		path: target.path || '',
		bodyTemplate: target.bodyTemplate || '',
		waitForCallback: target.waitForCallback === true,
		deadlineHours: target.deadlineHours ? String(target.deadlineHours) : '',
	}
}

export default {
	name: 'CustomToolFormModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	mixins: [formDialogSlot],

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: this.formFrom(this.item),
			flows: [],
			flowsLoading: false,
			samples: {},
			answer: '',
			trying: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * What to add to the n8n workflow so its result comes back.
		 *
		 * @return {string}
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
		 */
		n8nSteps() {
			return this.t(
				'hermiq',
				'In n8n, add "Respond to Webhook" right after the trigger, and at the end an "HTTP Request" node that posts the result to {url} with the header X-Hermiq-Result-Token set to {token}.',
				{ url: '{{ $json.hermiqResultUrl }}', token: '{{ $json.hermiqResultToken }}' },
			)
		},

		/**
		 * The dialog title.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		heading() {
			return this.item?.id
				? this.t('hermiq', 'Edit custom tool')
				: this.t('hermiq', 'Add a custom tool')
		},

		/**
		 * The simple input types.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		typeOptions() {
			return [
				{ value: 'string', label: this.t('hermiq', 'Text') },
				{ value: 'number', label: this.t('hermiq', 'Number') },
				{ value: 'integer', label: this.t('hermiq', 'Whole number') },
				{ value: 'boolean', label: this.t('hermiq', 'Yes or no') },
			]
		},

		/**
		 * What a tool may change.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		changeOptions() {
			return [
				{ value: 'no', label: this.t('hermiq', 'Changes nothing') },
				{ value: 'yes', label: this.t('hermiq', 'Changes data') },
				{ value: 'irreversible', label: this.t('hermiq', 'Changes data irreversibly') },
			]
		},

		/**
		 * The two target kinds.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		targetOptions() {
			return [
				{ value: 'flow', label: this.t('hermiq', 'An OpenRegister flow') },
				{ value: 'integriq', label: this.t('hermiq', 'A system connected through integriq') },
			]
		},

		/**
		 * The HTTP methods of an integriq target.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		methodOptions() {
			return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((method) => ({ value: method, label: method }))
		},

		/**
		 * The flows the admin may read, plus the stored one when it is not among them.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		flowOptions() {
			const options = this.flows.map((flow) => ({
				value: String(flow.uuid || flow.id),
				label: flow.name || flow.title || String(flow.uuid || flow.id),
			}))
			if (this.form.flowId && !options.some((option) => option.value === this.form.flowId)) {
				options.push({ value: this.form.flowId, label: this.form.flowId })
			}
			return options
		},

		/**
		 * The inputs that have a name, for the sample fields of "Try it".
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		namedInputs() {
			return this.form.inputs.filter((input) => input.name.trim() !== '')
		},

		/**
		 * Whether the required fields are filled in.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		canSave() {
			return this.form.name.trim() !== '' && this.form.slug.trim() !== '' && this.form.description.trim() !== ''
		},
	},

	watch: {
		/**
		 * Reset the form and load the flows each time the dialog opens.
		 *
		 * @param {boolean} open Whether the dialog is open.
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		show(open) {
			if (open) {
				this.form = this.formFrom(this.item)
				this.samples = {}
				this.answer = ''
				this.error = ''
				this.loadFlows()
			}
		},

		/**
		 * Reload the form when the edited tool changes.
		 *
		 * @param {object|null} value The edited tool.
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		item(value) {
			this.form = this.formFrom(value)
		},
	},

	/**
	 * Load the flows a tool can target when the modal is mounted open.
	 *
	 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
	 */
	mounted() {
		if (this.show) {
			this.loadFlows()
		}
	},

	methods: {
		/**
		 * Fill the target in for an n8n workflow: POST to its webhook path, waiting for its result.
		 *
		 * @return {void}
		 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-a-webhook-workflow-returns-its-result-to-a-one-time-address-req-handoff-004
		 */
		applyN8nPreset() {
			this.form.method = 'POST'
			this.form.path = '/webhook/' + (this.form.slug.trim() || 'workflow')
			this.form.waitForCallback = true
		},

		/**
		 * A fresh key for a list row.
		 *
		 * @return {number}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		nextKey() {
			keySeed += 1
			return keySeed
		},

		/**
		 * The option of a list with this value, or null.
		 *
		 * @param {Array<{value: string}>} options The options.
		 * @param {string} value The value.
		 * @return {object|null}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		optionFor(options, value) {
			return options.find((option) => option.value === value) || null
		},

		/**
		 * The editable form for a stored tool, or an empty one.
		 *
		 * @param {object|null} source The stored tool.
		 * @return {object}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		formFrom(source) {
			return {
				name: source?.name || '',
				slug: source?.slug || '',
				description: source?.description || '',
				inputs: inputsFrom(source?.inputSchema).map((input) => ({ ...input, key: this.nextKey() })),
				changesData: source?.changesData || 'no',
				...targetFormFrom(source?.target || {}),
				enabled: source?.enabled !== false,
			}
		},

		/**
		 * Add an empty input row.
		 *
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		addInput() {
			this.form.inputs.push({ key: this.nextKey(), name: '', type: 'string', required: false, description: '', enumText: '' })
		},

		/**
		 * Load the flows for the target picker; a failure leaves the list empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		async loadFlows() {
			this.flowsLoading = true
			try {
				this.flows = await listFlows()
			} catch {
				this.flows = []
			} finally {
				this.flowsLoading = false
			}
		},

		/**
		 * The payload the API stores.
		 *
		 * @return {object}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		buildPayload() {
			const target = this.form.targetType === 'flow'
				? { type: 'flow', flowId: this.form.flowId }
				: integriqTargetFrom(this.form)
			return {
				name: this.form.name.trim(),
				slug: this.form.slug.trim(),
				description: this.form.description.trim(),
				inputSchema: inputSchemaFrom(this.form.inputs),
				changesData: this.form.changesData,
				target,
				enabled: this.form.enabled,
			}
		},

		/**
		 * Save through the custom tools API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const payload = this.buildPayload()
				const saved = this.item?.id
					? await updateCustomTool(this.item.id, payload)
					: await createCustomTool(payload)
				this.announceSaved(saved)
				this.handleClose()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'The server did not accept the custom tool.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Run the saved tool once with the sample inputs and show the answer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-custom-tools-without-code/specs/custom-tools/spec.md#requirement-an-organisation-admin-can-define-a-custom-tool-without-code-req-custool-001
		 */
		async tryIt() {
			this.trying = true
			this.answer = ''
			try {
				const result = await tryCustomTool(this.item.id, sampleArguments(this.namedInputs, this.samples))
				this.answer = JSON.stringify(result, null, 2)
			} catch (e) {
				this.answer = errorMessage(e, this.t('hermiq', 'The tool could not be run.'))
			} finally {
				this.trying = false
			}
		},
	},
}
</script>

<style scoped>
.custom-tool-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.custom-tool-form__title {
	margin: 0;
}

.custom-tool-form__subhead {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
}

.custom-tool-form__input {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.custom-tool-form__preset {
	align-self: flex-start;
}

.custom-tool-form__try {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.custom-tool-form__answer {
	max-height: 300px;
	overflow: auto;
	padding: calc(var(--default-grid-baseline) * 2);
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	white-space: pre-wrap;
}

.custom-tool-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
