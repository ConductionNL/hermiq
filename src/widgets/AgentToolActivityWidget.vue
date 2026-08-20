<!--
  Tool activity, as its own widget.

  It used to be the second tab of the grants widget, which put two unrelated
  questions behind one heading: "what MAY this agent do" and "what HAS it done".
  They are read at different times by different people — one while granting,
  one while auditing — and a tab hides whichever you are not looking at. As
  peers on the page, both are visible at once.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="agent-tool-activity-widget">
		<!--
			The heading lives INSIDE the widget (manifest sets showTitle:false)
			for the same reason as its sibling: with the chrome title on it is a
			SIBLING of this root inside a display:block grid item, so height:100%
			resolves against the full cell and ignores the title above it, which
			overflows the cell by exactly the title's height (ADR-062).
		-->
		<h3 class="agent-tool-activity-widget__title">
			{{ t('hermiq', 'Tool activity') }}
		</h3>

		<div class="agent-tool-activity-widget__panel">
			<ToolInvocationTable :agentId="agentId" />
		</div>
	</div>
</template>

<script>
import ToolInvocationTable from '../components/ToolInvocationTable.vue'

export default {
	name: 'AgentToolActivityWidget',

	components: {
		ToolInvocationTable,
	},

	computed: {
		/**
		 * This agent's uuid from the route param.
		 *
		 * @return {string} The agent uuid.
		 *
		 * @spec openspec/specs/manifest-driven-pages/spec.md#requirement-a-tool-governance-custom-widget-must-combine-tool-grants-and-tool-activity-audit-history
		 */
		agentId() {
			return this.$route.params.id
		},
	},
}
</script>

<style scoped>
.agent-tool-activity-widget {
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	height: 100%;
	min-height: 0;
	overflow: hidden;
}

.agent-tool-activity-widget__title {
	margin: 0 0 8px;
	font-size: 1rem;
	font-weight: bold;
}

.agent-tool-activity-widget__panel {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
}
</style>
