<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentFactsheetDialog — a per-agent AI factsheet / model card (compliance-control-packs).

  Own file per ADR-004 modal-isolation, using NcDialog (design.md), mirroring
  CreateIncidentDialog.vue's structure. Read-only: purpose/provider/model/tools, the
  linked AiFeature's risk category + DPO-ack state (if registered), approval decision
  history, linked incidents, and the last access-review timestamp — assembled live by
  ComplianceController::factsheet(), never a stored snapshot. A 404 (the caller is
  neither the agent's owner/actingUser nor holds compliance.view-factsheet) renders a
  clear "not available" state, not a raw error (tasks.md Task 7).

  @spec openspec/changes/compliance-control-packs/tasks.md#task-7-frontend-agent-factsheet-dialog
  @spec openspec/changes/compliance-control-packs/specs/compliance-control-packs/spec.md#requirement-an-ai-factsheet-summarises-an-agents-governance-lifecycle
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Compliance factsheet')"
		:open="show"
		size="normal"
		@update:open="$emit('close')">
		<div class="agent-factsheet-dialog">
			<div v-if="loading" class="agent-factsheet-dialog__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<NcEmptyContent
				v-else-if="notAvailable"
				:name="t('hermiq', 'Factsheet not available')"
				:description="
					t(
						'hermiq',
						'You do not have access to this agent\'s compliance factsheet.',
					)
				">
				<template #icon>
					<ShieldIcon :size="20" />
				</template>
			</NcEmptyContent>

			<NcNoteCard
				v-else-if="error"
				type="error"
				:heading="t('hermiq', 'Could not load factsheet')">
				{{ error }}
			</NcNoteCard>

			<template v-else-if="factsheet">
				<section class="agent-factsheet-dialog__section">
					<h4>{{ factsheet.agent.name }}</h4>
					<dl class="agent-factsheet-dialog__fields">
						<dt>{{ t('hermiq', 'Provider / model') }}</dt>
						<dd>
							{{ factsheet.agent.provider || '—' }} /
							{{ factsheet.agent.model || '—' }}
						</dd>
						<dt>{{ t('hermiq', 'Tools') }}</dt>
						<dd>
							{{
								factsheet.agent.tools.length > 0
									? factsheet.agent.tools.join(', ')
									: t('hermiq', 'No tools')
							}}
						</dd>
						<dt>{{ t('hermiq', 'Owner') }}</dt>
						<dd>{{ factsheet.agent.owner || '—' }}</dd>
						<dt>{{ t('hermiq', 'Acting user') }}</dt>
						<dd>{{ factsheet.agent.actingUser || '—' }}</dd>
						<dt>{{ t('hermiq', 'Last access review') }}</dt>
						<dd>{{ formatDate(factsheet.lastReviewedAt) }}</dd>
					</dl>
				</section>

				<section
					v-if="factsheet.aiFeature"
					class="agent-factsheet-dialog__section">
					<h5>{{ t('hermiq', 'AI feature governance') }}</h5>
					<span
						class="agent-factsheet-dialog__risk"
						:class="`agent-factsheet-dialog__risk--${factsheet.aiFeature.riskCategory}`">
						{{ factsheet.aiFeature.riskCategory }}
					</span>
					<p class="agent-factsheet-dialog__note">
						{{ t('hermiq', 'Lifecycle') }}:
						{{ factsheet.aiFeature.lifecycle || '—' }}
						<template v-if="factsheet.aiFeature.dpoAckBy">
							— {{ t('hermiq', 'DPO-acknowledged by') }}
							{{ factsheet.aiFeature.dpoAckBy }} ({{
								formatDate(factsheet.aiFeature.dpoAckAt)
							}})
						</template>
					</p>
				</section>
				<p v-else class="agent-factsheet-dialog__note">
					{{
						t('hermiq', 'This agent is not registered as an AI feature.')
					}}
				</p>

				<section class="agent-factsheet-dialog__section">
					<h5>{{ t('hermiq', 'Approval decision history') }}</h5>
					<p
						v-if="factsheet.approvals.length === 0"
						class="agent-factsheet-dialog__note">
						{{ t('hermiq', 'No approval decisions recorded yet.') }}
					</p>
					<ul v-else class="agent-factsheet-dialog__list">
						<li
							v-for="(approval, index) in factsheet.approvals"
							:key="index">
							{{ approval.status }} —
							{{ approval.decidedBy || '—' }} ({{
								formatDate(approval.decidedAt)
							}})
						</li>
					</ul>
				</section>

				<section class="agent-factsheet-dialog__section">
					<h5>{{ t('hermiq', 'Linked incidents') }}</h5>
					<p
						v-if="factsheet.incidents.length === 0"
						class="agent-factsheet-dialog__note">
						{{ t('hermiq', 'No linked incidents.') }}
					</p>
					<ul v-else class="agent-factsheet-dialog__list">
						<li
							v-for="(incident, index) in factsheet.incidents"
							:key="index">
							{{ incident.description }} — {{ incident.impact }}
						</li>
					</ul>
				</section>
			</template>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import ShieldIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
import { getAgentFactsheet } from '../api/compliance.js'

export default {
	name: 'AgentFactsheetDialog',

	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ShieldIcon,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/**
		 * The agent UUID to load a factsheet for. Optional — when opened as
		 * the registry `agent-factsheet` open-modal target
		 * (manifest-driven-pages), no prop is available (open-modal action
		 * props are static JSON, not resolved against the current object),
		 * so it self-resolves from the route's `:id` param instead (see
		 * `resolvedAgentId`).
		 */
		agentId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			factsheet: null,
			loading: false,
			notAvailable: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The agent uuid — the `agentId` prop when explicitly supplied, else
		 * the current route's `:id` param.
		 *
		 * @return {string} The resolved agent uuid.
		 */
		resolvedAgentId() {
			return this.agentId || this.$route?.params?.id || ''
		},
	},

	watch: {
		// `immediate: true`: when opened via the registry `agent-factsheet`
		// open-modal action, CnAppRoot mounts this component FRESH with
		// `show` already `true` — a plain watcher only fires on a CHANGE, so
		// it would never run for that mount path without `immediate`.
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Load the agent's factsheet; a 404 (not-owner/not-authorized, or the
		 * agent does not exist) renders the "not available" empty state rather
		 * than a raw error.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.resolvedAgentId) {
				return
			}
			this.loading = true
			this.error = ''
			this.notAvailable = false
			this.factsheet = null
			try {
				this.factsheet = await getAgentFactsheet(this.resolvedAgentId)
			} catch (e) {
				if (e?.response?.status === 404) {
					this.notAvailable = true
				} else {
					this.error =
						e?.response?.data?.error
						|| e?.message
						|| this.t('hermiq', 'Unknown error')
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Human-readable date, or an em-dash when absent/unparseable.
		 *
		 * @param {string} value An ISO-8601 timestamp.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.agent-factsheet-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.agent-factsheet-dialog__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.agent-factsheet-dialog__section h4,
.agent-factsheet-dialog__section h5 {
	margin: 0 0 8px;
}

.agent-factsheet-dialog__fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.agent-factsheet-dialog__fields dt {
	color: var(--color-text-maxcontrast);
}

.agent-factsheet-dialog__fields dd {
	margin: 0;
}

.agent-factsheet-dialog__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 4px 0;
}

.agent-factsheet-dialog__list {
	margin: 0;
	padding-left: 20px;
}

.agent-factsheet-dialog__risk {
	font-size: 12px;
	text-transform: uppercase;
	width: fit-content;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
	display: inline-block;
}

.agent-factsheet-dialog__risk--minimal {
	color: var(--color-success);
}

.agent-factsheet-dialog__risk--limited {
	color: var(--color-warning);
}

.agent-factsheet-dialog__risk--high {
	color: var(--color-error);
}

.agent-factsheet-dialog__risk--unacceptable {
	color: var(--color-error);
	background: var(--color-error-hover, var(--color-background-dark));
}
</style>
