<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentAvailabilityDialog: switch one agent off and on (agents-switch-off-and-stop).

  Own file per ADR-004 modal-isolation, NcDialog, opened by AgentDetail's
  "Switch off or on" header action. Shows whether the agent is on, and when it
  is off who switched it, when and why. The agent owner, an instance admin or
  the owner of the agent's organisation may switch; switching off needs a reason.

  @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Switch this agent off or on')"
		:open="show"
		size="normal"
		@update:open="$emit('close')">
		<div
			class="agent-availability-dialog"
			data-testid="agent-availability-dialog">
			<div v-if="loading" class="agent-availability-dialog__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcNoteCard
				v-else-if="notAvailable"
				type="warning"
				:heading="t('hermiq', 'Agent not available')">
				{{ t('hermiq', 'This agent does not exist or you cannot see it.') }}
			</NcNoteCard>

			<template v-else-if="state">
				<p
					v-if="state.active"
					class="agent-availability-dialog__state"
					data-testid="agent-availability-state">
					{{
						t(
							'hermiq',
							'This agent is switched on. It runs from chat, schedules, webhooks and flows.',
						)
					}}
				</p>
				<div v-else data-testid="agent-availability-state">
					<p
						class="agent-availability-dialog__state agent-availability-dialog__state--off">
						{{ t('hermiq', 'Switched off') }}
					</p>
					<p v-if="state.changedBy">
						{{
							t('hermiq', 'Switched off by {user} on {time}.', {
								user: state.changedBy,
								time: formatDate(state.changedAt),
							})
						}}
					</p>
					<p>
						{{
							state.reason
								? t('hermiq', 'Reason: {reason}', {
										reason: state.reason,
									})
								: t('hermiq', 'No reason given.')
						}}
					</p>
				</div>

				<NcNoteCard
					v-if="error"
					type="error"
					:heading="t('hermiq', 'Could not switch the agent')">
					{{ error }}
				</NcNoteCard>

				<NcTextField
					v-if="state.canSwitch && state.active"
					v-model="reason"
					data-testid="agent-availability-reason"
					:label="t('hermiq', 'Why are you switching it off?')"
					:placeholder="
						t(
							'hermiq',
							'For example: sends reminders for closed permits',
						)
					" />

				<p v-if="!state.canSwitch" class="agent-availability-dialog__note">
					{{
						t(
							'hermiq',
							'Only the agent owner or an admin of its organisation can switch this agent.',
						)
					}}
				</p>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="state && state.canSwitch && state.active"
				variant="error"
				data-testid="agent-availability-switch-off"
				:disabled="saving || reason.trim() === ''"
				@click="switchTo(false)">
				{{ t('hermiq', 'Switch off') }}
			</NcButton>
			<NcButton
				v-if="state && state.canSwitch && !state.active"
				variant="primary"
				data-testid="agent-availability-switch-on"
				:disabled="saving"
				@click="switchTo(true)">
				{{ t('hermiq', 'Switch on') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { getAvailability, setAvailability } from '../api/agentAvailability.js'

export default {
	name: 'AgentAvailabilityDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The agent; when absent, the route's `:id` (open-modal props are static). */
		agentId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			state: null,
			reason: '',
			loading: false,
			saving: false,
			notAvailable: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The agent uuid: the prop, else the route's `:id`.
		 *
		 * @return {string} The agent uuid.
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Load the state each time the dialog opens.
			 *
			 * @param {boolean} open Whether it is open.
			 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
			 */
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Load the switch state; a 404 shows "not available".
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
		 */
		async load() {
			if (!this.resolvedAgentId) {
				return
			}
			this.loading = true
			this.notAvailable = false
			this.error = ''
			try {
				this.state = await getAvailability(this.resolvedAgentId)
			} catch (e) {
				this.notAvailable = e?.response?.status === 404
				this.error = this.notAvailable
					? ''
					: e?.response?.data?.error || e?.message || ''
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch the agent, then show the stored state.
		 *
		 * @param {boolean} active On (true) or off (false).
		 * @return {Promise<void>}
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
		 */
		async switchTo(active) {
			this.saving = true
			this.error = ''
			try {
				const stored = await setAvailability(
					this.resolvedAgentId,
					active,
					active ? '' : this.reason.trim(),
				)
				this.state = { ...this.state, ...stored }
				this.reason = ''
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || ''
			} finally {
				this.saving = false
			}
		},

		/**
		 * A readable date and time, or an empty string.
		 *
		 * @param {string} value An ISO-8601 timestamp.
		 * @return {string} The formatted value.
		 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
		 */
		formatDate(value) {
			const date = new Date(value || '')
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.agent-availability-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-availability-dialog__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.agent-availability-dialog__state {
	font-weight: bold;
	margin: 0;
}

.agent-availability-dialog__state--off {
	color: var(--color-error-text);
}

.agent-availability-dialog__note {
	color: var(--color-text-maxcontrast);
}
</style>
