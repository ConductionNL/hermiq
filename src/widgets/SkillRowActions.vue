<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  SkillRowActions — SkillsCatalog's per-row write actions as a
  `page.slots.row-actions` custom widget over the generic `type:"index"`
  SkillsCatalog page (manifest-driven-pages), mirroring
  AgentTemplateRowActions.vue exactly.

  "Approve" (quarantined only), "Export" and "Install onto agent" all call
  the existing tenant-scoped SkillController endpoints unchanged
  (src/api/skills.js) — no new write path, no declarative `object-op` patch.
  "Publish" hits a structured seam (publishSkill) that may return `{ error }`
  when no hub is configured; that is surfaced as an error note, not thrown.

  Receives `{ row }` from CnIndexPage's `#row-actions` scoped slot — `row` is
  the raw Skill OpenRegister object (register:"hermiq" schema:"agentskill").

  Install needs an agent picker: agents are loaded lazily (only when the
  install modal opens) via `useAgentStore()` +
  `registerObjectType('agent', 'agent', 'hermiq')` + `fetchCollection('agent')`
  — the same store the retired SkillsCatalog.vue used — mirroring how
  AgentTemplateRowActions lazily loads GitHub credentials in
  `fetchCredentials()`.

  hermiq-github-store adds a fifth action here, "Publish to GitHub" — a form
  (owner/repo/visibility/credential) that calls the new guarded
  `github/publish` endpoint, the new PRIMARY skill-publish path (the existing
  "Publish" button above stays as the secondary OpenConnector hub route). A
  broker `credentialId` is required; the GitHub token never reaches Hermiq —
  mirrors AgentTemplateRowActions' publish-to-GitHub form exactly.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-agenttemplategallery-renders-as-an-index-type-list-page-with-write-actions-kept-behind-their-existing-guarded-endpoints
  @spec openspec/changes/skills-catalog/specs/skills-catalog/spec.md
  @spec openspec/changes/skills-marketplace/tasks.md
  @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
-->
<template>
	<div class="skill-row-actions">
		<!-- skill-self-improvement: the catalog behind-badge — a pure client-side
		     publishedAt < lastAcceptedVersionAt comparison (no per-row history
		     query); text, never icon-only. -->
		<span
			v-if="publishedCopyBehind"
			class="skill-row-actions__behind-badge"
			role="status">
			{{ t('hermiq', 'Published copy is behind') }}
		</span>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="githubPublishNotice" type="success">
			{{ githubPublishNotice }}
		</NcNoteCard>

		<div class="skill-row-actions__buttons">
			<NcButton
				v-if="row.state === 'quarantined'"
				type="secondary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Approve quarantined skill')"
				@click="doApprove">
				{{ t('hermiq', 'Approve') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Qualify skill maturity')"
				@click="doQualify">
				{{ t('hermiq', 'Qualify') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Export skill')"
				@click="doExport">
				{{ t('hermiq', 'Export') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Publish skill to a hub')"
				@click="doPublish">
				{{ t('hermiq', 'Publish') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Publish skill to GitHub')"
				@click="openGithubPublishModal">
				{{ t('hermiq', 'Publish to GitHub') }}
			</NcButton>
			<NcButton
				type="tertiary"
				:disabled="busy"
				:aria-label="t('hermiq', 'Install on agent')"
				@click="openInstallModal">
				{{ t('hermiq', 'Install') }}
			</NcButton>
		</div>

		<SkillScorecardModal
			v-if="scorecardResult"
			:result="scorecardResult"
			:skillName="row.name || ''"
			@close="scorecardResult = null" />

		<SkillExportModal
			v-if="showExport"
			:exportedPackage="exportedPackage"
			@close="showExport = false" />

		<SkillInstallModal
			v-if="showInstall"
			:skillId="skillId()"
			@installed="onInstalled"
			@close="showInstall = false" />

		<SkillGithubPublishModal
			v-if="showGithubPublish"
			:skillId="skillId()"
			@published="onGithubPublished"
			@close="showGithubPublish = false" />
	</div>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import SkillExportModal from '../modals/SkillExportModal.vue'
import SkillGithubPublishModal from '../modals/SkillGithubPublishModal.vue'
import SkillInstallModal from '../modals/SkillInstallModal.vue'
// skill-maturity: the post-qualify scorecard, its own file per the
// modal-isolation rule — as are the export, install and GitHub-publish
// modals below.
import SkillScorecardModal from '../modals/SkillScorecardModal.vue'
import {
	approveSkill,
	exportSkill,
	publishSkill,
	qualifySkill,
} from '../api/skills.js'

export default {
	name: 'SkillRowActions',

	components: {
		NcButton,
		NcNoteCard,
		SkillExportModal,
		SkillGithubPublishModal,
		SkillInstallModal,
		SkillScorecardModal,
	},

	props: {
		/** The Skill row object (raw OpenRegister object). */
		row: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			busy: false,
			error: '',
			// skill-maturity: the qualify endpoint's scorecard payload; non-null
			// opens the SkillScorecardModal.
			scorecardResult: null,
			exportedPackage: '',
			showExport: false,
			// The install and GitHub-publish forms live in their own modal
			// files (modal-isolation rule); this widget only toggles them.
			showInstall: false,
			showGithubPublish: false,
			githubPublishNotice: '',
		}
	},

	computed: {
		/**
		 * Whether the published GitHub copy is behind the locally accepted version
		 * (skill-self-improvement): githubRepo set AND publishedAt older than
		 * lastAcceptedVersionAt — computed client-side per row, no history query.
		 *
		 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
		 * @return {boolean}
		 */
		publishedCopyBehind() {
			const publishedAt = this.row?.publishedAt
			const acceptedAt = this.row?.lastAcceptedVersionAt
			if (!this.row?.githubRepo || !publishedAt || !acceptedAt) {
				return false
			}
			const published = new Date(publishedAt).getTime()
			const accepted = new Date(acceptedAt).getTime()
			return (
				Number.isFinite(published)
				&& Number.isFinite(accepted)
				&& accepted > published
			)
		},
	},

	methods: {
		/**
		 * The row's uuid (OpenRegister objects expose both `uuid` and `id`
		 * depending on read path; prefer `uuid`).
		 *
		 * @return {string} The skill uuid.
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 */
		skillId() {
			return this.row.uuid || this.row.id
		},

		/**
		 * Approve a quarantined skill (the review gate → active). Refreshes
		 * the list via the shared `cn:page:refresh` signal so the row's state
		 * column updates.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 */
		async doApprove() {
			this.busy = true
			this.error = ''
			try {
				await approveSkill(this.skillId())
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Qualify this skill (skill-maturity): recompute + persist its maturity
		 * server-side, show the returned scorecard (with the failing levels'
		 * reasons), and refresh the list so the dots column updates.
		 *
		 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-catalog-ui-surfaces-maturity-dots-a-detail-scorecard-and-a-qualify-action
		 * @return {Promise<void>}
		 */
		async doQualify() {
			this.busy = true
			this.error = ''
			try {
				this.scorecardResult = await qualifySkill(this.skillId())
				emit('cn:page:refresh', {})
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Export this skill to a shareable agentskills.io package and show it.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-bidirectional-skillserializer-round-trip-fidelity
		 * @return {Promise<void>}
		 */
		async doExport() {
			this.error = ''
			try {
				this.exportedPackage = await exportSkill(this.skillId())
				this.showExport = true
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			}
		},

		/**
		 * Publish this skill to an external hub (structured seam error when
		 * unavailable — surfaced as a note, not thrown).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 */
		async doPublish() {
			this.busy = true
			this.error = ''
			try {
				const result = await publishSkill(this.skillId())
				if (result && result.error) {
					this.error =
						result.error.message
						|| this.t('hermiq', 'Publishing is not available.')
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the "Publish to GitHub" modal (hermiq-github-store, its own
		 * file per the modal-isolation rule).
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @return {void}
		 */
		openGithubPublishModal() {
			this.githubPublishNotice = ''
			this.showGithubPublish = true
		},

		/**
		 * Handle a successful GitHub publish reported by the modal: show the
		 * notice and refresh the list so the provenance columns update.
		 *
		 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
		 * @param {string} repoUrl The published repository URL.
		 * @return {void}
		 */
		onGithubPublished(repoUrl) {
			this.githubPublishNotice = this.t('hermiq', 'Published to {repoUrl}', {
				repoUrl,
			})
			this.showGithubPublish = false
			emit('cn:page:refresh', {})
		},

		/**
		 * Open the "Install on agent" modal (its own file per the
		 * modal-isolation rule).
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {void}
		 */
		openInstallModal() {
			this.showInstall = true
		},

		/**
		 * Handle a completed install reported by the modal: close it and
		 * refresh the list via the shared `cn:page:refresh` signal so the
		 * row's installed count updates.
		 *
		 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
		 * @return {void}
		 */
		onInstalled() {
			this.showInstall = false
			emit('cn:page:refresh', {})
		},
	},
}
</script>

<style scoped>
.skill-row-actions__behind-badge {
	display: inline-block;
	padding: 2px 8px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text, var(--color-main-text));
	background-color: var(--color-warning-hover, transparent);
	font-size: 12px;
	white-space: nowrap;
}

.skill-row-actions__buttons {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}
</style>
