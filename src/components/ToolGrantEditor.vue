<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ToolGrantEditor — the per-agent schema-scoped tool grant editor
  (agent-tool-governance-and-disclosure).

  Renders the grant-annotated ADR-063 derived catalogue (GET .../tool-catalog): every tool
  the agent COULD be granted, with its scope (read/write), whether it is currently granted,
  and by WHICH grant entry. Write/destructive tools that are NOT granted render with a
  distinct "requires explicit grant" affordance (a warning-styled badge, never colour-only —
  the text label carries the meaning) so the default-deny is VISIBLE, never silent.

  Grants are edited as the raw Agent.tools string[] through an NcSelect with taggable
  free-text entry (the grammar is open-ended: exact ids, {app}.{schema}.*, verb subsets, and
  the :write modifier — no static enum can cover it), plus one-click "Grant"/"Revoke"
  shortcuts per catalogue row that append/remove the exact id. Saving PUTs the array to
  .../tool-grants (owner-only server-side; the editor is read-only for a non-owner).

  Also surfaces whether progressive disclosure is ACTIVE for this agent (resolved count vs
  the configured threshold) so an operator understands why the model may not see every tool
  in context.

  @spec openspec/changes/agent-tool-governance-and-disclosure/tasks.md#task-6
  @spec openspec/changes/agent-tool-governance-and-disclosure/specs/agent-tool-governance/spec.md#requirement-schema-scoped-whitelist-grants-with-default-deny-for-writedestructive-tools
-->
<template>
	<section class="tool-grants">
		<h3 class="tool-grants__title">
			{{ t('hermiq', 'Tool grants') }}
		</h3>

		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Could not load the tool catalogue')">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="tool-grants__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<template v-else-if="catalog">
			<NcNoteCard v-if="catalog.disclosureActive" type="info">
				{{ disclosureMessage }}
			</NcNoteCard>

			<p class="tool-grants__hint">
				{{ t('hermiq', 'A schema wildcard ({app}.{schema}.*) grants read verbs only (search, get). Write and destructive tools (create, update, delete) must be named explicitly, or granted with the :write modifier ({app}.{schema}.*:write).') }}
			</p>

			<NcSelect
				v-model="draftGrants"
				class="tool-grants__select"
				:input-label="t('hermiq', 'Grants')"
				:placeholder="t('hermiq', 'Add a grant — an exact tool id, a {app}.{schema}.* wildcard, or a {app}.{schema}.*:write modifier')"
				:options="grantSuggestions"
				:disabled="!canEdit || saving"
				:multiple="true"
				:taggable="true"
				:close-on-select="false" />

			<div class="tool-grants__actions">
				<NcButton
					type="primary"
					:disabled="!canEdit || saving || !dirty"
					@click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('hermiq', 'Save grants') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:disabled="!canEdit || saving || !dirty"
					@click="reset">
					{{ t('hermiq', 'Reset') }}
				</NcButton>
			</div>

			<p v-if="!canEdit" class="tool-grants__readonly">
				{{ t('hermiq', 'Only the agent owner can change tool grants.') }}
			</p>

			<NcEmptyContent
				v-if="!catalog.tools.length"
				:name="t('hermiq', 'No tools discovered')"
				:description="t('hermiq', 'No app has exposed any MCP tool to this instance yet.')">
				<template #icon>
					<ToolboxOutline :size="20" />
				</template>
			</NcEmptyContent>

			<template v-else>
				<!--
					The catalogue is instance-wide, so it grows with every app that
					exposes MCP tools — this instance already resolves ~100 tools,
					which rendered as a 6,600px table that spilled straight out of
					the widget's grid cell and over everything below it. The rows
					stay in the DOM (they're the same content); they're just bounded
					by a scroll region with a sticky header, and filterable, because
					100 unfiltered rows behind a scrollbar is not a way to find one
					tool.
				-->
				<div class="tool-grants__filter">
					<NcTextField
						v-model="filter"
						class="tool-grants__filter-input"
						:label="t('hermiq', 'Filter tools')" />
					<span class="tool-grants__count">
						{{ n('hermiq', '%n tool', '%n tools', visibleTools.length) }}
						<template v-if="filter !== ''">
							{{ t('hermiq', 'of {total}', { total: catalog.tools.length }) }}
						</template>
					</span>
				</div>

				<NcEmptyContent
					v-if="!visibleTools.length"
					:name="t('hermiq', 'No tool matches this filter')">
					<template #icon>
						<ToolboxOutline :size="20" />
					</template>
				</NcEmptyContent>

				<div v-else class="tool-grants__table-wrap">
					<table class="tool-grants__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('hermiq', 'Tool') }}
								</th>
								<th scope="col">
									{{ t('hermiq', 'Scope') }}
								</th>
								<th scope="col">
									{{ t('hermiq', 'Status') }}
								</th>
								<th scope="col">
									{{ t('hermiq', 'Granted by') }}
								</th>
								<th scope="col">
									{{ t('hermiq', 'Action') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="tool in visibleTools" :key="tool.id">
								<td>
									<span class="tool-grants__id">{{ tool.id }}</span>
									<span v-if="tool.description" class="tool-grants__desc">{{ tool.description }}</span>
								</td>
								<td>
									<span :class="scopeBadgeClass(tool)">{{ scopeLabel(tool) }}</span>
								</td>
								<td>
									<span v-if="tool.granted" class="tool-grants__badge tool-grants__badge--granted">
										{{ t('hermiq', 'Granted') }}
									</span>
									<span
										v-else-if="tool.requiresExplicitGrant"
										class="tool-grants__badge tool-grants__badge--explicit">
										{{ t('hermiq', 'Requires explicit grant') }}
									</span>
									<span v-else class="tool-grants__badge tool-grants__badge--denied">
										{{ t('hermiq', 'Not granted') }}
									</span>
								</td>
								<td>{{ tool.grantedBy || '—' }}</td>
								<td>
									<NcButton
										v-if="!tool.granted"
										type="tertiary"
										:disabled="!canEdit || saving"
										@click="grantExact(tool)">
										{{ t('hermiq', 'Grant') }}
									</NcButton>
									<NcButton
										v-else-if="isExactlyGranted(tool)"
										type="tertiary"
										:disabled="!canEdit || saving"
										@click="revokeExact(tool)">
										{{ t('hermiq', 'Revoke') }}
									</NcButton>
									<span v-else class="tool-grants__via-wildcard">
										{{ t('hermiq', 'via wildcard') }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>
		</template>
	</section>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import ToolboxOutline from 'vue-material-design-icons/ToolboxOutline.vue'
import { getToolCatalog, updateToolGrants } from '../api/toolOversight.js'

export default {
	name: 'ToolGrantEditor',

	components: {
		ContentSave,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		ToolboxOutline,
	},

	props: {
		/** The agent UUID whose grants are edited. */
		agentId: {
			type: String,
			required: true,
		},
		/** Whether the current user may edit (owner-only; the server enforces it too). */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['saved'],

	data() {
		return {
			catalog: null,
			draftGrants: [],
			savedGrants: [],
			loading: true,
			saving: false,
			error: '',
			filter: '',
		}
	},

	computed: {
		/**
		 * The catalogue rows matching the filter, which matches on tool id and
		 * description so both `openregister.agent.*` and "create a new page"
		 * find their tool.
		 *
		 * @return {Array<object>} The tools to render.
		 */
		visibleTools() {
			const tools = this.catalog?.tools ?? []
			const needle = this.filter.trim().toLowerCase()
			if (needle === '') {
				return tools
			}
			return tools.filter((tool) =>
				tool.id.toLowerCase().includes(needle)
				|| (tool.description || '').toLowerCase().includes(needle),
			)
		},

		/**
		 * Whether the draft grant list differs from the last-saved one.
		 *
		 * @return {boolean} True when there are unsaved changes.
		 */
		dirty() {
			if (this.draftGrants.length !== this.savedGrants.length) {
				return true
			}
			return this.draftGrants.some((grant, index) => grant !== this.savedGrants[index])
		},

		/**
		 * Suggested grant strings for the NcSelect: every catalogue id plus a read
		 * wildcard and a :write wildcard per distinct {app}.{schema} prefix. The select
		 * stays taggable so an operator can still type a grant we did not suggest.
		 *
		 * @return {Array<string>} The suggestion list.
		 */
		grantSuggestions() {
			if (!this.catalog) {
				return []
			}
			const suggestions = new Set()
			for (const tool of this.catalog.tools) {
				suggestions.add(tool.id)
				const parts = tool.id.split('.')
				if (parts.length === 3) {
					const prefix = `${parts[0]}.${parts[1]}`
					suggestions.add(`${prefix}.*`)
					suggestions.add(`${prefix}.*:write`)
				}
			}
			return [...suggestions].sort()
		},

		/**
		 * The progressive-disclosure explanation shown when disclosure is active.
		 *
		 * @return {string} The message.
		 */
		disclosureMessage() {
			return this.t(
				'hermiq',
				'Progressive disclosure is active: this agent resolves {resolvedCount} tools, above the threshold of {threshold}. The model is shown a single search tool instead of every descriptor, and loads the ones it selects on demand.',
				{
					resolvedCount: this.catalog.resolvedCount,
					threshold: this.catalog.disclosureThreshold,
				},
			)
		},
	},

	watch: {
		agentId: {
			handler() {
				this.load()
			},
			immediate: true,
		},
	},

	methods: {
		/**
		 * Load the grant-annotated catalogue for this agent.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const catalog = await getToolCatalog(this.agentId)
				this.catalog = catalog
				const granted = catalog.tools
					.filter((tool) => tool.granted && tool.grantedBy)
					.map((tool) => tool.grantedBy)
				// The authoritative grant list is whatever produced the annotations —
				// dedupe the grantedBy entries rather than reconstructing the ids.
				this.savedGrants = [...new Set(granted)].filter((grant) => !grant.startsWith('('))
				this.draftGrants = [...this.savedGrants]
			} catch (error) {
				this.error = (error.response && error.response.data && error.response.data.error) || error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the draft grants, then reload the annotated catalogue.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			try {
				await updateToolGrants(this.agentId, this.draftGrants)
				showSuccess(this.t('hermiq', 'Tool grants saved'))
				this.$emit('saved', [...this.draftGrants])
				await this.load()
			} catch (error) {
				showError((error.response && error.response.data && error.response.data.error) || error.message)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Discard unsaved draft changes.
		 *
		 * @return {void}
		 */
		reset() {
			this.draftGrants = [...this.savedGrants]
		},

		/**
		 * Append an exact-id grant for a tool (the explicit grant a write/destructive
		 * tool needs — default-deny never grants it via a wildcard).
		 *
		 * @param {object} tool The catalogue row.
		 * @return {void}
		 */
		grantExact(tool) {
			if (!this.draftGrants.includes(tool.id)) {
				this.draftGrants = [...this.draftGrants, tool.id]
			}
		},

		/**
		 * Remove a tool's exact-id grant.
		 *
		 * @param {object} tool The catalogue row.
		 * @return {void}
		 */
		revokeExact(tool) {
			this.draftGrants = this.draftGrants.filter((grant) => grant !== tool.id)
		},

		/**
		 * Whether a tool is granted by its own exact id (rather than via a wildcard) —
		 * only an exact grant is revocable from its own row.
		 *
		 * @param {object} tool The catalogue row.
		 * @return {boolean} True when granted by exact id.
		 */
		isExactlyGranted(tool) {
			return this.draftGrants.includes(tool.id)
		},

		/**
		 * The scope badge's class (never colour-only — scopeLabel carries the meaning).
		 *
		 * @param {object} tool The catalogue row.
		 * @return {string} The badge class.
		 */
		scopeBadgeClass(tool) {
			return tool.destructiveHint
				? 'tool-grants__badge tool-grants__badge--write'
				: 'tool-grants__badge tool-grants__badge--read'
		},

		/**
		 * The scope badge's text label.
		 *
		 * @param {object} tool The catalogue row.
		 * @return {string} The label.
		 */
		scopeLabel(tool) {
			return tool.destructiveHint ? this.t('hermiq', 'Write') : this.t('hermiq', 'Read')
		},
	},
}
</script>

<style scoped>
.tool-grants {
	margin-block: 24px;
}

.tool-grants__title {
	margin-block-end: 8px;
}

.tool-grants__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}

.tool-grants__select {
	width: 100%;
	max-width: 640px;
}

.tool-grants__actions {
	display: flex;
	gap: 8px;
	margin-block: 12px;
}

.tool-grants__readonly {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}

.tool-grants__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.tool-grants__filter {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-block: 12px;
	max-width: 640px;
}

.tool-grants__filter-input {
	flex: 1 1 auto;
	min-width: 0;
}

.tool-grants__count {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

/*
 * The catalogue is instance-wide and unbounded (~100 rows here), so it must
 * scroll inside the widget rather than push the page's grid apart.
 */
.tool-grants__table-wrap {
	/*
	 * 300px, not more: this section shares its grid cell with the invocation
	 * audit table, whose own bounded region has to fit alongside it. Both caps
	 * plus the surrounding chrome are what make the cell's gridHeight (14)
	 * hold in the WORST case — a populated audit trail — instead of only when
	 * the trail happens to be empty.
	 */
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.tool-grants__table {
	width: 100%;
	border-collapse: collapse;
}

.tool-grants__table th,
.tool-grants__table td {
	text-align: start;
	padding: 8px;
	border-block-end: 1px solid var(--color-border);
	vertical-align: top;
}

/* Sticky header — the column meaning must survive scrolling the rows. */
.tool-grants__table thead th {
	position: sticky;
	inset-block-start: 0;
	z-index: 1;
	background-color: var(--color-main-background);
}

.tool-grants__id {
	display: block;
	font-family: monospace;
}

.tool-grants__desc {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.tool-grants__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	white-space: nowrap;
}

.tool-grants__badge--read {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.tool-grants__badge--write {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.tool-grants__badge--granted {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.tool-grants__badge--explicit {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.tool-grants__badge--denied {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.tool-grants__via-wildcard {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
