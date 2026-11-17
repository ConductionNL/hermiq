<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  CodeSandboxSettings — where agent and flow code runs (tools-code-sandbox).

  The admin chooses between the sandbox app on this server and a hosted sandbox
  reached through an integriq source, and "Run a test" runs `print(1 + 1)` there.
  The section always says which backend is live, because "where does code run"
  is the data-protection question this setting answers for the whole instance.

  All strings via t(); server data comes from the admin-gated API, never a DOM read.

  @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
-->
<template>
	<div class="code-sandbox">
		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Code sandbox error')">
			{{ error }}
		</NcNoteCard>

		<fieldset class="code-sandbox__choice" :disabled="loading || busy">
			<legend>{{ t('hermiq', 'Where code runs') }}</legend>
			<NcCheckboxRadioSwitch
				v-model="backend"
				type="radio"
				value="exapp"
				name="code-sandbox-backend">
				{{ t('hermiq', 'Own sandbox on this server') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="backend"
				type="radio"
				value="hosted"
				name="code-sandbox-backend">
				{{ t('hermiq', 'Hosted sandbox') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<div v-if="backend === 'hosted'" class="code-sandbox__row">
			<NcTextField
				v-model="hostedSource"
				:label="t('hermiq', 'Integriq source of the hosted sandbox')"
				:disabled="loading || busy" />
		</div>

		<div class="code-sandbox__row">
			<NcButton
				variant="primary"
				:disabled="loading || busy || !changed"
				@click="save">
				{{ t('hermiq', 'Save') }}
			</NcButton>
			<NcButton
				:disabled="loading || busy || changed"
				@click="runTest">
				{{ t('hermiq', 'Run a test') }}
			</NcButton>
		</div>

		<p class="code-sandbox__live">
			<span v-if="stored.backend === 'hosted'">
				{{ t('hermiq', 'Code runs at: {source} (outside this server)', { source: stored.hostedSource }) }}
			</span>
			<span v-else>
				{{ t('hermiq', 'Code runs at: this server') }}
			</span>
		</p>

		<p v-if="!available" class="code-sandbox__note">
			{{ t('hermiq', 'No code sandbox can take runs right now. Install the code sandbox app, or choose a hosted sandbox.') }}
		</p>

		<p v-if="test" class="code-sandbox__test" :class="{ 'code-sandbox__test--failed': !test.passed }">
			<span v-if="test.passed">{{ t('hermiq', 'Test passed: {output}', { output: test.output }) }}</span>
			<span v-else>{{ t('hermiq', 'Test failed: {output}', { output: test.output }) }}</span>
		</p>

		<p class="code-sandbox__note">
			{{ t('hermiq', 'Code runs without network access, without access to anyone\'s files and within limits on time and memory. An agent runs code only with the tool granted and the agent code execution AI feature enabled.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { getCodeSandbox, setCodeSandbox, testCodeSandbox } from '../../api/codeSandbox.js'
import { settingsCall } from './settingsCall.js'

export default {
	name: 'CodeSandboxSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	mixins: [settingsCall],

	data() {
		return {
			backend: 'exapp',
			hostedSource: '',
			stored: { backend: 'exapp', hostedSource: null },
			available: true,
			test: null,
		}
	},

	computed: {
		/**
		 * Whether the form differs from the stored choice.
		 *
		 * @return {boolean} True when there is something to save.
		 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
		 */
		changed() {
			if (this.backend !== this.stored.backend) {
				return true
			}
			return this.backend === 'hosted' && this.hostedSource.trim() !== (this.stored.hostedSource ?? '')
		},
	},

	methods: {
		/**
		 * Take a choice the server answered.
		 *
		 * @param {object} data { backend, hostedSource, available }.
		 * @return {void}
		 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
		 */
		apply(data) {
			this.stored = { backend: data.backend, hostedSource: data.hostedSource ?? null }
			this.backend = data.backend
			this.hostedSource = data.hostedSource ?? ''
			if (typeof data.available === 'boolean') {
				this.available = data.available
			}
		},

		/**
		 * Read where code runs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
		 */
		async load() {
			await this.withFlag('loading', async () => {
				this.apply(await getCodeSandbox())
			})
		},

		/**
		 * Save the choice; a refusal is shown with the server's reason.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-the-admin-chooses-where-code-runs-req-sandbox-004
		 */
		async save() {
			await this.withFlag('busy', async () => {
				const source = this.backend === 'hosted' ? this.hostedSource.trim() : null
				this.apply(await setCodeSandbox(this.backend, source))
				this.test = null
			})
		},

		/**
		 * Run `print(1 + 1)` in the live sandbox and show the result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-an-admin-switches-to-a-hosted-sandbox
		 */
		async runTest() {
			this.test = null
			await this.withFlag('busy', async () => {
				const data = await testCodeSandbox()
				this.test = { passed: data.passed === true, output: String(data.output ?? '') }
			})
		},
	},
}
</script>

<style scoped>
.code-sandbox__choice {
	border: none;
	padding: 0;
	margin: 0 0 8px;
}

.code-sandbox__choice legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.code-sandbox__row {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	max-width: 480px;
	margin-bottom: 8px;
}

.code-sandbox__live,
.code-sandbox__test {
	margin: 8px 0 0;
}

.code-sandbox__test--failed {
	color: var(--color-error-text);
}

.code-sandbox__note {
	margin: 4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
