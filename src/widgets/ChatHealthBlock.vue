<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ChatHealthBlock — the chat health block above the Integrations page's
  connection list (spec-coverage-built-rows, row `op-health`), resolved via the
  page's `slots.below-header`. It says whether chat works, with the AI provider
  and the default model, and lists each of hermiq's connections with whether it
  answers and when it was checked.

  Read from `GET /api/chat/health`, the masked LLM settings and integriq's
  `app_connection` rows for app=hermiq. src/services/chatHealth.js picks the
  fields it shows, so no URL, credential or key reaches the page.

  @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
-->
<template>
	<section class="chat-health" :aria-label="t('hermiq', 'Chat health')">
		<div v-if="loading" class="chat-health__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else>
			<NcNoteCard :type="noteType">
				<p class="chat-health__headline">
					{{ headline }}
				</p>
				<p v-if="view.provider" class="chat-health__provider">
					{{ t('hermiq', 'AI provider: {provider}', { provider: view.provider }) }}
					<template v-if="view.model">
						· {{ t('hermiq', 'Default model: {model}', { model: view.model }) }}
					</template>
				</p>
			</NcNoteCard>
			<ul v-if="view.rows.length > 0" class="chat-health__rows">
				<li
v-for="row in view.rows"
					:key="row.id"
					class="chat-health__row"
					:data-state="row.state">
					<span class="chat-health__title">{{ row.title }}</span>
					<span class="chat-health__state" :class="'chat-health__state--' + row.state">
						{{ stateLabel(row.state) }}
					</span>
					<span class="chat-health__checked">
						{{ row.checkedAt ? t('hermiq', 'Checked {time}', { time: formatDate(row.checkedAt) }) : t('hermiq', 'Not checked yet') }}
					</span>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { getLlmSettings } from '../api/llm.js'
import { buildChatHealth } from '../services/chatHealth.js'
import { dateOrDash } from '../utils/displayDate.js'

export default {
	name: 'ChatHealthBlock',

	components: { NcLoadingIcon, NcNoteCard },

	data() {
		return {
			loading: true,
			view: buildChatHealth({ health: null, llm: null, connections: [] }),
		}
	},

	computed: {
		/**
		 * The one sentence that says whether chat works, and if not, why.
		 *
		 * @return {string} The headline.
		 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
		 */
		headline() {
			if (this.view.chatStatus === 'ok') {
				return this.t('hermiq', 'Chat works.')
			}
			if (this.view.chatStatus === 'unconfigured') {
				return this.t('hermiq', 'Chat does not work: no AI provider is chosen.')
			}
			return this.t('hermiq', 'Chat does not work: its settings could not be read.')
		},

		/**
		 * The note card's tone: success when chat works, warning when no provider is chosen, else error.
		 *
		 * @return {string} `success`, `warning` or `error`.
		 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
		 */
		noteType() {
			if (this.view.chatStatus === 'ok') {
				return 'success'
			}
			return this.view.chatStatus === 'unconfigured' ? 'warning' : 'error'
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		formatDate: dateOrDash,

		/**
		 * The label of one connection state.
		 *
		 * @param {string} state `yes`, `no` or `off`.
		 * @return {string} The label.
		 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
		 */
		stateLabel(state) {
			if (state === 'yes') {
				return this.t('hermiq', 'Answers')
			}
			if (state === 'no') {
				return this.t('hermiq', 'Does not answer')
			}
			return this.t('hermiq', 'Not set up')
		},

		/**
		 * Read the three sources; one that fails leaves its part empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/spec-coverage-built-rows/specs/observability/spec.md#REQ-SPCOV-012
		 */
		async load() {
			const [health, llm, connections] = await Promise.all([
				axios.get(generateUrl('/apps/hermiq/api/chat/health'))
					.then((response) => response.data)
					.catch(() => null),
				getLlmSettings().catch(() => null),
				axios.get(generateUrl('/apps/openregister/api/objects/integriq/app_connection'), {
					params: { app: 'hermiq', _limit: 100 },
				})
					.then((response) => response.data?.results ?? [])
					.catch(() => []),
			])
			this.view = buildChatHealth({ health, llm, connections })
			this.loading = false
		},
	},
}
</script>

<style scoped>
.chat-health {
	margin-bottom: 20px;
}

.chat-health__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.chat-health__headline {
	font-weight: bold;
}

.chat-health__provider {
	color: var(--color-text-maxcontrast);
}

.chat-health__rows {
	list-style: none;
	margin: 12px 0 0;
	padding: 0;
}

.chat-health__row {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 2fr);
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.chat-health__state--yes {
	color: var(--color-success-text);
}

.chat-health__state--no {
	color: var(--color-error-text);
	font-weight: bold;
}

.chat-health__state--off,
.chat-health__checked {
	color: var(--color-text-maxcontrast);
}
</style>
