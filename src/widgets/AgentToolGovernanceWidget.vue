<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentToolGovernanceWidget — combines the tool-grant editor and the
  tool-invocation audit history as one type:"detail" custom widget
  (manifest-driven-pages).

  Both are read/write surfaces over the SAME capability (ADR-063 derived tool
  catalogue: grants + the audit trail of their use) — merged into one widget so
  the grid gets one coherent "tool governance" cell instead of two thin strips
  (design.md Decision 3). Self-fetches the agent id from `$route.params.id`
  and the agent object (for the owner-only `canEdit` gate on ToolGrantEditor —
  the server enforces owner-only regardless of this client-side hint).

  @spec openspec/changes/manifest-driven-pages/specs/manifest-driven-pages/spec.md#req-005-a-tool-governance-custom-widget-must-combine-tool-grants-and-tool-activity-audit-history
-->
<template>
	<div class="agent-tool-governance-widget">
		<ToolGrantEditor
			:agent-id="agentId"
			:can-edit="isOwner"
			@saved="onGrantsSaved" />
		<ToolInvocationTable :agent-id="agentId" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import ToolGrantEditor from '../components/ToolGrantEditor.vue'
import ToolInvocationTable from '../components/ToolInvocationTable.vue'
import { useAgentStore } from '../store/store.js'

export default {
	name: 'AgentToolGovernanceWidget',

	components: {
		ToolGrantEditor,
		ToolInvocationTable,
	},

	data() {
		return {
			agent: null,
		}
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 */
		agentId() {
			return this.$route.params.id
		},

		/**
		 * Whether the current user owns this agent — the grant editor is
		 * read-only for everyone else (the server enforces owner-only on PUT
		 * .../tool-grants regardless; this only avoids offering an action that
		 * would be refused).
		 *
		 * @return {boolean} True when the current user is the agent's owner.
		 */
		isOwner() {
			const user = getCurrentUser()
			return !!(user && this.agent && this.agent.owner === user.uid)
		},
	},

	created() {
		this.agentStore = useAgentStore()
		this.agentStore.registerObjectType('agent', 'agent', 'hermiq')
		this.loadAgent()
	},

	methods: {
		/**
		 * Load this agent (only used to resolve `owner` for the isOwner gate).
		 *
		 * @return {Promise<void>}
		 */
		async loadAgent() {
			this.agent = await this.agentStore.fetchObject('agent', this.agentId).catch(() => null)
		},

		/**
		 * Reload the agent after tool grants are saved (agent's `tools` display
		 * on the sibling data widget reflects the change after its own reload).
		 *
		 * @return {Promise<void>}
		 */
		async onGrantsSaved() {
			await this.loadAgent()
		},
	},
}
</script>

<style scoped>
.agent-tool-governance-widget {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
