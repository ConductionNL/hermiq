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

  @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
  @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/specs/human-approval-gate-ui/spec.md
-->
<template>
	<div class="approval-inbox">
		<div class="approval-inbox__header">
			<h2 class="approval-inbox__heading">
				{{ t('hermiq', 'Approvals') }}
			</h2>
			<KillSwitchToggle />
		</div>

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Could not load approvals')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="approval-inbox__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="approvals.length === 0"
			:name="t('hermiq', 'No approvals waiting')"
			:description="
				t('hermiq', 'Runs that need your approval will appear here.')
			">
			<template #icon>
				<CheckDecagram :size="20" />
			</template>
		</NcEmptyContent>

		<div
			v-if="!loading && approvals.length > 0"
			class="approval-inbox__filters"
			role="group"
			:aria-label="t('hermiq', 'Show')">
			<NcButton
				:variant="filter === 'all' ? 'primary' : 'tertiary'"
				:aria-pressed="filter === 'all' ? 'true' : 'false'"
				data-testid="approval-filter-all"
				@click="filter = 'all'">
				{{ t('hermiq', 'All') }}
			</NcButton>
			<NcButton
				:variant="filter === 'drafts' ? 'primary' : 'tertiary'"
				:aria-pressed="filter === 'drafts' ? 'true' : 'false'"
				data-testid="approval-filter-drafts"
				@click="filter = 'drafts'">
				{{ t('hermiq', 'Drafts ({count})', { count: draftCount }) }}
			</NcButton>
		</div>

		<NcEmptyContent
			v-if="!loading && approvals.length > 0 && visibleApprovals.length === 0"
			:name="t('hermiq', 'No drafts waiting')"
			:description="t('hermiq', 'Text an agent wants to send is held here when its owner asked to check it first.')" />

		<table v-else-if="!loading && visibleApprovals.length > 0" class="approval-inbox__table">
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
						<span class="hidden-visually">{{
							t('hermiq', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="approval in visibleApprovals" :key="approval.id">
					<td class="approval-inbox__name">
						{{ scheduleName(approval) }}
					</td>
					<td>{{ agentName(approval) }}</td>
					<td class="approval-inbox__prompt">
						<!-- Oversight-ask-and-hold: a question is answered right here; the
						     run continues with the answer. -->
						<AgentQuestionCard
							v-if="approval.sourceType === 'question' && approval.question"
							:question="approval.question"
							:disabled="actioningId === approval.id"
							@answer="answer(approval, $event)" />
						<span v-else-if="approval.sourceType === 'draft'" class="approval-inbox__draft">
							{{ draftExcerpt(approval) }}
						</span>
						<template v-else>
							{{ approval.prompt || '—' }}
						</template>
					</td>
					<td>{{ formatDate(approval.requestedAt) }}</td>
					<td class="approval-inbox__row-actions">
						<NcButton
							variant="secondary"
							:aria-label="t('hermiq', 'What this will do')"
							@click="previewTarget = approval">
							{{ t('hermiq', 'Details') }}
						</NcButton>
						<NcButton
							v-if="approval.sourceType === 'draft'"
							variant="primary"
							:disabled="actioningId === approval.id"
							data-testid="approval-review-draft"
							@click="draftTarget = approval">
							{{ t('hermiq', 'Review') }}
						</NcButton>
						<NcButton
							v-else-if="approval.sourceType !== 'question'"
							variant="primary"
							:disabled="actioningId === approval.id"
							:aria-label="t('hermiq', 'Approve run')"
							@click="approve(approval)">
							<template v-if="actioningId === approval.id" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Approve') }}
						</NcButton>
						<NcButton
							variant="error"
							:disabled="actioningId === approval.id"
							:aria-label="approval.sourceType === 'draft' ? t('hermiq', 'Discard draft') : t('hermiq', 'Deny run')"
							@click="openDeny(approval)">
							{{ approval.sourceType === 'draft' ? t('hermiq', 'Discard') : t('hermiq', 'Deny') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<ApprovalPreviewModal
			v-if="previewTarget"
			:approval="previewTarget"
			@close="previewTarget = null"
			@approve="approveFromPreview"
			@deny="denyFromPreview" />
		<DraftReviewModal
			:show="draftTarget !== null"
			:draft="draftTarget"
			@close="draftTarget = null"
			@released="onReleased"
			@discard="discardFromReview" />
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
import AgentQuestionCard from '../components/AgentQuestionCard.vue'
import KillSwitchToggle from '../components/KillSwitchToggle.vue'
import ApprovalDenyModal from '../modals/ApprovalDenyModal.vue'
import ApprovalPreviewModal from '../modals/ApprovalPreviewModal.vue'
import DraftReviewModal, { draftFields } from '../modals/DraftReviewModal.vue'
import { answerApproval, approveApproval, listPendingApprovals } from '../api/approvals.js'
import { useAgentStore, useScheduleStore } from '../store/store.js'
import { dateOrText } from '../utils/displayDate.js'
import { errorMessage } from '../utils/errorMessage.js'

export default {
	name: 'ApprovalInbox',

	components: {
		AgentQuestionCard,
		ApprovalDenyModal,
		ApprovalPreviewModal,
		CheckDecagram,
		DraftReviewModal,
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
			previewTarget: null,
			// Oversight-ask-and-hold: the "Drafts" filter and the draft under review.
			filter: 'all',
			draftTarget: null,
		}
	},

	computed: {
		/**
		 * The approvals the current filter shows.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-written-output-can-be-held-until-a-reviewer-releases-it-req-askhold-003
		 */
		visibleApprovals() {
			if (this.filter === 'drafts') {
				return this.approvals.filter((approval) => approval.sourceType === 'draft')
			}
			return this.approvals
		},

		/**
		 * How many held drafts wait.
		 *
		 * @return {number}
		 */
		draftCount() {
			return this.approvals.filter((approval) => approval.sourceType === 'draft').length
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
	 */
	created() {
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load pending approvals plus the schedule + agent name lookups in parallel.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [approvals, schedules, agents] = await Promise.all([
					listPendingApprovals(),
					this.store.fetchCollection('schedule'),
					this.agentStore.fetchCollection('agent'),
				])
				this.approvals = Array.isArray(approvals) ? approvals : []
				this.scheduleMap = this.byUuid(
					Array.isArray(schedules) ? schedules : [],
				)
				this.agentMap = this.byUuid(Array.isArray(agents) ? agents : [])
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Index a collection by its uuid (falling back to id).
		 *
		 * @param {Array<object>} items The objects to index.
		 * @return {object} A uuid → object map.
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
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
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
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
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
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
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
		 */
		formatDate: dateOrText,

		/**
		 * Approve from the details dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		async approveFromPreview() {
			const approval = this.previewTarget
			this.previewTarget = null
			await this.approve(approval)
		},

		/**
		 * Deny from the details dialog: opens the deny dialog for a reason.
		 *
		 * @return {void}
		 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
		 */
		denyFromPreview() {
			const approval = this.previewTarget
			this.previewTarget = null
			this.openDeny(approval)
		},

		/**
		 * Approve an approval (runs the gated agent server-side) and reload.
		 *
		 * @param {object} approval The approval record.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
		 */
		async approve(approval) {
			this.actioningId = approval.id
			this.error = ''
			try {
				await approveApproval(approval.id)
				await this.load()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.actioningId = ''
			}
		},

		/**
		 * Open the isolated deny modal for an approval.
		 *
		 * @param {object} approval The approval record.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
		 */
		/**
		 * Answer an agent's question; the run is dispatched again with the answer.
		 *
		 * @param {object} approval The question approval.
		 * @param {string} answer The chosen or typed answer.
		 * @return {Promise<void>}
		 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
		 */
		async answer(approval, answer) {
			this.actioningId = approval.id
			this.error = ''
			try {
				await answerApproval(approval.id, answer)
				await this.load()
			} catch (e) {
				this.error = errorMessage(e, this.t('hermiq', 'Unknown error'))
			} finally {
				this.actioningId = ''
			}
		},

		/**
		 * The first line of a held draft, for its row.
		 *
		 * @param {object} approval The draft approval.
		 * @return {string}
		 */
		draftExcerpt(approval) {
			const fields = draftFields(approval.draft)
			const line = (fields.subject || fields.text).split('\n')[0].trim()
			return line.length > 120 ? `${line.slice(0, 119)}…` : (line || '—')
		},

		/**
		 * A draft was released: close the review and reload.
		 *
		 * @return {Promise<void>}
		 */
		async onReleased() {
			this.draftTarget = null
			await this.load()
		},

		/**
		 * Discard from the review: ask for a reason in the deny dialog.
		 *
		 * @return {void}
		 */
		discardFromReview() {
			const approval = this.draftTarget
			this.draftTarget = null
			this.openDeny(approval)
		},

		openDeny(approval) {
			this.denyTarget = approval
			this.showDeny = true
		},

		/**
		 * Reload after a decision (deny) clears a row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-06-human-approval-gate-ui/tasks.md#task-2-1
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

.approval-inbox__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
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
	clip-path: inset(50%);
}
</style>
