<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentMemoryWidget — thin type:"detail" custom-widget adapter around the
  existing AgentMemoryPanel.vue, unchanged (manifest-driven-pages, task 2).

  AgentMemoryPanel.vue is prop-driven (`agent-id`) so it can serve BOTH the
  agent-picker-driven standalone `/memory` page (AgentMemory.vue) and this
  route-driven detail-page widget — this file supplies the one thing that
  differs between the two hosts (the agent id source), self-fetching it from
  `$route.params.id` (the manifest's `page.slots.widget-<id>` scoped slot only
  forwards `{ item, widget }`, not the loaded object). AgentMemoryPanel.vue
  itself is not modified.

  @spec openspec/specs/manifest-driven-pages/spec.md#requirement-agentdetail-renders-as-a-detail-type-widget-grid
-->
<template>
	<AgentMemoryPanel :agent-id="agentId" />
</template>

<script>
import AgentMemoryPanel from '../components/AgentMemoryPanel.vue'

export default {
	name: 'AgentMemoryWidget',

	components: {
		AgentMemoryPanel,
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
	},
}
</script>
