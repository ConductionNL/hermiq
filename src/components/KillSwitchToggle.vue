<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  KillSwitchToggle — per-organisation kill-switch control (human-approval-gate-ui).

  EU AI Act Art. 14 stop mechanism: engaging it halts every agent run for the
  organisation on the next dispatch tick. Rendered ONLY when the backend-provided
  capability flag (`hermiq::can_manage_killswitch`, read via loadState — never a DOM
  data-attribute read) marks the current user an org sub-admin or instance admin, and
  at least one manageable organisation is present. Reads + toggles the org's
  TenantControl through the guarded endpoints in src/api/approvals.js; the server
  remains the real authorization boundary.

  @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
  @spec openspec/changes/human-approval-gate-ui/specs/human-approval-gate-ui/spec.md
-->
<template>
	<div v-if="canManage && organisations.length > 0" class="kill-switch">
		<div class="kill-switch__head">
			<AlertOctagon :size="20" :class="engaged ? 'kill-switch__icon--on' : 'kill-switch__icon--off'" />
			<span class="kill-switch__label">{{ t('hermiq', 'Emergency stop') }}</span>
		</div>

		<div v-if="organisations.length > 1" class="kill-switch__org">
			<NcSelect
				v-model="orgOption"
				:input-label="t('hermiq', 'Organisation')"
				:options="orgOptions"
				:clearable="false"
				label="label"
				track-by="value" />
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Kill-switch error')">
			{{ error }}
		</NcNoteCard>

		<div class="kill-switch__row">
			<NcCheckboxRadioSwitch
				:checked="engaged"
				:disabled="loading || saving"
				type="switch"
				@update:checked="onToggle">
				{{ engaged ? t('hermiq', 'All runs halted for this organisation') : t('hermiq', 'Halt all runs for this organisation') }}
			</NcCheckboxRadioSwitch>
			<NcLoadingIcon v-if="loading || saving" :size="20" />
		</div>

		<p v-if="engaged && reason" class="kill-switch__reason">
			{{ reason }}
		</p>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { loadState } from '@nextcloud/initial-state'
import AlertOctagon from 'vue-material-design-icons/AlertOctagon.vue'
import { getKillSwitch, toggleKillSwitch } from '../api/approvals.js'

export default {
	name: 'KillSwitchToggle',

	components: {
		AlertOctagon,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		// Capability + manageable organisations come from the backend via
		// IInitialState (loadState) — never a DOM data-attribute read (ADR-004).
		const organisations = loadState('hermiq', 'managed_organisations', [])
		return {
			canManage: loadState('hermiq', 'can_manage_killswitch', false) === true,
			organisations: Array.isArray(organisations) ? organisations : [],
			selectedOrg: '',
			engaged: false,
			reason: '',
			loading: false,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The manageable organisations as NcSelect options.
		 *
		 * @return {Array<object>} The { label, value } options.
		 */
		orgOptions() {
			return this.organisations.map((org) => ({
				label: org.label || org.id,
				value: org.id,
			}))
		},

		/**
		 * Two-way bridge between the selected org id and the NcSelect option object.
		 */
		orgOption: {
			get() {
				return this.orgOptions.find((option) => option.value === this.selectedOrg) || this.orgOptions[0]
			},
			set(option) {
				this.selectedOrg = option ? option.value : ''
				this.load()
			},
		},
	},

	created() {
		if (this.canManage && this.organisations.length > 0) {
			this.selectedOrg = this.organisations[0].id
			this.load()
		}
	},

	methods: {
		/**
		 * Read the current kill-switch state for the selected organisation.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.selectedOrg) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const state = await getKillSwitch(this.selectedOrg)
				this.engaged = state?.engaged === true
				this.reason = state?.reason || ''
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Engage or disengage the kill-switch for the selected organisation.
		 *
		 * @param {boolean} next The requested engaged state.
		 * @return {Promise<void>}
		 */
		async onToggle(next) {
			if (!this.selectedOrg) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const state = await toggleKillSwitch(this.selectedOrg, next, '')
				this.engaged = state?.engaged === true
				this.reason = state?.reason || ''
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
				// Reflect the server's actual state on failure.
				await this.load()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.kill-switch {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.kill-switch__head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.kill-switch__label {
	font-weight: 600;
}

.kill-switch__icon--on {
	color: var(--color-error);
}

.kill-switch__icon--off {
	color: var(--color-text-maxcontrast);
}

.kill-switch__row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.kill-switch__reason {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
