<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SetupWizard — Hermiq's first-run configuration walkthrough.

  Shown once (gated by the per-user `wizardCompleted` preference) and re-openable from
  Settings. Four steps:
    1. LLM connection — probe an Ollama endpoint server-side (SetupController::llmTest) and
       pick a model; connectivity is what an agent cannot run without.
    2. Talk delivery — a default Talk room token scheduled runs deliver to (changeable
       per-schedule later).
    3. Organisation — the OpenRegister tenancy scope (owned organisations only).
    4. Get started — optionally seed a working no-tools demo agent so the first run succeeds.

  On finish it persists the chosen defaults + the completed flag via PreferencesController and
  seeds the demo agent through the existing OpenRegister agents endpoint (createAgent). No new
  write path.
-->
<template>
	<NcModal size="large" :can-close="true" @close="skip">
		<div class="wizard">
			<div class="wizard__head">
				<h2 class="wizard__title">
					{{ t('hermiq', 'Welcome to Hermiq') }}
				</h2>
				<p class="wizard__step-label">
					{{ t('hermiq', 'Step {n} of {total}', { n: step + 1, total: steps.length }) }} — {{ steps[step] }}
				</p>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Step 1: LLM connection -->
			<section v-show="step === 0" class="wizard__body">
				<p class="wizard__intro">
					{{ t('hermiq', 'Hermiq runs your agents against a local LLM (Ollama). Point it at your model host and test the connection.') }}
				</p>
				<NcTextField
					:value.sync="llmEndpoint"
					:label="t('hermiq', 'LLM endpoint')"
					placeholder="http://host.docker.internal:11434" />
				<div class="wizard__row">
					<NcButton type="secondary" :disabled="testing" @click="testLlm">
						<template v-if="testing" #icon>
							<NcLoadingIcon :size="18" />
						</template>
						{{ t('hermiq', 'Test connection') }}
					</NcButton>
					<span v-if="llmReachable === true" class="wizard__ok">{{ t('hermiq', 'Connected — {count} models', { count: models.length }) }}</span>
					<span v-else-if="llmReachable === false" class="wizard__bad">{{ t('hermiq', 'Not reachable') }}</span>
				</div>
				<NcSelect
					v-if="models.length"
					v-model="model"
					class="wizard__select"
					:input-label="t('hermiq', 'Default model')"
					:options="models"
					:placeholder="t('hermiq', 'Select a model')" />
			</section>

			<!-- Step 2: Talk delivery -->
			<section v-show="step === 1" class="wizard__body">
				<p class="wizard__intro">
					{{ t('hermiq', 'Scheduled runs deliver their results to Nextcloud Talk. Set a default room token (you can override it per schedule). Leave empty to deliver to your Note to self.') }}
				</p>
				<NcTextField
					:value.sync="deliverTarget"
					:label="t('hermiq', 'Default Talk room token')"
					:placeholder="t('hermiq', 'e.g. abc123xy (optional)')" />
			</section>

			<!-- Step 3: Organisation -->
			<section v-show="step === 2" class="wizard__body">
				<p class="wizard__intro">
					{{ t('hermiq', 'Agents, memory and skills are scoped to an organisation for multi-tenant governance. Pick the organisation these belong to.') }}
				</p>
				<NcSelect
					v-if="organisations.length"
					v-model="organisation"
					class="wizard__select"
					:input-label="t('hermiq', 'Organisation')"
					:options="organisations"
					label="name"
					track-by="uuid"
					:placeholder="t('hermiq', 'Select an organisation')" />
				<p v-else class="wizard__muted">
					{{ t('hermiq', 'No organisations found that you own — your agents will run in your personal scope. You can create organisations in OpenRegister later.') }}
				</p>
			</section>

			<!-- Step 4: Seed demo agent -->
			<section v-show="step === 3" class="wizard__body">
				<p class="wizard__intro">
					{{ t('hermiq', 'Want a head start? Hermiq can create a simple demo agent so you can see a successful run right away.') }}
				</p>
				<NcCheckboxRadioSwitch :checked.sync="seedDemo">
					{{ t('hermiq', 'Create a "Hermiq Greeter" demo agent') }}
				</NcCheckboxRadioSwitch>
				<p class="wizard__muted">
					{{ t('hermiq', 'A no-tools agent on your selected model. You can schedule it, run it, and delete it any time.') }}
				</p>
			</section>

			<div class="wizard__actions">
				<NcButton type="tertiary" @click="skip">
					{{ t('hermiq', 'Skip setup') }}
				</NcButton>
				<div class="wizard__nav">
					<NcButton v-if="step > 0" type="secondary" @click="step--">
						{{ t('hermiq', 'Back') }}
					</NcButton>
					<NcButton v-if="step < steps.length - 1" type="primary" @click="step++">
						{{ t('hermiq', 'Next') }}
					</NcButton>
					<NcButton v-else
						type="primary"
						:disabled="finishing"
						@click="finish">
						<template v-if="finishing" #icon>
							<NcLoadingIcon :size="18" />
						</template>
						{{ t('hermiq', 'Finish') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { llmTest, listOrganisations, setPreference } from '../api/setup.js'
import { createAgent } from '../api/agents.js'

export default {
	name: 'SetupWizard',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			step: 0,
			llmEndpoint: 'http://host.docker.internal:11434',
			llmReachable: null,
			testing: false,
			models: [],
			model: '',
			deliverTarget: '',
			organisations: [],
			organisation: null,
			seedDemo: true,
			finishing: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The step labels.
		 *
		 * @return {string[]} The ordered step titles.
		 */
		steps() {
			return [
				this.t('hermiq', 'LLM connection'),
				this.t('hermiq', 'Talk delivery'),
				this.t('hermiq', 'Organisation'),
				this.t('hermiq', 'Get started'),
			]
		},
	},

	created() {
		this.loadOrganisations()
	},

	methods: {
		/**
		 * Load the organisations the user owns for the tenancy step.
		 *
		 * @return {Promise<void>}
		 */
		async loadOrganisations() {
			try {
				this.organisations = await listOrganisations()
			} catch (e) {
				// Non-fatal — the org step falls back to a personal-scope note.
				this.organisations = []
			}
		},

		/**
		 * Probe the LLM endpoint and populate the model list.
		 *
		 * @return {Promise<void>}
		 */
		async testLlm() {
			this.testing = true
			this.error = ''
			try {
				const result = await llmTest(this.llmEndpoint)
				this.llmReachable = !!result.reachable
				this.models = Array.isArray(result.models) ? result.models : []
				if (this.models.length && !this.model) {
					this.model = this.models[0]
				}
				if (!result.reachable && result.error) {
					this.error = result.error
				}
			} catch (e) {
				this.llmReachable = false
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.testing = false
			}
		},

		/**
		 * Persist the chosen defaults + completed flag, optionally seed a demo agent,
		 * then close.
		 *
		 * @return {Promise<void>}
		 */
		async finish() {
			this.finishing = true
			this.error = ''
			try {
				await setPreference('llmendpoint', this.llmEndpoint || '')
				await setPreference('delivertarget', this.deliverTarget || '')
				await setPreference('defaultorganisation', this.organisation?.uuid || '')

				if (this.seedDemo) {
					await this.seedDemoAgent()
				}

				await setPreference('wizardcompleted', '1')
				this.$emit('done')
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not complete setup')
			} finally {
				this.finishing = false
			}
		},

		/**
		 * Create the no-tools demo agent through the OpenRegister agents endpoint.
		 *
		 * @return {Promise<void>}
		 */
		async seedDemoAgent() {
			await createAgent({
				name: 'Hermiq Greeter',
				provider: 'ollama',
				model: this.model || 'qwen3.5:latest',
				prompt: 'You are Hermiq Greeter, a friendly demo agent. When run, reply with a short, warm status message confirming the scheduled run completed successfully.',
				tools: [],
			})
		},

		/**
		 * Skip setup — still mark it completed so it does not reappear every load.
		 *
		 * @return {Promise<void>}
		 */
		async skip() {
			try {
				await setPreference('wizardcompleted', '1')
			} catch (e) {
				// Non-fatal — worst case the wizard shows again next load.
			}
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.wizard {
	padding: 24px;
	min-height: 380px;
	display: flex;
	flex-direction: column;
}

.wizard__title {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.wizard__step-label {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 16px;
}

.wizard__body {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.wizard__intro {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 560px;
}

.wizard__row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.wizard__select {
	max-width: 360px;
}

.wizard__ok {
	color: var(--color-success);
	font-weight: 600;
}

.wizard__bad {
	color: var(--color-error);
	font-weight: 600;
}

.wizard__muted {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
	max-width: 560px;
}

.wizard__actions {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.wizard__nav {
	display: flex;
	gap: 8px;
}
</style>
