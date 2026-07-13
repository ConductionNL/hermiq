<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentDetail — one agent's detail page (agent-management-ui + agent-capability-detail-surface).

  The management surface for all three agent capabilities:
  - Config: the schema-driven CnObjectDataWidget renders the agent's own fields as a
    click-to-edit grid (agent-capability-detail-surface). The dynamic tool allowlist is
    edited through the widget's #field-tools slot, fed by the live tool catalogue
    (/api/agents/tools) since the schema carries no static enum. The header "Edit agent"
    button still opens the config-only AgentFormModal.
  - Skills: an in-place attach/detach section over the agent's skillInstalls, backed by
    the skills-catalog install/uninstall endpoints.
  - Memory: the shared AgentMemoryPanel, the same surface as the standalone Memory page.

  Also lets the user attach/edit a Schedule (ScheduleFormModal, persisted via
  createObjectStore), trigger a Run now (POST /apps/hermiq/api/schedules/{id}/run), and
  review Run history. Because OpenRegister's agent execution is a work-in-progress that
  can return an error, Run now renders a graceful, dismissible error state (kept in run
  history) rather than breaking the view. All modals are isolated files (ADR-004).

  Run history (run-reliability) also surfaces the retry_pending/dead_letter/
  paused_circuit_breaker vocabulary as distinct badges (statusBadgeClass/statusLabel —
  never color-only) plus the attempt number, and a per-row "Re-run" action on a
  dead_letter row that reuses the SAME runNow()/runScheduleNow() path as the
  page-level "Run now" button (no new endpoint).

  Each run row also has a "Details" toggle (run-trace-observability) that expands
  an ordered step timeline fetched from the per-run trace endpoint, honestly
  labels a run whose execution path recorded no tool-call detail (never implying
  zero tool activity), and offers a "Download trace (JSON)" action that saves the
  already-redacted, already-fetched trace verbatim.

  @spec openspec/changes/agent-management-ui/tasks.md#task-5-1
  @spec openspec/changes/agent-management-ui/specs/agent-management-ui/spec.md
  @spec openspec/changes/agent-capability-detail-surface/specs/agent-management-ui/spec.md
  @spec openspec/changes/run-reliability/specs/agent-schedule/spec.md#requirement-dead-letter-state-after-retries-are-exhausted-with-manual-re-run-mvp
  @spec openspec/changes/run-trace-observability/tasks.md#task-6-frontend-run-trace-api-step-timeline-expand-view
  @spec openspec/changes/run-trace-observability/tasks.md#task-7-frontend-download-trace-json-action
-->
<template>
	<div class="agent-detail">
		<NcButton type="tertiary" class="agent-detail__back" @click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('hermiq', 'Back to agents') }}
		</NcButton>

		<div v-if="loading" class="agent-detail__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="!agent"
			:name="t('hermiq', 'Agent not found')"
			:description="t('hermiq', 'This agent does not exist or you may not have access to it.')">
			<template #icon>
				<Robot :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<div class="agent-detail__header">
				<h2 class="agent-detail__title">
					{{ agent.name || t('hermiq', 'Untitled agent') }}
				</h2>
				<div class="agent-detail__header-actions">
					<NcButton @click="showVersionHistory = true">
						<template #icon>
							<History :size="20" />
						</template>
						{{ t('hermiq', 'Version history') }}
					</NcButton>
					<NcButton @click="showEditAgent = true">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('hermiq', 'Edit agent') }}
					</NcButton>
				</div>
			</div>

			<!-- Config: schema-driven click-to-edit grid. The dynamic tool allowlist is
			     edited through the #field-tools slot (the schema has no static enum). -->
			<CnObjectDataWidget
				v-if="schema"
				:title="t('hermiq', 'Configuration')"
				:schema="schema"
				:object-data="agent"
				object-type="agent"
				:store="agentStore"
				:exclude="hiddenFields"
				@saved="onAgentSaved">
				<template #field-tools="{ value, update }">
					<NcSelect
						:value="toolsToOptions(value)"
						:input-label="t('hermiq', 'Enabled tools')"
						:options="toolOptions"
						:loading="toolsLoading"
						:multiple="true"
						:close-on-select="false"
						label="label"
						track-by="value"
						:placeholder="t('hermiq', 'Select tools the agent may use')"
						@input="(selected) => update((selected || []).map((option) => option.value))" />
				</template>
				<template #display-tools>
					{{ toolsLabel }}
				</template>
			</CnObjectDataWidget>

			<!-- Skills section -->
			<section class="agent-detail__section">
				<div class="agent-detail__section-head">
					<h3>{{ t('hermiq', 'Skills') }}</h3>
					<div class="agent-detail__skill-attach">
						<NcSelect
							v-model="skillToAttach"
							:input-label="t('hermiq', 'Attach a skill')"
							:options="attachableSkillOptions"
							:loading="skillsLoading"
							:disabled="skillBusy || attachableSkillOptions.length === 0"
							label="label"
							track-by="value"
							:placeholder="t('hermiq', 'Select a skill to attach')" />
						<NcButton
							type="secondary"
							:disabled="skillBusy || !skillToAttach"
							@click="attachSkill">
							<template v-if="skillBusy" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Attach') }}
						</NcButton>
					</div>
				</div>

				<p v-if="installedSkills.length === 0" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No skills installed yet. Attach one to give this agent extra capabilities.') }}
				</p>
				<ul v-else class="agent-detail__skills">
					<li v-for="skill in installedSkills" :key="skill.value" class="agent-detail__skill">
						<span class="agent-detail__skill-name">{{ skill.label }}</span>
						<NcButton
							type="tertiary"
							:disabled="skillBusy"
							:aria-label="t('hermiq', 'Detach skill')"
							@click="detachSkill(skill.value)">
							<template #icon>
								<Close :size="20" />
							</template>
							{{ t('hermiq', 'Detach') }}
						</NcButton>
					</li>
				</ul>
			</section>

			<!-- Memory section -->
			<section class="agent-detail__section">
				<h3>{{ t('hermiq', 'Memory') }}</h3>
				<AgentMemoryPanel :agent-id="agentUuid" />
			</section>

			<!-- Tool grants (agent-tool-governance-and-disclosure): schema-scoped grants over
			     the ADR-063 derived catalogue, with default-deny made visible on writes. -->
			<section class="agent-detail__section">
				<ToolGrantEditor
					:agent-id="agentUuid"
					:can-edit="isOwner"
					@saved="onGrantsSaved" />
			</section>

			<!-- Tool activity (agent-tool-governance-and-disclosure): the EU AI Act art.12/14
			     per-agent oversight surface read from OpenRegister's AuditTrail. -->
			<section class="agent-detail__section">
				<ToolInvocationTable :agent-id="agentUuid" />
			</section>

			<!-- Schedule section -->
			<section class="agent-detail__section">
				<div class="agent-detail__section-head">
					<h3>{{ t('hermiq', 'Schedule') }}</h3>
					<div class="agent-detail__section-actions">
						<NcButton @click="showScheduleForm = true">
							{{ schedule ? t('hermiq', 'Edit schedule') : t('hermiq', 'Attach schedule') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="!schedule || running"
							@click="runNow">
							<template #icon>
								<NcLoadingIcon v-if="running" :size="20" />
								<Play v-else :size="20" />
							</template>
							{{ t('hermiq', 'Run now') }}
						</NcButton>
					</div>
				</div>

				<!-- Pre-run cost estimate (cost-guardrails): trailing average, clearly
				     labelled an estimate — never a fabricated figure without history. -->
				<p v-if="estimate && estimate.available" class="agent-detail__estimate">
					{{ t('hermiq', 'Estimate: ~{tokens} tokens per run (average of the last {count} runs)', { tokens: estimate.avgTotalTokens, count: estimate.sampleSize }) }}
				</p>
				<p v-else-if="estimate" class="agent-detail__estimate agent-detail__estimate--empty">
					{{ t('hermiq', 'Not enough run history yet for a cost estimate.') }}
				</p>

				<NcNoteCard
					v-if="runError"
					type="error"
					:heading="t('hermiq', 'The last run reported an error')">
					{{ runError }}
					<template #action>
						<NcButton type="tertiary" @click="runError = ''">
							{{ t('hermiq', 'Dismiss') }}
						</NcButton>
					</template>
				</NcNoteCard>

				<p v-if="!schedule" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No schedule attached yet. Attach one to run this agent unattended, or use Run now once attached.') }}
				</p>
				<dl v-else class="agent-detail__meta">
					<div>
						<dt>{{ t('hermiq', 'Trigger') }}</dt>
						<dd>{{ triggerLabel }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Deliver output to') }}</dt>
						<dd>{{ schedule.deliver || 'none' }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Enabled') }}</dt>
						<dd>{{ schedule.enabled === false ? t('hermiq', 'No') : t('hermiq', 'Yes') }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Next run') }}</dt>
						<dd>{{ formatDate(schedule.nextRun) }}</dd>
					</div>
				</dl>
			</section>

			<!-- Webhook section (agent-webhook-trigger): per-agent inbound trigger. -->
			<section class="agent-detail__section">
				<div class="agent-detail__section-head">
					<h3>{{ t('hermiq', 'Webhook trigger') }}</h3>
					<div class="agent-detail__section-actions">
						<NcButton
							v-if="!webhookStatus || !webhookStatus.configured"
							:disabled="webhookBusy"
							@click="createWebhook">
							<template v-if="webhookBusy" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('hermiq', 'Create webhook') }}
						</NcButton>
						<template v-else>
							<NcButton :disabled="webhookBusy" @click="rotateWebhook">
								{{ t('hermiq', 'Rotate secret') }}
							</NcButton>
							<NcButton
								v-if="webhookStatus.enabled"
								type="error"
								:disabled="webhookBusy"
								@click="revokeWebhook">
								{{ t('hermiq', 'Revoke') }}
							</NcButton>
						</template>
					</div>
				</div>

				<p v-if="!webhookStatus || !webhookStatus.configured" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No webhook configured yet. Create one to let an external system (n8n, a CI pipeline, a third-party event) trigger this agent.') }}
				</p>
				<dl v-else class="agent-detail__meta">
					<div>
						<dt>{{ t('hermiq', 'Status') }}</dt>
						<dd>
							<span :class="['agent-detail__badge', webhookStatus.enabled ? 'agent-detail__badge--ok' : 'agent-detail__badge--error']">
								{{ webhookStatus.enabled ? t('hermiq', 'Enabled') : t('hermiq', 'Disabled') }}
							</span>
						</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Secret') }}</dt>
						<dd>{{ webhookStatus.secretPrefix }}…</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Last used') }}</dt>
						<dd>{{ formatDate(webhookStatus.lastUsedAt) }}</dd>
					</div>
					<div>
						<dt>{{ t('hermiq', 'Rotated') }}</dt>
						<dd>{{ formatDate(webhookStatus.rotatedAt) }}</dd>
					</div>
				</dl>
			</section>

			<!-- Budget section (cost-guardrails): agent-scoped status when configured -->
			<section v-if="budgetStatus && budgetStatus.configured" class="agent-detail__section">
				<h3>{{ t('hermiq', 'Budget') }}</h3>
				<NcNoteCard v-if="budgetStatus.hardCapReached" type="error">
					{{ t('hermiq', 'Hard cap reached — new runs are blocked until the next period.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="budgetStatus.softThresholdReached" type="warning">
					{{ t('hermiq', 'Soft threshold crossed for the current period.') }}
				</NcNoteCard>
				<dl class="agent-detail__meta">
					<div v-if="budgetStatus.tokens && budgetStatus.tokens.limit">
						<dt>{{ t('hermiq', 'Tokens this period') }}</dt>
						<dd>{{ budgetStatus.tokens.used }} / {{ budgetStatus.tokens.limit }} ({{ budgetStatus.tokens.percent }}%)</dd>
					</div>
					<div v-if="budgetStatus.eur && budgetStatus.eur.limit">
						<dt>{{ t('hermiq', 'Spend this period') }}</dt>
						<dd>€{{ budgetStatus.eur.used }} / €{{ budgetStatus.eur.limit }}</dd>
					</div>
				</dl>
			</section>

			<!-- Run history section -->
			<section class="agent-detail__section">
				<h3>{{ t('hermiq', 'Run history') }}</h3>

				<NcNoteCard v-if="runsError" type="warning">
					{{ t('hermiq', 'Could not load run history.') }}
				</NcNoteCard>

				<p v-else-if="!schedule" class="agent-detail__empty-hint">
					{{ t('hermiq', 'Attach a schedule to start recording run history.') }}
				</p>
				<p v-else-if="runs.length === 0" class="agent-detail__empty-hint">
					{{ t('hermiq', 'No runs yet.') }}
				</p>
				<table v-else class="agent-detail__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('hermiq', 'Status') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Started') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Duration') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Attempt') }}
							</th>
							<th scope="col">
								{{ t('hermiq', 'Agent version') }}
							</th>
							<th scope="col">
								<span class="hidden-visually">{{ t('hermiq', 'Actions') }}</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="run in runs">
							<tr :key="run.id">
								<td>
									<span :class="['agent-detail__badge', statusBadgeClass(run.status)]">
										{{ statusLabel(run.status) }}
									</span>
								</td>
								<td>{{ formatDate(run.startedAt || run.created) }}</td>
								<td>{{ durationLabel(run.durationMs) }}</td>
								<td>{{ run.attempt || '—' }}</td>
								<td>{{ shortVersionLabel(run.agentVersion) }}</td>
								<td class="agent-detail__row-actions">
									<NcButton
										type="tertiary"
										:aria-label="t('hermiq', 'View this run\'s step timeline')"
										@click="toggleRunTrace(run)">
										{{ expandedRunId === run.id ? t('hermiq', 'Hide details') : t('hermiq', 'Details') }}
									</NcButton>
									<NcButton
										v-if="run.status === 'dead_letter'"
										type="tertiary"
										:disabled="running"
										:aria-label="t('hermiq', 'Re-run this dead-lettered schedule')"
										@click="runNow">
										{{ t('hermiq', 'Re-run') }}
									</NcButton>
								</td>
							</tr>
							<tr v-if="expandedRunId === run.id" :key="`${run.id}-trace`">
								<td colspan="6" class="agent-detail__trace-cell">
									<NcLoadingIcon v-if="traceLoading" :size="24" />
									<NcNoteCard v-else-if="traceError" type="warning">
										{{ t('hermiq', "Could not load this run's trace.") }}
									</NcNoteCard>
									<div v-else-if="runTraces[run.id]" class="agent-detail__trace">
										<p v-if="runTraces[run.id].toolStepsAvailable === false" class="agent-detail__trace-hint">
											{{ t('hermiq', "Tool-level detail is unavailable for this run's execution path.") }}
										</p>
										<p v-if="!runTraces[run.id].steps || runTraces[run.id].steps.length === 0" class="agent-detail__empty-hint">
											{{ t('hermiq', 'No step detail recorded for this run.') }}
										</p>
										<ol v-else class="agent-detail__trace-steps">
											<li v-for="step in runTraces[run.id].steps" :key="step.seq" class="agent-detail__trace-step">
												<span class="agent-detail__trace-step-type">{{ stepTypeLabel(step.type) }}</span>
												<span class="agent-detail__trace-step-name">{{ step.name }}</span>
												<span class="agent-detail__trace-step-duration">{{ stepDurationLabel(step.durationMs) }}</span>
												<span :class="['agent-detail__badge', step.outcome === 'error' ? 'agent-detail__badge--error' : 'agent-detail__badge--ok']">
													{{ step.outcome }}
												</span>
											</li>
										</ol>
										<NcButton type="tertiary" @click="downloadTrace(run)">
											{{ t('hermiq', 'Download trace (JSON)') }}
										</NcButton>
									</div>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</section>

			<AgentFormModal
				:show="showEditAgent"
				:agent="agent"
				@close="showEditAgent = false"
				@saved="onAgentSaved" />

			<ScheduleFormModal
				:show="showScheduleForm"
				:agent-id="agentUuid"
				:schedule="schedule"
				@close="showScheduleForm = false"
				@saved="onScheduleSaved" />

			<WebhookSecretDialog
				:show="showWebhookSecretDialog"
				:secret="revealedSecret"
				@close="closeWebhookSecretDialog" />

			<AgentVersionHistoryDialog
				:show="showVersionHistory"
				:agent-id="agentUuid"
				:can-rollback="isOwner"
				@close="showVersionHistory = false"
				@compare="onCompareVersions"
				@rolled-back="onAgentSaved" />

			<AgentVersionDiffDialog
				:show="showVersionDiff"
				:agent-id="agentUuid"
				:from-id="diffFromId"
				:to-id="diffToId"
				@close="showVersionDiff = false" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnObjectDataWidget } from '@conduction/nextcloud-vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Close from 'vue-material-design-icons/Close.vue'
import History from 'vue-material-design-icons/History.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import { getRunTrace, listRuns, listTools, runScheduleNow } from '../api/agents.js'
import { getBudgetEstimate, getBudgetStatus } from '../api/budgets.js'
import { installSkill, listSkills, uninstallSkill } from '../api/skills.js'
import { createWebhookSecret, getWebhookStatus, revokeWebhookSecret, rotateWebhookSecret } from '../api/webhooks.js'
import { useAgentStore, useScheduleStore } from '../store/store.js'
import { getCurrentUser } from '@nextcloud/auth'
import AgentFormModal from '../modals/AgentFormModal.vue'
import AgentMemoryPanel from '../components/AgentMemoryPanel.vue'
import AgentVersionDiffDialog from '../dialogs/agents/AgentVersionDiffDialog.vue'
import AgentVersionHistoryDialog from '../dialogs/agents/AgentVersionHistoryDialog.vue'
import ScheduleFormModal from '../modals/ScheduleFormModal.vue'
import ToolGrantEditor from '../components/ToolGrantEditor.vue'
import ToolInvocationTable from '../components/ToolInvocationTable.vue'
import WebhookSecretDialog from '../modals/WebhookSecretDialog.vue'

export default {
	name: 'AgentDetail',

	components: {
		AgentFormModal,
		AgentMemoryPanel,
		AgentVersionDiffDialog,
		AgentVersionHistoryDialog,
		ArrowLeft,
		Close,
		CnObjectDataWidget,
		History,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Pencil,
		Play,
		Robot,
		ScheduleFormModal,
		ToolGrantEditor,
		ToolInvocationTable,
		WebhookSecretDialog,
	},

	data() {
		return {
			agent: null,
			schema: null,
			schedule: null,
			runs: [],
			loading: true,
			running: false,
			runError: '',
			runsError: false,
			// Run trace (run-trace-observability): which run row is expanded, its
			// fetched trace(s) cached by run id, and the expanded row's load state.
			expandedRunId: null,
			runTraces: {},
			traceLoading: false,
			traceError: false,
			showEditAgent: false,
			showScheduleForm: false,
			// Version history (agent-versioning): timeline/rollback dialog + the
			// diff dialog it triggers via @compare, keeping the two selected ids.
			showVersionHistory: false,
			showVersionDiff: false,
			diffFromId: '',
			diffToId: '',
			// Config data widget: the dynamic tool catalogue for the #field-tools slot.
			toolOptions: [],
			toolsLoading: false,
			// Skills section state.
			skills: [],
			skillsLoading: false,
			skillBusy: false,
			skillToAttach: null,
			// Cost guardrails (cost-guardrails): pre-run estimate + agent-scoped budget
			// status. Both non-fatal — null keeps their UI hidden on error.
			estimate: null,
			budgetStatus: null,
			// Webhook trigger (agent-webhook-trigger): status + copy-once secret reveal.
			// revealedSecret is transient (never persisted, cleared on dialog close) —
			// the panel itself only ever shows the masked secretPrefix afterward.
			webhookStatus: null,
			webhookBusy: false,
			showWebhookSecretDialog: false,
			revealedSecret: '',
			// Fields the config widget hides (tenancy, quotas, and capabilities managed by
			// their own sections — skills here, memory below).
			hiddenFields: [
				'configuration', 'views', 'invitedUsers', 'groups', 'isPrivate',
				'requestQuota', 'tokenQuota', 'actingUser', 'skillInstalls', 'contextRefs',
			],
		}
	},

	computed: {
		/**
		 * The agent uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentUuid() {
			return this.$route.params.id
		},

		/**
		 * Whether the current user owns this agent — the grant editor is read-only for
		 * everyone else (the server enforces owner-only on PUT .../tool-grants regardless;
		 * this only avoids offering an action that would be refused).
		 *
		 * @return {boolean} True when the current user is the agent's owner.
		 */
		isOwner() {
			const user = getCurrentUser()
			return !!(user && this.agent && this.agent.owner === user.uid)
		},

		/**
		 * Comma-joined list of the agent's enabled tools (the data widget's tools display).
		 *
		 * @return {string} The tools label.
		 */
		toolsLabel() {
			const tools = Array.isArray(this.agent && this.agent.tools) ? this.agent.tools : []
			return tools.length ? tools.join(', ') : '—'
		},

		/**
		 * The agent's installed skills as { label, value } options, resolved from
		 * skillInstalls uuids against the skills catalogue (falls back to the uuid).
		 *
		 * @return {Array<object>} The installed-skill options.
		 */
		installedSkills() {
			const installed = Array.isArray(this.agent && this.agent.skillInstalls) ? this.agent.skillInstalls : []
			return installed.map((uuid) => {
				const match = this.skills.find((skill) => (skill.uuid || skill.id) === uuid)
				return { label: (match && match.name) || uuid, value: uuid }
			})
		},

		/**
		 * Catalogue skills not yet installed on this agent, as attach options.
		 *
		 * @return {Array<object>} The attachable-skill options.
		 */
		attachableSkillOptions() {
			const installed = Array.isArray(this.agent && this.agent.skillInstalls) ? this.agent.skillInstalls : []
			return this.skills
				.filter((skill) => !installed.includes(skill.uuid || skill.id))
				.map((skill) => ({ label: skill.name || skill.uuid || skill.id, value: skill.uuid || skill.id }))
		},

		/**
		 * Human label for the schedule trigger.
		 *
		 * @return {string} The trigger label.
		 */
		triggerLabel() {
			if (!this.schedule) {
				return '—'
			}
			if (this.schedule.kind === 'cron') {
				return `${this.t('hermiq', 'Cron')}: ${this.schedule.cronExpr || ''}`
			}
			if (this.schedule.kind === 'interval') {
				return `${this.t('hermiq', 'Every')} ${this.schedule.intervalMinutes} ${this.t('hermiq', 'minutes')}`
			}
			return `${this.t('hermiq', 'Once')}: ${this.formatDate(this.schedule.runAt)}`
		},
	},

	created() {
		this.store = useScheduleStore()
		this.store.registerObjectType('schedule', 'schedule', 'hermiq')
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the agent (createObjectStore, hermiq register), its attached
		 * schedule, and its run history.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-7-agentdetail-webhook-panel-copy-once-secret-dialog
		 */
		async load() {
			this.loading = true
			try {
				const [agent, schema, schedules] = await Promise.all([
					this.agentStore.fetchObject('agent', this.agentUuid),
					this.agentStore.fetchSchema('agent'),
					this.store.fetchCollection('schedule'),
				])
				this.agent = agent || null
				this.schema = schema || null
				this.schedule = (Array.isArray(schedules) ? schedules : [])
					.find((candidate) => candidate.agentId === this.agentUuid) || null
				await Promise.all([this.loadRuns(), this.loadTools(), this.loadSkills(), this.loadBudgetInfo(), this.loadWebhookStatus()])
			} catch (e) {
				showError(this.t('hermiq', 'Could not load the agent.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the pre-run cost estimate + agent-scoped budget status (cost-guardrails).
		 * Non-fatal: both surfaces simply stay hidden when the requests fail.
		 *
		 * @return {Promise<void>}
		 */
		async loadBudgetInfo() {
			const [estimate, budgetStatus] = await Promise.all([
				getBudgetEstimate(this.agentUuid).catch(() => null),
				getBudgetStatus('', this.agentUuid).catch(() => null),
			])
			this.estimate = estimate
			this.budgetStatus = budgetStatus
		},

		/**
		 * Load the webhook trigger status (agent-webhook-trigger). Non-fatal:
		 * the panel just shows the "no webhook configured" empty state on error.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		async loadWebhookStatus() {
			try {
				this.webhookStatus = await getWebhookStatus(this.agentUuid)
			} catch (e) {
				this.webhookStatus = null
			}
		},

		/**
		 * Create a webhook secret for this agent and reveal it once.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		async createWebhook() {
			this.webhookBusy = true
			try {
				const result = await createWebhookSecret(this.agentUuid)
				this.revealedSecret = result.secret || ''
				this.showWebhookSecretDialog = true
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook created.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not create the webhook.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Rotate this agent's webhook secret and reveal the new one once.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		async rotateWebhook() {
			this.webhookBusy = true
			try {
				const result = await rotateWebhookSecret(this.agentUuid)
				this.revealedSecret = result.secret || ''
				this.showWebhookSecretDialog = true
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook secret rotated.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not rotate the webhook secret.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Revoke this agent's webhook — disables it without deleting its configuration.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		async revokeWebhook() {
			this.webhookBusy = true
			try {
				await revokeWebhookSecret(this.agentUuid)
				await this.loadWebhookStatus()
				showSuccess(this.t('hermiq', 'Webhook revoked.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not revoke the webhook.'))
			} finally {
				this.webhookBusy = false
			}
		},

		/**
		 * Clear the transiently-held plaintext secret once the copy-once dialog is
		 * dismissed — it is never held in memory longer than needed, and the dialog
		 * cannot be reopened to re-reveal it (only a fresh create/rotate yields a
		 * new one).
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/agent-webhook-trigger/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-webhook-trigger-in-place-mvp
		 */
		closeWebhookSecretDialog() {
			this.showWebhookSecretDialog = false
			this.revealedSecret = ''
		},

		/**
		 * Re-fetch just the agent (after a skill attach/detach) so skillInstalls and the
		 * config widget reflect the change without a full page reload.
		 *
		 * @return {Promise<void>}
		 */
		async reloadAgent() {
			const agent = await this.agentStore.fetchObject('agent', this.agentUuid)
			this.agent = agent || this.agent
		},

		/**
		 * Load the tool catalogue for the config widget's #field-tools slot
		 * (/api/agents/tools). Non-fatal: the picker just stays empty on error.
		 *
		 * @return {Promise<void>}
		 */
		async loadTools() {
			this.toolsLoading = true
			try {
				const tools = await listTools()
				this.toolOptions = (Array.isArray(tools) ? tools : []).map((tool) => {
					const value = tool.id || tool.name || tool.key || String(tool)
					const label = tool.name || value
					const description = tool.description ? ` — ${tool.description}` : ''
					return { label: `${label}${description}`, value }
				})
			} catch (e) {
				this.toolOptions = []
			} finally {
				this.toolsLoading = false
			}
		},

		/**
		 * Load the tenant's skills catalogue for the Skills section (non-blocking on error).
		 *
		 * @return {Promise<void>}
		 */
		async loadSkills() {
			this.skillsLoading = true
			try {
				const skills = await listSkills()
				this.skills = Array.isArray(skills) ? skills : []
			} catch (e) {
				this.skills = []
			} finally {
				this.skillsLoading = false
			}
		},

		/**
		 * Map an array of tool ids to the { label, value } options the tools NcSelect
		 * renders as its current selection.
		 *
		 * @param {Array<string>} value The current tool ids.
		 * @return {Array<object>} The matching options (label falls back to the id).
		 */
		toolsToOptions(value) {
			const ids = Array.isArray(value) ? value : []
			return ids.map((id) => this.toolOptions.find((option) => option.value === id) || { label: id, value: id })
		},

		/**
		 * Attach the selected catalogue skill to this agent, then refresh.
		 *
		 * @return {Promise<void>}
		 */
		async attachSkill() {
			if (!this.skillToAttach) {
				return
			}
			this.skillBusy = true
			try {
				await installSkill(this.skillToAttach.value, this.agentUuid)
				this.skillToAttach = null
				await this.reloadAgent()
				showSuccess(this.t('hermiq', 'Skill attached.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not attach the skill.'))
			} finally {
				this.skillBusy = false
			}
		},

		/**
		 * Detach a skill from this agent, then refresh.
		 *
		 * @param {string} skillUuid The Skill UUID to detach.
		 * @return {Promise<void>}
		 */
		async detachSkill(skillUuid) {
			this.skillBusy = true
			try {
				await uninstallSkill(skillUuid, this.agentUuid)
				await this.reloadAgent()
				showSuccess(this.t('hermiq', 'Skill detached.'))
			} catch (e) {
				showError(this.t('hermiq', 'Could not detach the skill.'))
			} finally {
				this.skillBusy = false
			}
		},

		/**
		 * Load the run history for the attached schedule (non-blocking on error).
		 *
		 * @return {Promise<void>}
		 */
		async loadRuns() {
			this.runsError = false
			// A fresh load invalidates any expanded row / cached trace from a
			// previous schedule or a previous run list.
			this.expandedRunId = null
			this.runTraces = {}
			if (!this.schedule || !this.schedule.id) {
				this.runs = []
				return
			}
			try {
				this.runs = await listRuns(this.schedule.id)
			} catch (e) {
				this.runsError = true
				this.runs = []
			}
		},

		/**
		 * Expand/collapse a run's step-timeline row, fetching its trace on first
		 * expand (run-trace-observability) and caching it by run id thereafter.
		 *
		 * @param {object} run The run record (from the Run history list).
		 * @return {Promise<void>}
		 */
		async toggleRunTrace(run) {
			if (this.expandedRunId === run.id) {
				this.expandedRunId = null
				return
			}

			this.expandedRunId = run.id
			if (this.runTraces[run.id] || !this.schedule || !this.schedule.id) {
				return
			}

			this.traceLoading = true
			this.traceError = false
			try {
				const trace = await getRunTrace(this.schedule.id, run.id)
				this.runTraces = { ...this.runTraces, [run.id]: trace }
			} catch (e) {
				this.traceError = true
			} finally {
				this.traceLoading = false
			}
		},

		/**
		 * Save the already-fetched, already-redacted trace for one run as a local
		 * JSON file — no client-side re-redaction or transformation beyond
		 * formatting (run-trace-observability).
		 *
		 * @param {object} run The run record whose trace is currently expanded.
		 * @return {void}
		 */
		downloadTrace(run) {
			const trace = this.runTraces[run.id]
			if (!trace) {
				return
			}

			const blob = new Blob([JSON.stringify(trace, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `run-trace-${run.id}.json`
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			URL.revokeObjectURL(url)
		},

		/**
		 * Human label for a trace step's type.
		 *
		 * @param {string} type The step type (gate_wait|context|history|llm|tool|delivery).
		 * @return {string} The localised label.
		 */
		stepTypeLabel(type) {
			const labels = {
				gate_wait: this.t('hermiq', 'Awaiting approval'),
				context: this.t('hermiq', 'Context'),
				history: this.t('hermiq', 'History'),
				llm: this.t('hermiq', 'LLM'),
				tool: this.t('hermiq', 'Tool'),
				delivery: this.t('hermiq', 'Delivery'),
			}
			return labels[type] || type || '—'
		},

		/**
		 * Human label for a trace step's duration in milliseconds.
		 *
		 * @param {number} ms The duration in milliseconds.
		 * @return {string} The duration label (ms below one second, otherwise seconds).
		 */
		stepDurationLabel(ms) {
			if (ms === null || ms === undefined) {
				return '—'
			}
			const value = Number(ms)
			if (Number.isNaN(value)) {
				return '—'
			}
			if (value < 1000) {
				return `${Math.round(value)}ms`
			}
			return `${(value / 1000).toFixed(1)}s`
		},

		/**
		 * Trigger an immediate run and surface the result or a graceful error state.
		 *
		 * Also used (run-reliability) as the dead-letter row's "Re-run" action: a
		 * dead-lettered occurrence's manual re-run is a fresh, fully governed
		 * dispatch, so it reuses this SAME runScheduleNow() call — no new endpoint.
		 *
		 * @return {Promise<void>}
		 */
		async runNow() {
			if (!this.schedule || !this.schedule.id) {
				return
			}
			this.running = true
			this.runError = ''
			try {
				const result = await runScheduleNow(this.schedule.id)
				if (result && result.status === 'error') {
					// OpenRegister agent-execution error (recorded + kept in run history).
					this.runError = result.error || this.t('hermiq', 'The agent run failed.')
					showError(this.t('hermiq', 'The agent run reported an error.'))
				} else {
					showSuccess(this.t('hermiq', 'Agent run started.'))
				}
			} catch (e) {
				this.runError = e?.response?.data?.message
					|| e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'The agent run failed.')
				showError(this.t('hermiq', 'The agent run failed.'))
			} finally {
				this.running = false
				// Refresh schedule status + run history regardless of outcome.
				await this.load()
			}
		},

		/**
		 * Reload after the agent is edited.
		 *
		 * @return {Promise<void>}
		 */
		async onAgentSaved() {
			await this.load()
		},

		/**
		 * Reload after the schedule is attached/edited.
		 *
		 * @return {Promise<void>}
		 */
		async onScheduleSaved() {
			await this.load()
		},

		/**
		 * Reload after the tool grants are saved (agent-tool-governance-and-disclosure) —
		 * Agent.tools drives the config widget's tools display, so the page must refetch.
		 *
		 * @return {Promise<void>}
		 */
		async onGrantsSaved() {
			await this.load()
		},

		/**
		 * Open the diff dialog for the two versions selected in the version
		 * history dialog (agent-versioning).
		 *
		 * @param {object} selection The selected version ids ({ from, to }).
		 * @return {void}
		 */
		onCompareVersions(selection) {
			this.diffFromId = selection.from
			this.diffToId = selection.to
			this.showVersionDiff = true
		},

		/**
		 * A short, stable display form of a pinned agent-version id
		 * (agent-versioning) — the version id is an opaque AuditTrail entry
		 * UUID, so only its first segment is shown, matching how other short
		 * ids already render on this page (e.g. webhook secret prefixes).
		 *
		 * @param {string} versionId The pinned agent version id (may be absent
		 * on a pre-agent-versioning run).
		 * @return {string} The short label, or a dash when absent.
		 */
		shortVersionLabel(versionId) {
			if (!versionId) {
				return '—'
			}
			return String(versionId).split('-')[0]
		},

		/**
		 * Format an ISO date for display, or a dash when absent/invalid.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The localised date, or '—'.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString()
		},

		/**
		 * Human label for a run duration in milliseconds.
		 *
		 * @param {number} ms The duration in milliseconds.
		 * @return {string} The duration label.
		 */
		durationLabel(ms) {
			if (ms === null || ms === undefined) {
				return '—'
			}
			const seconds = Math.round(Number(ms) / 1000)
			return `${seconds}s`
		},

		/**
		 * Badge CSS modifier class for a run-history status (run-reliability extends
		 * the original error/ok binary with the retry/dead-letter/circuit-breaker
		 * vocabulary). Falls back to the neutral "ok" styling for any other status
		 * (running, skipped_killswitch, awaiting_approval, …) so nothing renders
		 * unstyled.
		 *
		 * @param {string} status The run's status.
		 * @return {string} The `agent-detail__badge--*` modifier class.
		 */
		statusBadgeClass(status) {
			if (status === 'error' || status === 'dead_letter' || status === 'paused_circuit_breaker') {
				return 'agent-detail__badge--error'
			}
			if (status === 'retry_pending' || status === 'awaiting_approval' || status === 'skipped_killswitch' || status === 'skipped_budget') {
				return 'agent-detail__badge--warning'
			}
			return 'agent-detail__badge--ok'
		},

		/**
		 * Human-readable label for a run-history status. Distinguishes each new
		 * run-reliability status by TEXT (not color alone), so the difference is
		 * available to screen readers and keyboard-only users too.
		 *
		 * @param {string} status The run's status.
		 * @return {string} The localised status label.
		 */
		statusLabel(status) {
			const labels = {
				ok: this.t('hermiq', 'OK'),
				error: this.t('hermiq', 'Error'),
				running: this.t('hermiq', 'Running'),
				skipped_killswitch: this.t('hermiq', 'Halted (kill-switch)'),
				skipped_budget: this.t('hermiq', 'Halted (budget)'),
				awaiting_approval: this.t('hermiq', 'Awaiting approval'),
				retry_pending: this.t('hermiq', 'Retrying…'),
				dead_letter: this.t('hermiq', 'Dead-letter'),
				paused_circuit_breaker: this.t('hermiq', 'Paused (circuit breaker)'),
			}
			return labels[status] || status || '—'
		},

		/**
		 * Navigate back to the agent catalog.
		 *
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/')
		},
	},
}
</script>

<style scoped>
.agent-detail {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.agent-detail__back {
	margin-bottom: 8px;
}

.agent-detail__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.agent-detail__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.agent-detail__header-actions {
	display: flex;
	gap: 8px;
}

.agent-detail__title {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.agent-detail__meta {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px 24px;
	margin: 0 0 8px;
}

.agent-detail__estimate {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.agent-detail__estimate--empty {
	font-style: italic;
}

.agent-detail__meta-wide {
	grid-column: 1 / -1;
}

.agent-detail__meta dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.agent-detail__meta dd {
	margin: 0;
	white-space: pre-wrap;
}

.agent-detail__section {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.agent-detail__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 12px;
}

.agent-detail__section-head h3,
.agent-detail__section h3 {
	margin: 0 0 12px;
	font-size: 18px;
	font-weight: 600;
}

.agent-detail__section-actions {
	display: flex;
	gap: 8px;
}

.agent-detail__empty-hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}

.agent-detail__table {
	width: 100%;
	border-collapse: collapse;
}

.agent-detail__table th,
.agent-detail__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-detail__row-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.agent-detail__trace-cell {
	background-color: var(--color-background-hover);
}

.agent-detail__trace {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.agent-detail__trace-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.agent-detail__trace-steps {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-detail__trace-step {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.agent-detail__trace-step-type {
	min-width: 90px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.agent-detail__trace-step-name {
	flex: 1 1 auto;
}

.agent-detail__trace-step-duration {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.agent-detail__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 13px;
}

.agent-detail__badge--ok {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.agent-detail__badge--error {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

/* run-reliability: retry_pending / awaiting_approval / skipped_* — visually AND
   textually distinct from both ok (green) and error (red). */
.agent-detail__badge--warning {
	color: var(--color-warning-text, var(--color-warning));
	font-weight: 600;
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
}

.agent-detail__skill-attach {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	min-width: 320px;
}

.agent-detail__skill-attach .v-select {
	min-width: 240px;
}

.agent-detail__skills {
	list-style: none;
	margin: 0;
	padding: 0;
}

.agent-detail__skill {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.agent-detail__skill-name {
	flex: 1 1 auto;
}
</style>
