<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  McpTools — the Hermiq "MCP tools" nav page.

  Lists the MCP tools discoverable across the instance that an agent can be given
  (Files/Contacts/Calendar/Deck/email plus every app that registers an
  IMcpToolProvider). Tools are read from Hermiq's facade-backed
  /api/agents/tools endpoint (agent-engine-port; the same source the
  agent-config tool picker uses), grouped by their source app so an operator
  can see what capabilities are available before wiring them onto an agent.

  A standard nav page — NOT a dashboard. Read-only: assigning a tool to an agent
  happens in the agent editor (AgentFormModal); this page is the catalogue.

  Renders through the shared CnDataTable so it matches the standard index-page design.
-->
<template>
	<div class="mcp-tools">
		<div class="mcp-tools__header">
			<h2 class="mcp-tools__heading">
				{{ t('hermiq', 'MCP tools') }}
			</h2>
		</div>

		<p class="mcp-tools__intro">
			{{
				t(
					'hermiq',
					'These Model Context Protocol tools are available across this Nextcloud. Give an agent one or more of them in the agent editor to let it act on your behalf.',
				)
			}}
		</p>

		<NcNoteCard
			v-if="error"
			type="error"
			:heading="t('hermiq', 'Could not load MCP tools')">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && tools.length === 0 && !error"
			:name="t('hermiq', 'No MCP tools available')"
			:description="
				t(
					'hermiq',
					'Install apps that register MCP tool providers (or enable Hermiq\'s native Nextcloud tools) to give agents capabilities.',
				)
			">
			<template #icon>
				<ToolboxIcon :size="20" />
			</template>
		</NcEmptyContent>

		<CnDataTable
			v-else
			:columns="columns"
			:rows="rows"
			:loading="loading"
			rowKey="id"
			:emptyText="t('hermiq', 'No MCP tools available')">
			<template #column-source="{ row }">
				<span class="mcp-tools__source">{{ row.source }}</span>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import ToolboxIcon from 'vue-material-design-icons/ToolboxOutline.vue'
import { listTools } from '../api/agents.js'

export default {
	name: 'McpTools',

	components: {
		CnDataTable,
		NcEmptyContent,
		NcNoteCard,
		ToolboxIcon,
	},

	data() {
		return {
			tools: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * Column definitions for the shared index table.
		 *
		 * @return {Array<object>} CnDataTable column descriptors.
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('hermiq', 'Tool') },
				{ key: 'description', label: this.t('hermiq', 'Description') },
				{ key: 'source', label: this.t('hermiq', 'Provided by') },
			]
		},

		/**
		 * Tools projected onto flat rows. Defensive about the descriptor shape:
		 * the agents/tools resource may key the source app under several names.
		 *
		 * @return {Array<object>} The table rows.
		 */
		rows() {
			return this.tools.map((tool, index) => ({
				id: tool.id || tool.name || `tool-${index}`,
				name: tool.name || tool.id || this.t('hermiq', 'Unnamed tool'),
				description: tool.description || '—',
				source:
					tool.appId || tool.app || tool.provider || tool.source || '—',
			}))
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load the MCP tools available to agents (RBAC-scoped server-side).
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.tools = await listTools()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.message
					|| this.t('hermiq', 'Unknown error')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.mcp-tools {
	padding: 20px;
	max-width: 960px;
	margin: 0 auto;
}

.mcp-tools__heading {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
	/* Settings-section custom page at the top of .app-content — clear the
	   Nextcloud nav toggle (44px), mirroring nc-vue's dashboard-header rule. */
	padding-inline-start: 56px;
}

.mcp-tools__intro {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}

.mcp-tools__source {
	font-family: monospace;
	font-size: 13px;
}
</style>
