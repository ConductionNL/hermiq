<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentVersionDiffDialog — field-level diff between two of an agent's versions
  (agent-versioning).

  Own file per ADR-004 modal-isolation. Reads GET /api/agents/{id}/versions/diff
  (AgentVersionController::diff — a read-only replay over OpenRegister's
  AuditTrail, never persisted). Only fields that actually differ, scoped to the
  fixed versioned-config field allowlist, are ever shown — `name`/`isPrivate`/
  quota fields never appear here even if they happen to differ between the two
  points in time (design.md Decision 4).

  @spec openspec/changes/agent-versioning/tasks.md#task-4-frontend-version-history-diff-and-one-click-rollback-on-agentdetail
  @spec openspec/changes/agent-versioning/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
-->
<template>
	<NcDialog
		:name="t('hermiq', 'Compare versions')"
		:open="show"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="agent-version-diff-dialog">
			<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not load the comparison')">
				{{ error }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :size="32" />

			<p v-else-if="fields.length === 0" class="agent-version-diff-dialog__empty">
				{{ t('hermiq', 'No differences between these two versions.') }}
			</p>

			<dl v-else class="agent-version-diff-dialog__list">
				<template v-for="field in fields" :key="field">
					<dt class="agent-version-diff-dialog__field">
						{{ fieldLabel(field) }}
					</dt>
					<dd class="agent-version-diff-dialog__values">
						<span class="agent-version-diff-dialog__old">
							<span class="agent-version-diff-dialog__tag">{{ t('hermiq', 'Before') }}</span>
							<code>{{ formatValue(diff[field].old) }}</code>
						</span>
						<span class="agent-version-diff-dialog__new">
							<span class="agent-version-diff-dialog__tag">{{ t('hermiq', 'After') }}</span>
							<code>{{ formatValue(diff[field].new) }}</code>
						</span>
					</dd>
				</template>
			</dl>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('hermiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { diffAgentVersions } from '../../api/agents.js'

export default {
	name: 'AgentVersionDiffDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},
		/** The Agent UUID whose versions are being compared. */
		agentId: {
			type: String,
			required: true,
		},
		/** The "before" version id. */
		fromId: {
			type: String,
			default: '',
		},
		/** The "after" version id. */
		toId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			diff: {},
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The changed field names, in the fixed schema order so the list reads
		 * consistently across every comparison.
		 *
		 * @return {Array<string>} The changed field names.
		 */
		fields() {
			return Object.keys(this.diff || {})
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.load()
			}
		},
	},

	methods: {
		/**
		 * Load the diff between fromId and toId.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.fromId || !this.toId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.diff = await diffAgentVersions(this.agentId, this.fromId, this.toId)
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
				this.diff = {}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Human label for a versioned-config field name.
		 *
		 * @param {string} field The field key.
		 * @return {string} The localised label.
		 */
		fieldLabel(field) {
			const labels = {
				prompt: this.t('hermiq', 'Prompt'),
				model: this.t('hermiq', 'Model'),
				provider: this.t('hermiq', 'Provider'),
				temperature: this.t('hermiq', 'Temperature'),
				maxTokens: this.t('hermiq', 'Max tokens'),
				configuration: this.t('hermiq', 'Configuration'),
				tools: this.t('hermiq', 'Tools'),
				skillInstalls: this.t('hermiq', 'Skills'),
				contextRefs: this.t('hermiq', 'Context'),
				enableRag: this.t('hermiq', 'Enable RAG'),
				ragSearchMode: this.t('hermiq', 'RAG search mode'),
				ragNumSources: this.t('hermiq', 'RAG number of sources'),
				ragIncludeFiles: this.t('hermiq', 'RAG include files'),
				ragIncludeObjects: this.t('hermiq', 'RAG include objects'),
				views: this.t('hermiq', 'Views'),
				searchFiles: this.t('hermiq', 'Search files'),
				searchObjects: this.t('hermiq', 'Search objects'),
			}
			return labels[field] || field
		},

		/**
		 * Render a diffed value for display — arrays/objects as compact JSON,
		 * everything else as a string; absent values as a dash.
		 *
		 * @param {*} value The value to render.
		 * @return {string} The display string.
		 */
		formatValue(value) {
			if (value === null || value === undefined || value === '') {
				return '—'
			}
			if (typeof value === 'object') {
				try {
					return JSON.stringify(value)
				} catch (e) {
					return String(value)
				}
			}
			return String(value)
		},

		/**
		 * Close the dialog when NcDialog reports its open state changed to false.
		 *
		 * @param {boolean} open The new open state.
		 * @return {void}
		 */
		onUpdateOpen(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.agent-version-diff-dialog__empty {
	color: var(--color-text-maxcontrast);
}

.agent-version-diff-dialog__list {
	display: grid;
	grid-template-columns: 160px 1fr;
	gap: 8px 16px;
	margin: 0;
}

.agent-version-diff-dialog__field {
	font-weight: 600;
	align-self: start;
	padding-top: 4px;
}

.agent-version-diff-dialog__values {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-version-diff-dialog__old,
.agent-version-diff-dialog__new {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.agent-version-diff-dialog__old code {
	text-decoration: line-through;
	color: var(--color-error-text, var(--color-error));
}

.agent-version-diff-dialog__new code {
	color: var(--color-success-text, var(--color-success));
}

.agent-version-diff-dialog__tag {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	min-width: 48px;
}
</style>
