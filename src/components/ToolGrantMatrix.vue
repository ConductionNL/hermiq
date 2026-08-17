<!--
  Tool grants as a MATRIX rather than a list.

  The flat list it replaces asked a person to find 202 tool ids by eye and tick
  them one at a time, with the current selection shown as a separate bag of
  chips that had to be reconciled with the list by reading. Rights are not
  shaped like a list — they are shaped like (cluster, subject, verb) — so the
  editor is a table: one foldout per cluster, one row per subject, one checkbox
  column per verb.

  The hierarchy comes from OpenRegister's own model, not from parsing tool
  names: a schema names its owning `application` (or inherits its register's),
  which is the cluster, and the schema itself is the subject. Measured on the
  dev instance, 1,085 of 2,000 schemas name their own app and 915 inherit one.

  ⚠️ Two populations live here and only one is schema-shaped. Hand-written tools
  (`hermiq_sendMail`, `delegateAgent`) have no schema and no CRUD verb, so their
  clusters render one column per VERB THEY ACTUALLY HAVE instead of an empty
  CRUD grid — a row of four unticked boxes under a tool that has no create,
  read, update or delete would be four lies.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="grant-matrix">
		<div v-if="loading" class="grant-matrix__state">
			<NcLoadingIcon :size="32" />
			<p>{{ t('hermiq', 'Loading tools…') }}</p>
		</div>

		<NcEmptyContent
			v-else-if="error"
			:name="t('hermiq', 'Tools could not be loaded')"
			:description="error" />

		<template v-else>
			<div class="grant-matrix__toolbar">
				<NcTextField
					v-model="filter"
					:label="t('hermiq', 'Filter tools')"
					:placeholder="t('hermiq', 'Filter by cluster, subject or tool')"
					trailingButtonIcon="close"
					:showTrailingButton="filter !== ''"
					class="grant-matrix__filter"
					@trailingButtonClick="filter = ''" />

				<NcCheckboxRadioSwitch
					v-model="grantedOnly"
					type="checkbox"
					class="grant-matrix__granted-filter">
					{{ t('hermiq', 'Only granted') }}
				</NcCheckboxRadioSwitch>

				<span class="grant-matrix__count">
					{{ n('hermiq', '%n right granted', '%n rights granted', grantedCount) }}
					·
					{{ n('hermiq', '%n tool', '%n tools', totalTools) }}
				</span>
			</div>

			<NcEmptyContent
				v-if="visibleClusters.length === 0"
				:name="t('hermiq', 'Nothing matches')"
				:description="grantedOnly
					? t('hermiq', 'No granted tool matches this filter.')
					: t('hermiq', 'No tool matches this filter.')" />

			<div
				v-for="cluster in visibleClusters"
				:key="cluster.id"
				class="grant-matrix__cluster">
				<button
					type="button"
					class="grant-matrix__cluster-header"
					:aria-expanded="isOpen(cluster.id) ? 'true' : 'false'"
					:aria-controls="`${uid}-cluster-${cluster.id}`"
					@click="toggleCluster(cluster.id)">
					<ChevronDownIcon
						:size="20"
						class="grant-matrix__chevron"
						:class="{ 'grant-matrix__chevron--open': isOpen(cluster.id) }" />
					<span class="grant-matrix__cluster-name">{{ cluster.label }}</span>
					<span class="grant-matrix__cluster-meta">
						{{ n('hermiq', '%n subject', '%n subjects', cluster.rows.length) }}
						<template v-if="cluster.grantedCount > 0">
							· {{ t('hermiq', '{count} granted', { count: cluster.grantedCount }) }}
						</template>
					</span>
				</button>

				<div
					v-show="isOpen(cluster.id)"
					:id="`${uid}-cluster-${cluster.id}`"
					class="grant-matrix__table-wrap">
					<table class="grant-matrix__table">
						<thead>
							<tr>
								<th scope="col" class="grant-matrix__subject-head">
									{{ t('hermiq', 'Subject') }}
								</th>
								<th
									v-for="verb in cluster.verbs"
									:key="verb"
									scope="col"
									class="grant-matrix__verb-head">
									{{ verbLabel(verb) }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in cluster.rows" :key="row.id">
								<th scope="row" class="grant-matrix__subject">
									<span class="grant-matrix__subject-name">{{ row.label }}</span>
									<span v-if="row.description" class="grant-matrix__subject-desc">
										{{ row.description }}
									</span>
								</th>
								<td
									v-for="verb in cluster.verbs"
									:key="verb"
									class="grant-matrix__cell">
									<span v-if="!row.tools[verb]" class="grant-matrix__absent" aria-hidden="true">—</span>

									<!--
										A wildcard grant is a THIRD state, not a ticked box.
										It is held, but not by this checkbox: unticking would
										write an exact-id grant list that silently drops every
										other tool the wildcard covered. Showing it as an
										ordinary tick would invite exactly that.
									-->
									<span
										v-else-if="row.tools[verb].wildcard"
										class="grant-matrix__wildcard"
										:title="t('hermiq', 'Granted by the wildcard {grant}. Edit that grant to change it.', { grant: row.tools[verb].grantedBy })">
										<AsteriskIcon :size="16" />
										<span class="hidden-visually">
											{{ t('hermiq', 'Granted via wildcard') }}
										</span>
									</span>

									<NcCheckboxRadioSwitch
										v-else
										:modelValue="isDrafted(row.tools[verb].id)"
										type="checkbox"
										:disabled="!canEdit || saving"
										:aria-label="ariaFor(cluster, row, verb)"
										@update:modelValue="toggleTool(row.tools[verb].id, $event)" />
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import {
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import AsteriskIcon from 'vue-material-design-icons/Asterisk.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import { getToolCatalog, updateToolGrants } from '../api/toolOversight.js'
import { getToolTaxonomy } from '../api/toolTaxonomy.js'

let matrixUid = 0

// The canonical verbs, in the order a person reads them. A cluster whose tools
// use none of these gets its own verbs instead — see buildClusters().
const CRUD = ['create', 'read', 'update', 'delete']

export default {
	name: 'ToolGrantMatrix',

	components: {
		AsteriskIcon,
		ChevronDownIcon,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		agentId: {
			type: String,
			required: true,
		},

		canEdit: {
			type: Boolean,
			default: false,
		},

		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['dirtyChanged'],

	data() {
		return {
			catalog: null,
			taxonomy: [],
			draftGrants: [],
			savedGrants: [],
			loading: true,
			error: '',
			filter: '',
			grantedOnly: false,
			openClusters: {},
			uid: `grant-matrix-${++matrixUid}`,
		}
	},

	computed: {
		/**
		 * Every tool, joined with its taxonomy and grant state.
		 *
		 * The two endpoints answer different halves: the catalogue knows what
		 * this agent HOLDS, the taxonomy knows where each tool BELONGS. Joined
		 * on the tool id rather than merged server-side so neither endpoint
		 * grows a dependency on the other.
		 *
		 * @return {Array<object>} The joined tools.
		 */
		tools() {
			const taxonomyById = new Map(
				this.taxonomy.map((entry) => [entry.name, entry]),
			)

			return (this.catalog?.tools ?? []).map((tool) => {
				const taxonomy = taxonomyById.get(tool.id) ?? {}
				// A tool granted by something OTHER than its own id is held
				// through a wildcard or verb subset. That is a different kind of
				// grant and must not render as an ordinary tick.
				const wildcard = tool.granted === true
					&& typeof tool.grantedBy === 'string'
					&& tool.grantedBy !== tool.id

				return {
					id: tool.id,
					description: tool.description ?? '',
					app: taxonomy.app ?? null,
					subject: taxonomy.group ?? tool.id,
					verb: taxonomy.right ?? 'special',
					granted: tool.granted === true,
					grantedBy: tool.grantedBy,
					wildcard,
				}
			})
		},

		/**
		 * Tools surviving the text and granted filters.
		 *
		 * @return {Array<object>} The filtered tools.
		 */
		filteredTools() {
			const needle = this.filter.trim().toLowerCase()

			return this.tools.filter((tool) => {
				if (this.grantedOnly === true
					&& this.isDrafted(tool.id) === false
					&& tool.wildcard === false) {
					return false
				}

				if (needle === '') {
					return true
				}

				return [tool.id, tool.app, tool.subject, tool.description]
					.filter(Boolean)
					.some((field) => String(field).toLowerCase().includes(needle))
			})
		},

		/**
		 * The clusters to render, each with its own verb columns and rows.
		 *
		 * @return {Array<object>} The clusters.
		 */
		visibleClusters() {
			return this.buildClusters(this.filteredTools)
		},

		/**
		 * How many rights the draft currently holds.
		 *
		 * @return {number} The count.
		 */
		grantedCount() {
			return this.draftGrants.length
		},

		/**
		 * How many tools exist in total, before filtering.
		 *
		 * @return {number} The count.
		 */
		totalTools() {
			return this.tools.length
		},

		/**
		 * Whether the draft differs from what is saved.
		 *
		 * @return {boolean} True when there are unsaved changes.
		 */
		dirty() {
			if (this.draftGrants.length !== this.savedGrants.length) {
				return true
			}

			const saved = new Set(this.savedGrants)
			return this.draftGrants.some((grant) => saved.has(grant) === false)
		},
	},

	watch: {
		agentId: {
			handler() {
				this.load()
			},
		},

		dirty: {
			immediate: true,
			handler(value) {
				this.$emit('dirtyChanged', value)
			},
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Group tools into clusters, rows and verb columns.
		 *
		 * @param {Array<object>} tools The tools to group.
		 * @return {Array<object>} The clusters.
		 */
		buildClusters(tools) {
			const clusters = new Map()

			for (const tool of tools) {
				// A tool with no owning app cannot be filed under one. It gets an
				// explicit "no application" cluster rather than being dropped —
				// a right that exists but is invisible is worse than an ugly heading.
				const id = tool.app ?? '__none__'
				if (clusters.has(id) === false) {
					clusters.set(id, { id, tools: [] })
				}
				clusters.get(id).tools.push(tool)
			}

			return [...clusters.values()]
				.map((cluster) => this.shapeCluster(cluster))
				.sort((a, b) => a.label.localeCompare(b.label))
		},

		/**
		 * Give one cluster its verb columns and subject rows.
		 *
		 * ⚠️ A cluster whose tools use none of the CRUD verbs gets a column per
		 * verb it ACTUALLY has. Rendering an empty create/read/update/delete
		 * grid for `sendMail` and `delegateAgent` would show four boxes that can
		 * never be ticked and say nothing true about the tool.
		 *
		 * @param {object} cluster The raw cluster.
		 * @return {object} The shaped cluster.
		 */
		shapeCluster(cluster) {
			const present = new Set(cluster.tools.map((tool) => tool.verb))
			const crud = CRUD.filter((verb) => present.has(verb))
			const extra = [...present].filter((verb) => CRUD.includes(verb) === false).sort()
			const verbs = crud.length > 0 ? [...crud, ...extra] : extra

			const rows = new Map()
			for (const tool of cluster.tools) {
				if (rows.has(tool.subject) === false) {
					rows.set(tool.subject, {
						id: tool.subject,
						label: tool.subject,
						description: '',
						tools: {},
					})
				}

				const row = rows.get(tool.subject)
				row.tools[tool.verb] = tool
				if (row.description === '' && Object.keys(row.tools).length === 1) {
					row.description = tool.description
				}
			}

			const shapedRows = [...rows.values()].sort((a, b) => a.label.localeCompare(b.label))

			const label = cluster.id === '__none__'
				? t('hermiq', 'No application')
				: cluster.id
			const grantedCount = cluster.tools.filter(
				(tool) => this.isDrafted(tool.id) || tool.wildcard,
			).length

			return { id: cluster.id, label, verbs, rows: shapedRows, grantedCount }
		},

		/**
		 * A readable label for a verb column.
		 *
		 * @param {string} verb The verb.
		 * @return {string} The label.
		 */
		verbLabel(verb) {
			const labels = {
				create: t('hermiq', 'Create'),
				read: t('hermiq', 'Read'),
				update: t('hermiq', 'Update'),
				delete: t('hermiq', 'Delete'),
				special: t('hermiq', 'Special'),
			}

			return labels[verb] ?? verb
		},

		/**
		 * The accessible name for one checkbox. A bare "create" repeated down a
		 * column tells a screen-reader user nothing about WHAT is being granted.
		 *
		 * @param {object} cluster The cluster.
		 * @param {object} row The subject row.
		 * @param {string} verb The verb.
		 * @return {string} The label.
		 */
		ariaFor(cluster, row, verb) {
			return t('hermiq', 'Grant {verb} on {subject} in {cluster}', {
				verb: this.verbLabel(verb),
				subject: row.label,
				cluster: cluster.label,
			})
		},

		/**
		 * Whether a cluster foldout is open.
		 *
		 * @param {string} id The cluster id.
		 * @return {boolean} True when open.
		 */
		isOpen(id) {
			return this.openClusters[id] === true
		},

		/**
		 * Toggle a cluster foldout.
		 *
		 * @param {string} id The cluster id.
		 * @return {void}
		 */
		toggleCluster(id) {
			this.openClusters = {
				...this.openClusters,
				[id]: this.isOpen(id) === false,
			}
		},

		/**
		 * Whether the draft grants hold this exact tool id.
		 *
		 * @param {string} id The tool id.
		 * @return {boolean} True when drafted.
		 */
		isDrafted(id) {
			return this.draftGrants.includes(id)
		},

		/**
		 * Add or remove one exact tool grant.
		 *
		 * @param {string} id The tool id.
		 * @param {boolean} checked The new state.
		 * @return {void}
		 */
		toggleTool(id, checked) {
			if (checked === true) {
				if (this.isDrafted(id) === false) {
					this.draftGrants = [...this.draftGrants, id]
				}
				return
			}

			this.draftGrants = this.draftGrants.filter((grant) => grant !== id)
		},

		/**
		 * Load the catalogue and the taxonomy together.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [catalog, taxonomy] = await Promise.all([
					getToolCatalog(this.agentId),
					getToolTaxonomy(),
				])

				this.catalog = catalog
				this.taxonomy = taxonomy

				// The authoritative grant list is whatever produced the
				// annotations — dedupe grantedBy rather than reconstructing ids.
				const granted = catalog.tools
					.filter((tool) => tool.granted && tool.grantedBy)
					.map((tool) => tool.grantedBy)

				this.savedGrants = [...new Set(granted)].filter(
					(grant) => !grant.startsWith('('),
				)
				this.draftGrants = [...this.savedGrants]

				// Open the clusters that already hold something, so the widget
				// opens showing what this agent HAS rather than a wall of
				// collapsed headings.
				const open = {}
				for (const cluster of this.buildClusters(this.tools)) {
					if (cluster.grantedCount > 0) {
						open[cluster.id] = true
					}
				}
				this.openClusters = open
			} catch (error) {
				this.error = error.response?.data?.error ?? error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the draft. Called by the parent widget's title-bar button.
		 *
		 * @return {Promise<Array<string>>} The saved grants.
		 */
		async persist() {
			await updateToolGrants(this.agentId, this.draftGrants)
			const saved = [...this.draftGrants]
			await this.load()
			return saved
		},

		/**
		 * Discard unsaved changes. Called by the parent widget's title bar.
		 *
		 * @return {void}
		 */
		reset() {
			this.draftGrants = [...this.savedGrants]
		},
	},
}
</script>

<style scoped>
.grant-matrix {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
	overflow-y: auto;
}

.grant-matrix__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
}

.grant-matrix__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.grant-matrix__filter {
	flex: 1 1 220px;
	min-width: 180px;
}

.grant-matrix__granted-filter {
	flex: 0 0 auto;
}

.grant-matrix__count {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.grant-matrix__cluster {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.grant-matrix__cluster-header {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border: none;
	cursor: pointer;
	text-align: start;
	font-size: inherit;
}

.grant-matrix__cluster-header:hover {
	background-color: var(--color-background-dark);
}

.grant-matrix__chevron {
	transition: transform 0.15s ease;
	flex: 0 0 auto;
}

.grant-matrix__chevron--open {
	transform: rotate(180deg);
}

.grant-matrix__cluster-name {
	font-weight: bold;
}

.grant-matrix__cluster-meta {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
}

/* Wide matrices scroll in their own box; the widget must never scroll sideways. */
.grant-matrix__table-wrap {
	overflow-x: auto;
}

.grant-matrix__table {
	width: 100%;
	border-collapse: collapse;
}

.grant-matrix__table th,
.grant-matrix__table td {
	padding: 6px 12px;
	border-block-start: 1px solid var(--color-border);
	text-align: start;
}

.grant-matrix__verb-head {
	text-align: center;
	font-size: 0.8em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.grant-matrix__subject-head {
	font-size: 0.8em;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.grant-matrix__subject {
	font-weight: normal;
	max-width: 320px;
}

.grant-matrix__subject-name {
	display: block;
	font-family: var(--font-face-monospace, monospace);
}

.grant-matrix__subject-desc {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.grant-matrix__cell {
	text-align: center;
	vertical-align: middle;
}

.grant-matrix__cell :deep(.checkbox-radio-switch) {
	justify-content: center;
}

.grant-matrix__absent {
	color: var(--color-text-maxcontrast);
	opacity: 0.5;
}

.grant-matrix__wildcard {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--color-primary-element);
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
}
</style>
