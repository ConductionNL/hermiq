<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentEvalBaselineWidget — the AgentDetail data widget that holds the
  `evalBaselineMode` register property (skill-evals): the value is edited
  HERE, and the register property's consequence-explaining description is
  surfaced HERE as an info affordance (a keyboard-reachable info-icon
  popover), so the joint-vs-per-skill semantics and the 2x vs (N+1)x cost
  consequence are visible exactly where the user changes the value (spec
  scenario). The description text mirrors the register schema's property
  description verbatim (translated); the write is a plain object PUT through
  the generic agent store, all other fields carried forward.

  A dedicated small widget rather than an entry in the built-in `agent-core`
  type:data widget because the built-in data widget renders values only — it
  has no per-property info affordance or inline editor at HEAD (ground-truth
  adaptation of design.md Decision 9's info-affordance placement; flagged for
  the reviewer).

  @spec openspec/specs/agent-evals/spec.md#requirement-the-agent-schema-declares-evalbaselinemode-with-a-consequence-explaining-description
-->
<template>
	<div class="agent-eval-baseline">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Eval baseline error')">
			{{ error }}
		</NcNoteCard>

		<div class="agent-eval-baseline__row">
			<NcSelect
				v-model="selectedMode"
				class="agent-eval-baseline__select"
				:input-label="t('hermiq', 'Eval baseline mode')"
				:options="modeOptions"
				:disabled="saving || loading"
				label="label"
				track-by="value"
				:clearable="false" />
			<NcPopover class="agent-eval-baseline__info" :focus-trap="false">
				<template #trigger>
					<NcButton
						type="tertiary"
						:aria-label="t('hermiq', 'What does the eval baseline mode change?')">
						<template #icon>
							<InformationOutline :size="20" />
						</template>
					</NcButton>
				</template>
				<p class="agent-eval-baseline__description">
					{{ modeDescription }}
				</p>
			</NcPopover>
			<NcButton
				type="secondary"
				:disabled="saving || loading || !isDirty"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('hermiq', 'Save') }}
			</NcButton>
		</div>
		<p class="agent-eval-baseline__hint">
			{{ currentModeSummary }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcPopover, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentEvalBaselineWidget',

	components: {
		InformationOutline,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcPopover,
		NcSelect,
	},

	data() {
		return {
			agent: null,
			selectedMode: null,
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * The two attribution modes as picker options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		modeOptions() {
			return [
				{ label: this.t('hermiq', 'Joint (default) — one baseline, ~2x cost'), value: 'joint' },
				{ label: this.t('hermiq', 'Per-skill — true marginals, (N+1)x cost'), value: 'per-skill' },
			]
		},

		/**
		 * The stored mode ('joint' unless explicitly 'per-skill' — an agent
		 * without the property behaves as joint).
		 *
		 * @return {string} joint|per-skill.
		 */
		storedMode() {
			return this.agent?.evalBaselineMode === 'per-skill' ? 'per-skill' : 'joint'
		},

		/**
		 * Whether the picker differs from the stored value.
		 *
		 * @return {boolean} True when a save would change the agent.
		 */
		isDirty() {
			return !!this.selectedMode && this.selectedMode.value !== this.storedMode
		},

		/**
		 * The register property's consequence-explaining description, verbatim —
		 * the SAME text stored on Agent.evalBaselineMode in
		 * lib/Settings/hermiq_register.json (the info affordance's content).
		 *
		 * @return {string} The translated description.
		 */
		modeDescription() {
			return this.t('hermiq', "How a paired (with-skill vs without-skill) eval baseline is constructed for this agent. 'joint' (the default, also when unset): every paired run executes ONE without-skill half that detaches all of the dataset's linked skills together — each case runs exactly twice, at roughly 2x the token cost of a normal eval run, and the recorded baseline delta is the JOINT contribution of the whole linked skill set, shared by every linked skill. 'per-skill': every paired run executes one without-skill half PER linked skill (the with-set minus only that skill) — with N linked skills each case runs N+1 times, at (N+1)x the token cost per paired run, and each skill receives its own TRUE marginal baseline delta. Every half counts toward the same organisation and agent budgets.")
		},

		/**
		 * A one-line summary of the currently STORED mode.
		 *
		 * @return {string} The summary line.
		 */
		currentModeSummary() {
			if (this.storedMode === 'per-skill') {
				return this.t('hermiq', 'Paired evals run one without-skill half per linked skill — true per-skill deltas at (N+1)x token cost.')
			}
			return this.t('hermiq', 'Paired evals run one shared without-skill half — a joint delta for the linked set at ~2x token cost.')
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the agent and initialise the picker from the stored value.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.agent = await this.agentStore.fetchObject('agent', this.agentId)
				this.selectedMode = this.modeOptions.find((option) => option.value === this.storedMode) || this.modeOptions[0]
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not load the agent.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the changed mode through the generic object path (plain PUT —
		 * every other agent field carried forward).
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.agent || !this.selectedMode) {
				return
			}
			this.saving = true
			try {
				await this.agentStore.saveObject('agent', { ...this.agent, evalBaselineMode: this.selectedMode.value })
				await this.load()
				showSuccess(this.t('hermiq', 'Eval baseline mode saved.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not save the eval baseline mode.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.agent-eval-baseline__row {
	display: flex;
	align-items: flex-end;
	gap: 4px;
	flex-wrap: wrap;
}

.agent-eval-baseline__select {
	min-width: 280px;
	flex: 1 1 auto;
}

.agent-eval-baseline__description {
	max-width: 420px;
	padding: 12px;
	margin: 0;
	font-size: 13px;
}

.agent-eval-baseline__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0 0;
}
</style>
