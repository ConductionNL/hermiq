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
					{{
						n(
							'hermiq',
							'%n right granted',
							'%n rights granted',
							grantedCount,
						)
					}}
					·
					{{ n('hermiq', '%n tool', '%n tools', totalTools) }}
				</span>
			</div>

			<NcEmptyContent
				v-if="visibleClusters.length === 0"
				:name="t('hermiq', 'Nothing matches')"
				:description="
					grantedOnly
						? t('hermiq', 'No granted tool matches this filter.')
						: t('hermiq', 'No tool matches this filter.')
				" />

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
						:class="{
							'grant-matrix__chevron--open': isOpen(cluster.id),
						}" />
					<span class="grant-matrix__cluster-name">{{
						cluster.label
					}}</span>
					<span class="grant-matrix__cluster-meta">
						{{
							n(
								'hermiq',
								'%n subject',
								'%n subjects',
								cluster.rows.length,
							)
						}}
						<template v-if="cluster.grantedCount > 0">
							·
							{{
								t('hermiq', '{count} granted', {
									count: cluster.grantedCount,
								})
							}}
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
									<span class="grant-matrix__subject-name">{{
										row.label
									}}</span>
									<span
										v-if="row.description"
										class="grant-matrix__subject-desc">
										{{ row.description }}
									</span>
								</th>
								<td
									v-for="verb in cluster.verbs"
									:key="verb"
									class="grant-matrix__cell"
									:class="{
										'grant-matrix__cell--special':
											verb === 'special',
									}">
									<!--
										The SPECIAL column holds named actions, not one
										anonymous tick. `sendMail` and `delegateAgent` are
										different rights, so each carries its own label and
										its description on hover — a bare checkbox under a
										"SPECIAL" heading grants something the reader cannot
										identify.
									-->
									<template v-if="verb === 'special'">
										<span
											v-if="row.specials.length === 0"
											class="grant-matrix__absent"
											aria-hidden="true"
											>—</span
										>
										<span
											v-for="special in row.specials"
											:key="special.id"
											class="grant-matrix__special"
											:title="special.description">
											<span
												class="grant-matrix__special-name"
												>{{
													special.specialLabel
													|| special.id
												}}</span
											>
											<AsteriskIcon
												v-if="special.wildcard"
												:size="16"
												class="grant-matrix__wildcard" />
											<NcCheckboxRadioSwitch
												v-else
												:modelValue="isDrafted(special.id)"
												type="checkbox"
												:disabled="!canEdit || saving"
												:aria-label="
													t(
														'hermiq',
														'Grant {action} on {subject}',
														{
															action:
																special.specialLabel
																|| special.id,
															subject: row.label,
														},
													)
												"
												@update:modelValue="
													toggleTool(special.id, $event)
												" />
										</span>
									</template>

									<span
										v-else-if="!row.tools[verb]"
										class="grant-matrix__absent"
										aria-hidden="true"
										>—</span
									>

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
										:title="
											t(
												'hermiq',
												'Granted by the wildcard {grant}. Edit that grant to change it.',
												{ grant: row.tools[verb].grantedBy },
											)
										">
										<AsteriskIcon :size="16" />
										<span class="hidden-visually">
											{{ t('hermiq', 'Granted via wildcard') }}
										</span>
									</span>

									<span v-else class="grant-matrix__grant">
										<NcCheckboxRadioSwitch
											:modelValue="
												isDrafted(row.tools[verb].id)
											"
											type="checkbox"
											:disabled="!canEdit || saving"
											:aria-label="ariaFor(cluster, row, verb)"
											@update:modelValue="
												toggleTool(
													row.tools[verb].id,
													$event,
												)
											" />
										<!--
											The write/destructive marker is TEXT, not a
											colour and not an icon alone (WCAG 1.4.1). It
											is the only place an operator learns that this
											particular tick grants something that changes
											or deletes data.
										-->
										<span
											v-if="
												classificationLabel(row.tools[verb])
												!== ''
											"
											class="grant-matrix__reach"
											:class="{
												'grant-matrix__reach--destructive':
													row.tools[verb].destructive,
											}"
											:title="
												classificationLabel(row.tools[verb])
											"
											aria-hidden="true">
											{{
												row.tools[verb].destructive
													? t('hermiq', 'del')
													: t('hermiq', 'write')
											}}
										</span>
									</span>
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
import { getAgentGrants, getToolTaxonomy } from '../api/toolTaxonomy.js'
// 🔑 The verb/subject vocabulary and the id parser live in a plain module, not
// here. A single-file component's methods cannot be reached from the `node
// tests/*.spec.js` suite this app uses, and while the parser sat in this file
// it was wrong about 35 of the 87 undeclared tools through a green build, a
// green lint and a passing e2e run — the rows rendered, they were just labelled
// with the wrong nouns. See tests/tool-taxonomy.spec.js.
import {
	CANONICAL_VERBS,
	joinKey as joinToolKey,
	parseVerbAndSubject as parseToolId,
} from '../utils/toolTaxonomy.js'

let matrixUid = 0

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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-saving-without-changing-anything-preserves-the-grants
		 */
		tools() {
			// ⚠️ The two endpoints spell the SAME tool differently: the catalogue
			// says `decidesk.listOpenActionItems`, the taxonomy says
			// `decidesk_listOpenActionItems`. Joined on the raw id, 172 of 202
			// tools missed and landed under "No application" — and the widget
			// still rendered, which is why this needs a normalised key rather
			// than an assumption that the ids agree.
			const taxonomyById = new Map(
				this.taxonomy.map((entry) => [this.joinKey(entry.name), entry]),
			)

			return (this.catalog?.tools ?? []).map((tool) => {
				const taxonomy = taxonomyById.get(this.joinKey(tool.id)) ?? {}
				// A tool granted by something OTHER than its own id is held
				// through a wildcard or verb subset. That is a different kind of
				// grant and must not render as an ordinary tick.
				const wildcard =
					tool.granted === true
					&& typeof tool.grantedBy === 'string'
					&& tool.grantedBy !== tool.id

				const parsed = this.parseVerbAndSubject(tool.id, taxonomy)

				return {
					id: tool.id,
					description: tool.description ?? '',
					app: taxonomy.app ?? null,
					subject: parsed.subject,
					verb: parsed.verb,
					// The tool's own verb, kept for the SPECIAL column's label:
					// "delegateAgent" says what the checkbox grants, "special"
					// says nothing.
					specialLabel: parsed.specialLabel,
					// 🔴 THE CLASSIFICATION IS CARRIED, NOT DROPPED. The catalogue
					// states `scope` (read/write), `destructiveHint` and
					// `requiresExplicitGrant`, and the flat editor this matrix
					// replaced showed them as a warning-styled badge — never
					// colour-only, the text carried the meaning. The first
					// version of this component kept none of them, so a checkbox
					// granting a destructive tool looked exactly like one
					// granting a read. An e2e test caught it; the honest fix is
					// to carry the classification, not to relax the test.
					//
					// ⚠️ `reach` is NOT this field. It is user/external/self/
					// instance — WHERE the data goes, not whether the tool
					// writes. Reading it as a write-classification marks every
					// tool, which is the same as marking none.
					scope: tool.scope === 'write' ? 'write' : 'read',
					destructive: tool.destructiveHint === true,
					requiresExplicitGrant: tool.requiresExplicitGrant === true,
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-saving-without-changing-anything-preserves-the-grants
		 */
		filteredTools() {
			const needle = this.filter.trim().toLowerCase()

			return this.tools.filter((tool) => {
				if (
					this.grantedOnly === true
					&& this.isDrafted(tool.id) === false
					&& tool.wildcard === false
				) {
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
		 */
		visibleClusters() {
			return this.buildClusters(this.filteredTools)
		},

		/**
		 * How many rights the draft currently holds.
		 *
		 * @return {number} The count.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-saving-without-changing-anything-preserves-the-grants
		 */
		grantedCount() {
			return this.draftGrants.length
		},

		/**
		 * How many tools exist in total, before filtering.
		 *
		 * @return {number} The count.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
		 */
		totalTools() {
			return this.tools.length
		},

		/**
		 * Whether the draft differs from what is saved.
		 *
		 * @return {boolean} True when there are unsaved changes.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-an-untouched-save-loses-nothing
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
			/**
			 * Reload the matrix when the agent changes.
			 *
			 * @return {void}
			 *
			 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-saving-without-changing-anything-preserves-the-grants
			 */
			handler() {
				this.load()
			},
		},

		dirty: {
			immediate: true,
			/**
			 * Tell the host whether there are unsaved changes.
			 *
			 * @param {boolean} value Whether the draft differs from what is stored.
			 * @return {void}
			 *
			 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-an-untouched-save-loses-nothing
			 */
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
		 * The key both endpoints agree on. See utils/toolTaxonomy.js.
		 *
		 * @param {string} id The tool id in either spelling.
		 * @return {string} The normalised join key.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
		 */
		joinKey(id) {
			return joinToolKey(id)
		},

		/**
		 * Split a tool id into the column it belongs in and the thing it acts on.
		 *
		 * See utils/toolTaxonomy.js — the rule and its history live there, with
		 * tests, because they could not be tested from inside this file.
		 *
		 * @param {string} id       The tool id, in any spelling.
		 * @param {object} taxonomy The taxonomy row for this tool, if any.
		 * @return {{verb: string, subject: string, specialLabel: string|null}} The parse.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
		 */
		parseVerbAndSubject(id, taxonomy) {
			return parseToolId(id, taxonomy)
		},

		/**
		 * Group tools into clusters, rows and verb columns.
		 *
		 * @param {Array<object>} tools The tools to group.
		 * @return {Array<object>} The clusters.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
		 */
		shapeCluster(cluster) {
			// The five canonical columns are ALWAYS rendered, even where a verb
			// is unused. A column that appears and disappears per cluster makes
			// the grid unreadable: `delete` present in one foldout and absent in
			// the next means the eye cannot run down a column, and an empty
			// `delete` cell is itself information — nobody can delete this.
			const hasSpecial = cluster.tools.some((tool) => tool.verb === 'special')
			const verbs =
				hasSpecial === true
					? [...CANONICAL_VERBS, 'special']
					: [...CANONICAL_VERBS]

			const rows = new Map()
			for (const tool of cluster.tools) {
				if (rows.has(tool.subject) === false) {
					rows.set(tool.subject, {
						id: tool.subject,
						label: tool.subject,
						description: '',
						tools: {},
						// Specials are a LIST, not one slot: a subject can carry
						// several (upsertContact, forgetMemory), and collapsing
						// them into one cell would silently hide all but one.
						specials: [],
					})
				}

				const row = rows.get(tool.subject)
				if (tool.verb === 'special') {
					row.specials.push(tool)
				} else {
					row.tools[tool.verb] = tool
				}

				if (row.description === '') {
					row.description = tool.description
				}
			}

			const shapedRows = [...rows.values()].sort((a, b) =>
				a.label.localeCompare(b.label),
			)

			const label =
				cluster.id === '__none__'
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-the-legacy-grant-grammar-lives-in-exactly-one-place
		 */
		verbLabel(verb) {
			const labels = {
				create: t('hermiq', 'Create'),
				read: t('hermiq', 'Read'),
				update: t('hermiq', 'Update'),
				list: t('hermiq', 'List'),
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
		 */
		ariaFor(cluster, row, verb) {
			const base = t('hermiq', 'Grant {verb} on {subject} in {cluster}', {
				verb: this.verbLabel(verb),
				subject: row.label,
				cluster: cluster.label,
			})

			// The classification belongs in the ACCESSIBLE NAME, not only in a
			// visual badge: a screen-reader user ticking this box otherwise
			// hears the same thing for a read and for a destructive grant.
			const label = this.classificationLabel(row.tools[verb])
			if (label === '') {
				return base
			}

			return `${base} — ${label}`
		},

		/**
		 * The operator-facing classification for one tool, or '' for a plain read.
		 *
		 * Spelled out rather than shown as a colour or an icon alone: this is
		 * the one place a person learns that ticking a box grants something
		 * that WRITES or DELETES, and colour alone fails WCAG 1.4.1.
		 *
		 * @param {object} tool The catalogue entry for this cell.
		 * @return {string} The label, or '' when there is nothing to warn about.
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
		 */
		classificationLabel(tool) {
			if (!tool || (tool.scope !== 'write' && tool.destructive !== true)) {
				return ''
			}

			const kind =
				tool.destructive === true
					? t('hermiq', 'destructive')
					: t('hermiq', 'write')

			if (tool.requiresExplicitGrant !== true) {
				return kind
			}

			return t('hermiq', '{kind} — requires explicit grant', { kind })
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-tool-grants-are-a-structure-in-the-domain-and-a-list-in-storage
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-an-untouched-save-loses-nothing
		 */
		isDrafted(id) {
			// Compared on the normalised key, not the raw string: the same grant
			// is stored as `pipelinq_lead_search` or `pipelinq.lead.search`
			// depending on who wrote it. A raw comparison leaves a held grant
			// showing as unticked, which invites the user to "grant" it again
			// and end up with the same right stored twice in two spellings.
			const key = this.joinKey(id)
			return this.draftGrants.some((grant) => this.joinKey(grant) === key)
		},

		/**
		 * Add or remove one exact tool grant.
		 *
		 * @param {string} id The tool id.
		 * @param {boolean} checked The new state.
		 * @return {void}
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-two-constrained-grants-for-one-tool-both-survive
		 */
		toggleTool(id, checked) {
			if (checked === true) {
				if (this.isDrafted(id) === false) {
					this.draftGrants = [...this.draftGrants, id]
				}
				return
			}

			// Removed on the normalised key so unticking clears the right
			// whichever spelling it was stored in.
			const key = this.joinKey(id)
			this.draftGrants = this.draftGrants.filter(
				(grant) => this.joinKey(grant) !== key,
			)
		},

		/**
		 * Load the catalogue and the taxonomy together.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#requirement-saving-without-changing-anything-preserves-the-grants
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [catalog, taxonomy, grants] = await Promise.all([
					getToolCatalog(this.agentId),
					getToolTaxonomy(),
					getAgentGrants(this.agentId),
				])

				this.catalog = catalog
				this.taxonomy = taxonomy

				// 🔴 Read the grants as STORED. Reconstructing them from the
				// catalogue's `grantedBy` annotations — which is what this did
				// first — silently drops every grant the catalogue cannot
				// attribute. Measured on a live agent: all 8 grants came back
				// `granted: true` with `grantedBy: null`, so the reconstruction
				// kept none, and saving wrote the survivors only. Opening this
				// widget and pressing Save destroyed seven grants.
				//
				// Save writes back exactly what was read plus the user's edits,
				// so a grant form this UI cannot render — a wildcard, a verb
				// subset, an id the catalogue no longer lists — survives
				// untouched instead of being quietly deleted by a screen that
				// never showed it.
				this.savedGrants = [...grants]
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-an-untouched-save-loses-nothing
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
		 *
		 * @spec openspec/changes/structured-tool-grants/specs/structured-tool-grants/spec.md#scenario-an-untouched-save-loses-nothing
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

/* Specials stack: one labelled control per action, so a subject carrying
   several (upsertContact, forgetMemory) shows all of them rather than one. */
.grant-matrix__cell--special {
	text-align: start;
}

.grant-matrix__special {
	display: inline-flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	margin-inline-end: 12px;
	vertical-align: top;
	cursor: help;
}

.grant-matrix__special-name {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
}

.grant-matrix__grant {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

/* Text, not colour: the word is what carries the meaning, and the colour only
   reinforces it (WCAG 1.4.1 — never colour alone). */
.grant-matrix__reach {
	font-size: 0.7em;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: 0.02em;
	padding: 1px 4px;
	border-radius: var(--border-radius-small, 4px);
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.grant-matrix__reach--destructive {
	color: var(--color-error-text, var(--color-error));
	border-color: var(--color-error);
}

/* The chevron's rotation is decoration — the open/closed state is already
   carried by aria-expanded and by the rows themselves, so removing the
   animation costs the reader nothing and spares anyone who asked for less
   motion a spin on every cluster they open. */
@media (prefers-reduced-motion: reduce) {
	.grant-matrix__chevron {
		transition: none;
	}
}
</style>
