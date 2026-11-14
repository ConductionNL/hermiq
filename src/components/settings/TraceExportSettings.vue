<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  TraceExportSettings — send run and chat traces over OpenTelemetry (OTLP) to an
  outside tool such as Langfuse (observability-export-traces).

  The credential is named by its OpenRegister broker reference only; the secret is
  created and kept in the broker. Metadata only is the default. The section says
  where traces go, and that the audit trail stays the record.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-an-administrator-configures-trace-export-with-a-brokered-credential-req-trex-001
-->
<template>
	<div class="trace-export">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Trace export error')">
			{{ error }}
		</NcNoteCard>

		<NcCheckboxRadioSwitch
			v-model="form.enabled"
			type="switch"
			:disabled="loading || busy">
			{{ t('hermiq', 'Export traces') }}
		</NcCheckboxRadioSwitch>

		<NcTextField
			v-model="form.endpoint"
			:label="t('hermiq', 'OTLP/HTTP traces endpoint')"
			:disabled="loading || busy" />

		<NcTextField
			v-model="form.credentialId"
			:label="t('hermiq', 'Credential (OpenRegister broker reference)')"
			:helperText="t('hermiq', 'Create an organisation credential of a generic type in OpenRegister, then paste its reference here. The secret stays in the broker.')"
			:disabled="loading || busy" />

		<NcSelect
			v-model="form.authScheme"
			:options="authSchemes"
			:reduce="(option) => option.id"
			label="label"
			:inputLabel="t('hermiq', 'Credential type')"
			:disabled="loading || busy" />

		<NcTextField
			v-if="form.authScheme === 'basic'"
			v-model="form.username"
			:label="t('hermiq', 'Username (for Langfuse, the public key)')"
			:disabled="loading || busy" />

		<NcTextField
			v-model="form.organisation"
			:label="t('hermiq', 'Organisation of the credential')"
			:disabled="loading || busy" />

		<fieldset class="trace-export__group">
			<legend>{{ t('hermiq', 'What to export') }}</legend>
			<NcCheckboxRadioSwitch
				v-model="form.sources"
				value="runs"
				name="trace-export-sources"
				:disabled="loading || busy">
				{{ t('hermiq', 'Scheduled, flow and webhook runs') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="form.sources"
				value="chat"
				name="trace-export-sources"
				:disabled="loading || busy">
				{{ t('hermiq', 'Chat turns') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<fieldset class="trace-export__group">
			<legend>{{ t('hermiq', 'How much to export') }}</legend>
			<NcCheckboxRadioSwitch
				v-model="form.content"
				type="radio"
				value="metadata"
				name="trace-export-content"
				:disabled="loading || busy">
				{{ t('hermiq', 'Metadata only: names, timings, outcomes, model and token counts') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="form.content"
				type="radio"
				value="redacted-text"
				name="trace-export-content"
				:disabled="loading || busy">
				{{ t('hermiq', 'Also prompts and answers, after the output filter and redaction') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<NcCheckboxRadioSwitch
			v-model="form.allowPrivateEndpoint"
			:disabled="loading || busy">
			{{ t('hermiq', 'The collector runs on this network (allow a private address)') }}
		</NcCheckboxRadioSwitch>

		<p v-if="destinationNotice" class="trace-export__notice">
			{{ destinationNotice }}
		</p>
		<p class="trace-export__notice">
			{{ t('hermiq', 'The audit trail stays the record. Exported traces are a copy for your own tools.') }}
		</p>

		<NcButton
			variant="primary"
			:disabled="loading || busy"
			@click="save">
			{{ t('hermiq', 'Save trace export') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { getTraceExport, setTraceExport } from '../../api/traceExport.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'TraceExportSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [settingsCall],

	data() {
		return {
			form: {
				enabled: false,
				endpoint: '',
				credentialId: '',
				authScheme: 'bearer',
				username: '',
				organisation: '',
				sources: ['runs', 'chat'],
				content: 'metadata',
				allowPrivateEndpoint: false,
			},

			destinationNotice: '',
		}
	},

	computed: {
		/**
		 * The credential types, after OpenRegister's inject-only generic providers.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
		 */
		authSchemes() {
			return [
				{ id: 'basic', label: this.t('hermiq', 'Basic (username and password, such as Langfuse)') },
				{ id: 'bearer', label: this.t('hermiq', 'Bearer token') },
				{ id: 'apikey', label: this.t('hermiq', 'API key') },
			]
		},
	},

	methods: {
		/**
		 * Read the settings and the destination notice.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#scenario-an-administrator-connects-langfuse
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.apply(await getTraceExport())
			})
		},

		/**
		 * Save the settings. A refusal (422) names what is missing and is shown.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/observability-export-traces/specs/trace-export/spec.md#requirement-an-administrator-configures-trace-export-with-a-brokered-credential-req-trex-001
		 */
		async save() {
			await this.withFlag('busy', async () => {
				this.apply(await setTraceExport(this.form))
			})
		},

		/**
		 * Take the server's answer into the form.
		 *
		 * @param {object} data `{settings, destinationNotice}`.
		 * @return {void}
		 * @spec openspec/changes/observability-export-traces/tasks.md#task-1-admin-settings-and-the-connection-row
		 */
		apply(data) {
			this.form = { ...this.form, ...data.settings }
			this.destinationNotice = data.destinationNotice
		},
	},
}
</script>

<style scoped>
.trace-export {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	max-width: 600px;
}

.trace-export__group {
	border: none;
	padding: 0;
}

.trace-export__notice {
	color: var(--color-text-maxcontrast);
}
</style>
