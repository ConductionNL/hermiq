<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplateGithubStore — the "GitHub store" discovery section of the
  unified "Store" page (agent-template-github-store, generalised by
  hermiq-github-store), resolved via `page.slots.below-header` on the Store
  page's generic `type:"index"` config (manifest-driven-pages) — sits between
  the page header and the local agent-template table, a close port of
  OpenBuild's `TemplateGallery.vue` GitHub section (search box, result cards,
  a credential hint), now generalised with a per-kind filter.

  hermiq-github-store: this widget's name/registry key/component filename are
  UNCHANGED (design.md Decision 1 — kind-parameterise, don't fork) even though
  it now discovers BOTH `topic:hermiq-agent-template` and `topic:hermiq-skill`
  repos. Each active kind issues its OWN backend call
  (`searchGithubTemplates`/`searchGithubSkills` — Decision 3: search/install
  live on their owning controller, AgentTemplateController/SkillController
  respectively) and the kind-tagged cards are merged client-side; the kind
  filter (`NcSelect` with an `inputLabel`, WCAG 2.1 AA) simply narrows which
  calls are issued. Installing a card dispatches to the matching install path
  by `card.kind`: an agent-template card still goes through the UNCHANGED
  `AgentTemplateController::githubInstall()` -> `AgentTemplateService::
  importPackage(source: 'hub')`; a skill card goes through
  `SkillController::githubInstall()` -> `SkillMarketplaceService::
  installFromSource(source: 'hub')` (also unchanged) — both quarantine +
  content-scan gates are untouched, so an installed row of either kind always
  lands `state: "quarantined"`.

  @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
  @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
  @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
  @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
-->
<template>
	<div class="agent-template-github-store">
		<h3 class="agent-template-github-store__heading">
			{{ t('hermiq', 'GitHub store') }}
		</h3>

		<div class="agent-template-github-store__search">
			<NcTextField v-model="query"
				:label="t('hermiq', 'Search GitHub for agent templates or skills')"
				:placeholder="t('hermiq', 'Search by name or keyword…')"
				@update:modelValue="onQueryChange" />
			<NcSelect v-model="kindFilter"
				:options="kindOptions"
				:input-label="t('hermiq', 'Kind')"
				:clearable="false"
				label="label"
				track-by="value"
				@update:modelValue="doSearch" />
			<NcSelect v-if="githubCredentials.length > 0"
				v-model="selectedCredential"
				:options="githubCredentials"
				:input-label="t('hermiq', 'GitHub credential')"
				:loading="loadingCredentials"
				:placeholder="t('hermiq', 'Anonymous (no credential)')"
				label="label"
				@update:modelValue="doSearch" />
		</div>

		<NcNoteCard v-if="rateLimitHintVisible" type="warning">
			{{ t('hermiq', 'GitHub\'s anonymous search rate limit was reached. Add a personal GitHub credential under your Personal settings, or ask an organisation admin to add one under the Hermiq admin settings, to raise the limit and see private repos.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="unreachableHintVisible" type="warning">
			{{ t('hermiq', 'Could not reach GitHub right now. Please try again later.') }}
		</NcNoteCard>
		<NcNoteCard v-if="installError" type="error">
			{{ installError }}
		</NcNoteCard>
		<NcNoteCard v-if="installNotice" type="success">
			{{ installNotice }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent v-else-if="cards.length === 0"
			:name="t('hermiq', 'No GitHub results found')"
			:description="t('hermiq', 'Publish an agent template or skill from a row below, or try a different search term.')" />
		<div v-else class="agent-template-github-store__cards">
			<div v-for="card in cards" :key="card.kind + ':' + card.owner + '/' + card.repo" class="agent-template-github-store__card">
				<div class="agent-template-github-store__card-title">
					{{ card.name || (card.owner + '/' + card.repo) }}
					<span class="agent-template-github-store__card-kind">{{ kindLabel(card.kind) }}</span>
				</div>
				<div class="agent-template-github-store__card-meta">
					{{ card.owner }}/{{ card.repo }}
					<span v-if="card.version"> · v{{ card.version }}</span>
					<span> · ★{{ card.stars }}</span>
				</div>
				<p v-if="card.description" class="agent-template-github-store__card-description">
					{{ card.description }}
				</p>
				<NcNoteCard v-if="card.unparseable" type="warning">
					{{ unparseableHint(card.kind) }}
				</NcNoteCard>
				<NcButton v-else
					type="primary"
					:disabled="installingId === cardKey(card)"
					@click="doInstall(card)">
					{{ installingId === cardKey(card) ? t('hermiq', 'Installing…') : t('hermiq', 'Install') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { emit } from '@nextcloud/event-bus'
import { installGithubTemplate, searchGithubTemplates } from '../api/agentTemplates.js'
import { installGithubSkill, searchGithubSkills } from '../api/skills.js'

/** Debounce delay (ms) before a typed search term triggers a request. */
const SEARCH_DEBOUNCE_MS = 400

/** The two discovery kinds this store searches (hermiq-github-store). */
const KIND_AGENT_TEMPLATE = 'agent-template'
const KIND_SKILL = 'skill'

export default {
	name: 'AgentTemplateGithubStore',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			query: '',
			kindFilter: { label: this.t('hermiq', 'All (agent templates + skills)'), value: 'all' },
			cards: [],
			outcome: 'ok',
			brokerCredentialAvailable: false,
			rateLimited: false,
			loading: false,
			credentials: [],
			loadingCredentials: false,
			selectedCredential: null,
			installingId: null,
			installError: '',
			installNotice: '',
			searchDebounceTimer: null,
		}
	},

	computed: {
		/**
		 * The kind-filter options (hermiq-github-store).
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		kindOptions() {
			return [
				{ label: this.t('hermiq', 'All (agent templates + skills)'), value: 'all' },
				{ label: this.t('hermiq', 'Agent templates'), value: KIND_AGENT_TEMPLATE },
				{ label: this.t('hermiq', 'Skills'), value: KIND_SKILL },
			]
		},

		/**
		 * The caller's broker credentials scoped to the `github` provider, shaped
		 * for NcSelect (mirrors LlmProviderModal.vue's `credentialsFor()`).
		 *
		 * @return {Array<object>} NcSelect options.
		 */
		githubCredentials() {
			return this.credentials
				.filter((c) => c.provider === 'github')
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		/**
		 * Whether to show the "add a credential to raise limits" hint.
		 *
		 * @return {boolean}
		 */
		rateLimitHintVisible() {
			return this.rateLimited === true && this.outcome === 'github_rate_limited'
		},

		/**
		 * Whether to show the generic "could not reach GitHub" hint.
		 *
		 * @return {boolean}
		 */
		unreachableHintVisible() {
			return this.rateLimited === false && this.outcome === 'github_unreachable'
		},
	},

	mounted() {
		this.fetchCredentials()
		this.doSearch()
	},

	methods: {
		/**
		 * Load the caller's broker credentials (for the optional GitHub picker).
		 *
		 * @return {Promise<void>}
		 */
		async fetchCredentials() {
			this.loadingCredentials = true
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				this.credentials = data.results || []
			} catch (e) {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * Debounce a typed search term before re-searching.
		 *
		 * @return {void}
		 */
		onQueryChange() {
			clearTimeout(this.searchDebounceTimer)
			this.searchDebounceTimer = setTimeout(this.doSearch, SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Whether the given kind is currently active under the kind filter.
		 *
		 * @param {string} kind The discovery kind.
		 * @return {boolean}
		 */
		kindActive(kind) {
			return (this.kindFilter?.value || 'all') === 'all' || this.kindFilter?.value === kind
		},

		/**
		 * A human label for a card's kind.
		 *
		 * @param {string} kind The discovery kind.
		 * @return {string}
		 */
		kindLabel(kind) {
			return kind === KIND_SKILL ? this.t('hermiq', 'Skill') : this.t('hermiq', 'Agent template')
		},

		/**
		 * The kind-appropriate "cannot install" hint for an unparseable card.
		 *
		 * @param {string} kind The discovery kind.
		 * @return {string}
		 */
		unparseableHint(kind) {
			return kind === KIND_SKILL
				? this.t('hermiq', 'This repo does not carry a readable hermiq-skill.md — it cannot be installed.')
				: this.t('hermiq', 'This repo does not carry a readable hermiq-agent-template.json — it cannot be installed.')
		},

		/**
		 * Search GitHub for the active kind(s)' discovery-topic repos. Each active
		 * kind issues its own backend call (hermiq-github-store); the kind-tagged
		 * cards are merged client-side. Never throws — a rate-limited/unreachable
		 * search resolves to an empty card list plus an explicit outcome, surfaced
		 * as a non-blocking hint.
		 *
		 * @return {Promise<void>}
		 */
		async doSearch() {
			this.loading = true
			try {
				const credentialId = this.selectedCredential?.value || null
				const calls = []
				if (this.kindActive(KIND_AGENT_TEMPLATE)) {
					calls.push(
						searchGithubTemplates(this.query, credentialId)
							.catch(() => ({ outcome: 'github_unreachable', cards: [], rateLimited: false, brokerCredentialAvailable: false })),
					)
				}
				if (this.kindActive(KIND_SKILL)) {
					calls.push(
						searchGithubSkills(this.query, credentialId)
							.catch(() => ({ outcome: 'github_unreachable', cards: [], rateLimited: false, brokerCredentialAvailable: false })),
					)
				}

				const results = await Promise.all(calls)
				this.cards = results.flatMap((r) => r.cards || [])
				this.brokerCredentialAvailable = results.some((r) => r.brokerCredentialAvailable === true)

				const anyOk = results.some((r) => r.outcome === 'ok')
				const anyRateLimited = results.some((r) => r.rateLimited === true)
				this.rateLimited = anyRateLimited
				if (anyOk) {
					this.outcome = 'ok'
				} else if (anyRateLimited) {
					this.outcome = 'github_rate_limited'
				} else {
					this.outcome = (results[0] && results[0].outcome) || 'ok'
				}
			} catch (e) {
				this.cards = []
				this.outcome = 'github_unreachable'
			} finally {
				this.loading = false
			}
		},

		/**
		 * A stable per-card key for the busy-state check.
		 *
		 * @param {object} card The result card.
		 * @return {string} `kind:owner/repo`.
		 */
		cardKey(card) {
			return `${card.kind}:${card.owner}/${card.repo}`
		},

		/**
		 * Install a discovered card. Dispatches by `card.kind` to the matching
		 * install path — both land quarantined + content-scanned via their
		 * existing, UNCHANGED quarantine gates (hermiq-github-store). Refreshes
		 * the page's index table so a newly-installed agent-template row appears
		 * below (an installed skill surfaces on the separate Skills page).
		 *
		 * @param {object} card The result card to install.
		 * @return {Promise<void>}
		 */
		async doInstall(card) {
			this.installError = ''
			this.installNotice = ''
			this.installingId = this.cardKey(card)
			try {
				const credentialId = this.selectedCredential?.value || null
				if (card.kind === KIND_SKILL) {
					await installGithubSkill({ owner: card.owner, repo: card.repo, credentialId })
					this.installNotice = this.t('hermiq', 'Installed skill "{name}" — it is quarantined until reviewed. Find it on the Skills page.', { name: card.name || this.cardKey(card) })
				} else {
					await installGithubTemplate({ owner: card.owner, repo: card.repo, credentialId })
					this.installNotice = this.t('hermiq', 'Installed "{name}" — it is quarantined until reviewed.', { name: card.name || this.cardKey(card) })
					emit('cn:page:refresh', {})
				}
			} catch (e) {
				this.installError = e?.response?.data?.error || e?.message || this.t('hermiq', 'Unknown error')
			} finally {
				this.installingId = null
			}
		},
	},
}
</script>

<style scoped>
.agent-template-github-store {
	margin-bottom: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.agent-template-github-store__heading {
	margin: 0 0 8px;
	/* This widget renders at the top of the Store index page's below-header
	   slot, so its heading is the first thing under the Nextcloud navigation
	   toggle (44px wide, absolutely positioned at the left of .app-content).
	   Clear it, mirroring nc-vue's .cn-dashboard-page__header rule. */
	padding-inline-start: 56px;
}

.agent-template-github-store__search {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.agent-template-github-store__search > * {
	min-width: 240px;
	flex: 1 1 240px;
}

.agent-template-github-store__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 12px;
}

.agent-template-github-store__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-template-github-store__card-title {
	font-weight: bold;
	display: flex;
	align-items: center;
	gap: 6px;
}

.agent-template-github-store__card-kind {
	font-weight: normal;
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	padding: 1px 8px;
}

.agent-template-github-store__card-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.agent-template-github-store__card-description {
	margin: 4px 0;
}
</style>
