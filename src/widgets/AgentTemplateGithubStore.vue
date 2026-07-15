<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentTemplateGithubStore — the "GitHub store" section of the Agent templates
  gallery (agent-template-github-store), resolved via
  `page.slots.below-header` on AgentTemplateGallery's generic `type:"index"`
  page (manifest-driven-pages) — sits between the page header and the
  existing template table, a close port of OpenBuild's `TemplateGallery.vue`
  GitHub section (search box, result cards, a credential hint).

  Searches `topic:hermiq-agent-template` repos anonymously by default;
  supplying a broker `github`-provider credential (from
  `/apps/openregister/api/credentials`, the SAME picker pattern
  LlmProviderModal.vue/WebResearchSettingsModal.vue already use) raises the
  anonymous rate limit and reaches private repos. Installing a card fetches
  the repo's portable template package and imports it through the EXISTING
  quarantine + content-scan gate (`AgentTemplateController::githubInstall()`
  -> `AgentTemplateService::importPackage(source: 'hub')`, unchanged) — the
  created template always lands `state: "quarantined"`, and shows up in the
  table below after a `cn:page:refresh` signal exactly like a pasted-package
  hub import.

  @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
  @spec openspec/changes/agent-template-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
-->
<template>
	<div class="agent-template-github-store">
		<h3 class="agent-template-github-store__heading">
			{{ t('hermiq', 'GitHub store') }}
		</h3>

		<div class="agent-template-github-store__search">
			<NcTextField :value.sync="query"
				:label="t('hermiq', 'Search hermiq-agent-template repos on GitHub')"
				:placeholder="t('hermiq', 'Search by name or keyword…')"
				@update:value="onQueryChange" />
			<NcSelect v-if="githubCredentials.length > 0"
				v-model="selectedCredential"
				:options="githubCredentials"
				:input-label="t('hermiq', 'GitHub credential')"
				:loading="loadingCredentials"
				:placeholder="t('hermiq', 'Anonymous (no credential)')"
				label="label"
				@input="doSearch" />
		</div>

		<NcNoteCard v-if="rateLimitHintVisible" type="warning">
			{{ t('hermiq', 'GitHub\'s anonymous search rate limit was reached. Add a GitHub credential under Settings → Agent credentials to raise the limit and see private repos.') }}
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
			:name="t('hermiq', 'No GitHub templates found')"
			:description="t('hermiq', 'Publish a template from a row below, or try a different search term.')" />
		<div v-else class="agent-template-github-store__cards">
			<div v-for="card in cards" :key="card.owner + '/' + card.repo" class="agent-template-github-store__card">
				<div class="agent-template-github-store__card-title">
					{{ card.name || (card.owner + '/' + card.repo) }}
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
					{{ t('hermiq', 'This repo does not carry a readable hermiq-agent-template.json — it cannot be installed.') }}
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

/** Debounce delay (ms) before a typed search term triggers a request. */
const SEARCH_DEBOUNCE_MS = 400

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
		 * Search GitHub for `topic:hermiq-agent-template` repos. Never throws — a
		 * rate-limited/unreachable search resolves to an empty card list plus an
		 * explicit outcome, surfaced as a non-blocking hint.
		 *
		 * @return {Promise<void>}
		 */
		async doSearch() {
			this.loading = true
			try {
				const result = await searchGithubTemplates(this.query, this.selectedCredential?.value || null)
				this.cards = result.cards || []
				this.outcome = result.outcome || 'ok'
				this.brokerCredentialAvailable = result.brokerCredentialAvailable === true
				this.rateLimited = result.rateLimited === true
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
		 * @return {string} `owner/repo`.
		 */
		cardKey(card) {
			return `${card.owner}/${card.repo}`
		},

		/**
		 * Install a discovered template. Lands quarantined + content-scanned via
		 * the existing hub-import gate; refreshes the page's index table so the
		 * new (quarantined) row appears below.
		 *
		 * @param {object} card The result card to install.
		 * @return {Promise<void>}
		 */
		async doInstall(card) {
			this.installError = ''
			this.installNotice = ''
			this.installingId = this.cardKey(card)
			try {
				await installGithubTemplate({
					owner: card.owner,
					repo: card.repo,
					credentialId: this.selectedCredential?.value || null,
				})
				this.installNotice = this.t('hermiq', 'Installed "{name}" — it is quarantined until reviewed.', { name: card.name || this.cardKey(card) })
				emit('cn:page:refresh', {})
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
}

.agent-template-github-store__card-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.agent-template-github-store__card-description {
	margin: 4px 0;
}
</style>
