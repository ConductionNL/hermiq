<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ApprovalInbox — the Hermiq "Approvals" nav page (human-approval-gate-ui).

  Lists the pending Approval objects routed to the current user as reviewer (the
  server scopes and guards them), showing the gated schedule name, its agent, the
  prompt, and when approval was requested, with Approve + Deny actions. Approve calls
  the guarded endpoint (which runs the gated agent); Deny opens the isolated
  ApprovalDenyModal (ADR-004). Schedule + agent names are resolved by matching the
  approval's scheduleId/agentId against the createObjectStore schedule collection and
  the agents resource — mirroring AgentCatalog.

  A standard nav page — NOT a dashboard (dashboard-antipattern gate). The org
  kill-switch (KillSwitchToggle) sits in the header, shown only to org sub-admins /
  instance admins via a backend capability flag.

  @spec openspec/changes/human-approval-gate-ui/tasks.md#task-2-1
  @spec openspec/changes/human-approval-gate-ui/specs/human-approval-gate-ui/spec.md
-->
<template>
	<div class="approval-inbox">
		<div class="approval-inbox__header">
			<h2 class="approval-inbox__heading">
				{{ t('hermiq', 'Approvals') }}
			</h2>
			<KillSwitchToggle />
		</div>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not load approvals')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="approval-inbox__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="approvals.length === 0"
			:name="t('hermiq', 'No approvals waiting')"
			:description="t('hermiq', 'Runs that need your approval will appear here.')">
			<template #icon>
				<CheckDecagram :size="20" />
			</template>
		</NcEmptyContent>

		<table v-else class="approval-inbox__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('hermiq', 'Schedule') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Agent') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Prompt') }}
					</th>
					<th scope="col">
						{{ t('hermiq', 'Requested') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('hermiq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="approval in approvals" :key="approval.id">
					<td class="approval-inbox__name">
						{{ scheduleName(approval) }}
					</td>
					<td>{{ agentName(approval) }}</td>
					<td class="approval-inbox__prompt">
						{{ approval.prompt || '—' }}
					</td>
					<td>{{ formatDate(approval.requestedAt) }}</td>
					<td class="approval-inbox__row-actions">
						<NcButton
							type="primary"
							:disabled="actioningId === approval.id"
							:aria-label="t('hermiq', 'Approve run')"
							@click="approve(approval)">
							<template v-if="actioningId === approval.id" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Approve') }}
						</NcButton>
						<NcButton
							type="error"
							:disabled="actioningId === approval.id"
							:aria-label="t('hermiq', 'Deny run')"
							@click="openDeny(approval)">
							{{ t('hermiq', 'Deny') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<ApprovalDenyModal
			:show="showDeny"
			:approval="denyTarget"
			@close="showDeny = false"
			@denied="onDecided" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import { approveApproval, listPendingApprovals } from '../api/approvals.js'
import { listAgents } from '../api/agents.js'
import { useScheduleStore } from '../store/store.js'
import ApprovalDenyModal from '../modals/ApprovalDenyModal.vue'
import KillSwitchToggle from '../components/KillSwitchToggle.vue'

export default {
	name: 'ApprovalInbox',

	components: {
		ApprovalDenyModal,
		CheckDecagram,
		KillSwitchToggle,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			approvals: [],
			scheduleMap: {},
			agentMap: {},
			loading: true,
			error: '',
			showDeny: false,
			denyTarget: null,
			actioningId: '',
		}
	},

	created() {
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load pending approvals plus the schedule + agent name lookups in parallel.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [approvals, schedules, agents] = await Promise.all([
					listPendingApprovals(),
					this.store.fetchCollection('schedule'),
					listAgents(),
				])
				this.approvals = Array.isArray(approvals) ? approvals : []
				this.scheduleMap = this.byUuid(Array.isArray(schedules) ? schedules : [])
				this.agentMap = this.byUuid(Array.isArray(agents) ? agents : [])
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Index a collection by its uuid (falling back to id).
		 *
		 * @param {Array<object>} items The objects to index.
		 * @return {object} A uuid → object map.
		 */
		byUuid(items) {
			const map = {}
			items.forEach((item) => {
				const key = item.uuid || item.id
				if (key) {
					map[String(key)] = item
				}
			})
			return map
		},

		/**
		 * Resolve the gated schedule's name for an approval.
		 *
		 * @param {object} approval The approval record.
		 * @return {string} The schedule name (falls back to the id).
		 */
		scheduleName(approval) {
			const schedule = this.scheduleMap[String(approval.scheduleId)]
			return (schedule && schedule.name) || approval.scheduleId || '—'
		},

		/**
		 * Resolve the bound agent's name for an approval.
		 *
		 * @param {object} approval The approval record.
		 * @return {string} The agent name (falls back to the id).
		 */
		agentName(approval) {
			const agent = this.agentMap[String(approval.agentId)]
			return (agent && agent.name) || approval.agentId || '—'
		},

		/**
		 * Human-friendly requested-at timestamp.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or a dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return String(value)
			}
			return date.toLocaleString()
		},

		/**
		 * Approve an approval (runs the gated agent server-side) and reload.
		 *
		 * @param {object} approval The approval record.
		 * @return {Promise<void>}
		 */
		async approve(approval) {
			this.actioningId = approval.id
			this.error = ''
			try {
				await approveApproval(approval.id)
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.actioningId = ''
			}
		},

		/**
		 * Open the isolated deny modal for an approval.
		 *
		 * @param {object} approval The approval record.
		 * @return {void}
		 */
		openDeny(approval) {
			this.denyTarget = approval
			this.showDeny = true
		},

		/**
		 * Reload after a decision (deny) clears a row.
		 *
		 * @return {Promise<void>}
		 */
		async onDecided() {
			await this.load()
		},
	},
}
</script>

<style scoped>
.approval-inbox {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.approval-inbox__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.approval-inbox__heading {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.approval-inbox__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.approval-inbox__table {
	width: 100%;
	border-collapse: collapse;
}

.approval-inbox__table th,
.approval-inbox__table td {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.approval-inbox__name {
	font-weight: 600;
}

.approval-inbox__prompt {
	max-width: 320px;
	color: var(--color-text-maxcontrast);
}

.approval-inbox__row-actions {
	text-align: right;
	white-space: nowrap;
}

.approval-inbox__row-actions .button-vue {
	margin-inline-start: 6px;
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}
</style>
