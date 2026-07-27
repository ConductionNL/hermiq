<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillVersionHistory — the SkillDetail page's version history widget
  (skill-self-improvement), mirroring agent-versioning's surface: the
  AuditTrail-backed history (newest first), a per-version diff view over the
  versioned content plane (frontmatter/body/files), the explicit Rollback action
  (rollback-as-a-new-version, history never mutated), the "published copy is
  behind" badge (client-side publishedAt < lastAcceptedVersionAt comparison) with
  its one-click Republish (same publish authorization, own provenance repo only,
  NEVER automatic), and the advisory post-acceptance rollback-suggestion banner
  (derived from the linked datasets' latest failed regression run after
  lastAcceptedVersionAt — rollback stays an explicit human action).

  @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
  @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
  @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
-->
<template>
	<div class="skill-version-history">
		<NcNoteCard v-if="error" type="error" :heading="t('hermiq', 'Version history error')">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" class="skill-version-history__loading" />

		<template v-else>
			<div v-if="publishedCopyBehind" class="skill-version-history__behind">
				<span class="skill-version-history__behind-badge" role="status">
					{{ t('hermiq', 'Published copy is behind') }}
				</span>
				<span class="skill-version-history__hint">
					{{ t('hermiq', 'The accepted local version postdates the GitHub publish. Nothing is pushed automatically.') }}
				</span>
				<div class="skill-version-history__republish">
					<NcTextField
						:value.sync="republishCredentialId"
						:label="t('hermiq', 'Broker credential ID')"
						:placeholder="t('hermiq', 'github credential UUID')" />
					<NcButton type="secondary"
						:disabled="busy || republishCredentialId.trim() === ''"
						:aria-label="t('hermiq', 'Republish to the published GitHub repository')"
						@click="doRepublish">
						{{ busy ? t('hermiq', 'Working…') : t('hermiq', 'Republish') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="rollbackSuggestion" type="warning" :heading="t('hermiq', 'Roll back to previous version?')">
				{{ t('hermiq', 'The first eval run after the last accepted version failed the regression gate. Rolling back is advisory and always your explicit choice.') }}
			</NcNoteCard>

			<h4 class="skill-version-history__heading">
				{{ t('hermiq', 'Version history') }}
			</h4>
			<ul class="skill-version-history__list">
				<li v-for="(version, index) in versions" :key="version.id" class="skill-version-history__row">
					<div class="skill-version-history__row-main">
						<span class="skill-version-history__stamp">{{ formatDate(version.timestamp) }}</span>
						<span class="skill-version-history__user">{{ version.user || '—' }}</span>
						<span class="skill-version-history__fields">
							{{ version.changedFields && version.changedFields.length ? version.changedFields.join(', ') : t('hermiq', 'no content change') }}
						</span>
						<span v-if="index === 0" class="skill-version-history__current" role="status">
							{{ t('hermiq', 'current') }}
						</span>
					</div>
					<div class="skill-version-history__row-actions">
						<NcButton v-if="index !== 0"
							type="tertiary"
							:disabled="busy"
							:aria-label="t('hermiq', 'Show the differences against the current version')"
							@click="showDiff(version.id)">
							{{ t('hermiq', 'Diff') }}
						</NcButton>
						<NcButton v-if="index !== 0"
							type="tertiary"
							:disabled="busy"
							:aria-label="t('hermiq', 'Roll back to this version')"
							@click="doRollback(version.id)">
							{{ t('hermiq', 'Roll back') }}
						</NcButton>
					</div>
				</li>
				<li v-if="versions.length === 0" class="skill-version-history__empty">
					{{ t('hermiq', 'No versions recorded yet.') }}
				</li>
			</ul>

			<template v-if="diffVersionId">
				<h4 class="skill-version-history__heading">
					{{ t('hermiq', 'Differences vs current version') }}
				</h4>
				<dl v-if="Object.keys(diff).length > 0" class="skill-version-history__diff">
					<template v-for="(change, field) in diff" :key="field">
						<dt>
							{{ field }}
						</dt>
						<dd>
							<div class="skill-version-history__diff-half">
								<span class="skill-version-history__diff-label">{{ t('hermiq', 'Then') }}</span>
								<pre class="skill-version-history__pre">{{ stringify(change.old) }}</pre>
							</div>
							<div class="skill-version-history__diff-half">
								<span class="skill-version-history__diff-label">{{ t('hermiq', 'Now') }}</span>
								<pre class="skill-version-history__pre">{{ stringify(change.new) }}</pre>
							</div>
						</dd>
					</template>
				</dl>
				<p v-else class="skill-version-history__empty">
					{{ t('hermiq', 'The selected version has identical content.') }}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { diffSkillVersions, listSkillVersions, republishSkill, rollbackSkill } from '../api/skills.js'
import { useEvalDatasetStore, useEvalRunStore, useSkillStore } from '../store/store.js'

export default {
	name: 'SkillVersionHistory',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			skill: null,
			versions: [],
			evalRuns: [],
			datasets: [],
			diff: {},
			diffVersionId: '',
			republishCredentialId: '',
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * This skill's uuid from the route param.
		 *
		 * @spec exclude pure route-param accessor, no spec behaviour
		 * @return {string} The skill uuid.
		 */
		skillId() {
			return this.$route.params.id
		},

		/**
		 * Whether the published GitHub copy is behind the accepted local version
		 * (pure client-side comparison — no history query).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
		 * @return {boolean}
		 */
		publishedCopyBehind() {
			const publishedAt = this.skill?.publishedAt
			const acceptedAt = this.skill?.lastAcceptedVersionAt
			if (!this.skill?.githubRepo || !publishedAt || !acceptedAt) {
				return false
			}
			const published = new Date(publishedAt).getTime()
			const accepted = new Date(acceptedAt).getTime()
			return Number.isFinite(published) && Number.isFinite(accepted) && accepted > published
		},

		/**
		 * Whether the advisory rollback suggestion applies: the latest eval run for
		 * a dataset linking this skill failed the regression gate AFTER the last
		 * accepted version.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-post-acceptance-regression-surfaces-a-rollback-suggestion
		 * @return {boolean}
		 */
		rollbackSuggestion() {
			const acceptedAt = this.skill?.lastAcceptedVersionAt
			if (!acceptedAt) {
				return false
			}
			const accepted = new Date(acceptedAt).getTime()
			const linkedDatasetIds = new Set(
				this.datasets
					.filter((dataset) => Array.isArray(dataset?.skillRefs) && dataset.skillRefs.includes(this.skillId))
					.map((dataset) => dataset.uuid || dataset.id),
			)
			return this.evalRuns.some((run) => {
				if (!linkedDatasetIds.has(run?.datasetId) || run?.regressionGateResult !== 'failed') {
					return false
				}
				const ended = new Date(run?.endedAt || 0).getTime()
				return Number.isFinite(ended) && ended > accepted
			})
		},
	},

	/**
	 * Vue lifecycle hook — wires the stores and kicks off the initial load.
	 *
	 * @spec exclude framework lifecycle hook, only wires stores and delegates to load()
	 */
	created() {
		this.skillStore = useSkillStore()
		this.skillStore.registerObjectType('agentskill', 'agentskill', 'hermiq')
		this.datasetStore = useEvalDatasetStore()
		this.datasetStore.registerObjectType('evaldataset', 'evaldataset', 'hermiq')
		this.runStore = useEvalRunStore()
		this.runStore.registerObjectType('evalrun', 'evalrun', 'hermiq')
		this.load()
	},

	methods: {
		/**
		 * Load the skill, its version history, and the eval context for the
		 * regression-watch banner.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [skill, versions, datasets, evalRuns] = await Promise.all([
					this.skillStore.fetchObject('agentskill', this.skillId),
					listSkillVersions(this.skillId),
					this.datasetStore.fetchCollection('evaldataset').catch(() => []),
					this.runStore.fetchCollection('evalrun').catch(() => []),
				])
				this.skill = skill || null
				this.versions = versions
				this.datasets = Array.isArray(datasets) ? datasets : (datasets?.results || [])
				this.evalRuns = Array.isArray(evalRuns) ? evalRuns : (evalRuns?.results || [])
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Could not load the version history.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show the diff of one version against the current version.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
		 * @param {string} versionId The older version id.
		 * @return {Promise<void>}
		 */
		async showDiff(versionId) {
			this.busy = true
			this.error = ''
			try {
				const currentId = this.versions[0]?.id
				this.diff = await diffSkillVersions(this.skillId, versionId, currentId)
				this.diffVersionId = versionId
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Diff failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Roll back to a previous version — an explicit human action, creating a
		 * brand-new version (history never mutated).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
		 * @param {string} versionId The target version id.
		 * @return {Promise<void>}
		 */
		async doRollback(versionId) {
			this.busy = true
			this.error = ''
			try {
				await rollbackSkill(this.skillId, versionId)
				this.diff = {}
				this.diffVersionId = ''
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Rollback failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * One-click republish to the skill's own provenance repo (never automatic;
		 * requires the publish authorization server-side).
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
		 * @return {Promise<void>}
		 */
		async doRepublish() {
			this.busy = true
			this.error = ''
			try {
				await republishSkill(this.skillId, this.republishCredentialId.trim())
				await this.load()
			} catch (e) {
				this.error = e?.response?.data?.error || e?.message || this.t('hermiq', 'Republish failed.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Render a diff value for display.
		 *
		 * @spec exclude pure display formatting of a diff value
		 * @param {*} value The old/new value.
		 * @return {string} The printable form.
		 */
		stringify(value) {
			if (value === null || value === undefined) {
				return '—'
			}
			if (typeof value === 'string') {
				return value
			}
			return JSON.stringify(value, null, 2)
		},

		/**
		 * Format an ISO timestamp for display.
		 *
		 * @spec exclude pure date formatting for display
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted date, or an em dash.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.skill-version-history {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px;
	overflow-y: auto;
	height: 100%;
}

.skill-version-history__loading {
	margin: 24px auto;
}

.skill-version-history__behind {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 8px;
}

.skill-version-history__behind-badge {
	display: inline-block;
	align-self: flex-start;
	padding: 2px 8px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
}

.skill-version-history__republish {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.skill-version-history__heading {
	margin: 8px 0 0;
	font-size: 14px;
	font-weight: 600;
}

.skill-version-history__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.skill-version-history__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	border-bottom: 1px solid var(--color-border);
	padding: 4px 0;
}

.skill-version-history__row-main {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	min-width: 0;
}

.skill-version-history__stamp {
	font-size: 13px;
}

.skill-version-history__user,
.skill-version-history__fields,
.skill-version-history__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.skill-version-history__current {
	display: inline-block;
	padding: 0 6px;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-pill);
	font-size: 11px;
}

.skill-version-history__row-actions {
	display: flex;
	gap: 4px;
	flex: none;
}

.skill-version-history__diff {
	margin: 0;
}

.skill-version-history__diff dt {
	font-weight: 600;
	margin-top: 8px;
}

.skill-version-history__diff dd {
	display: flex;
	gap: 12px;
	margin: 4px 0 0;
}

.skill-version-history__diff-half {
	flex: 1 1 0;
	min-width: 0;
}

.skill-version-history__diff-label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.skill-version-history__pre {
	max-height: 200px;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 12px;
	white-space: pre-wrap;
}

.skill-version-history__empty {
	color: var(--color-text-maxcontrast);
	margin: 4px 0;
}
</style>
