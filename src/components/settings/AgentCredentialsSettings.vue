<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AgentCredentialsSettings — the Hermiq Settings "Agent credentials" tab
  (agent-credentials).

  Lets a user set up hermiq's LLM-provider/tool credentials at both a
  personal level (their own broker credential) and an organisation level (an
  org admin's shared broker credential), on top of OpenRegister's existing
  credential broker — apps hold no secrets; a credentialRef UUID is stored,
  never the secret itself. Both surfaces are the shared, already-tested
  `CnCredentials` component (never a hand-rolled credential form): one
  mounted `scope="personal"`, one `scope="organisation"`. Hermiq's manifest
  `credentials[]` declarations (openai, fireworks, github) drive the
  "What Hermiq uses" informational list `CnCredentials` renders under its
  personal-scope intro.

  Mounted as a Settings-tab widget via
  {type:"component", componentName:"AgentCredentialsSettings"}
  (src/customComponents.js) — brings its own heading, the same contract
  GuardrailPolicySettings.vue/McpTools.vue/ComplianceDashboard.vue already
  satisfy for their own Settings tabs.

  @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-manifest-declared-credential-requirements
  @spec openspec/changes/agent-credentials/specs/agent-credentials/spec.md#requirement-personal-and-organisation-credential-management-surfaces
-->
<template>
	<div class="agent-credentials-settings">
		<h2 class="agent-credentials-settings__heading">
			{{ t('hermiq', 'Agent credentials') }}
		</h2>
		<p class="agent-credentials-settings__intro">
			{{ t('hermiq', 'Bring your own provider key, or let your organisation share one. Every call still goes through the OpenRegister credential broker — Hermiq never sees or stores the secret itself.') }}
		</p>

		<!--
			CnCredentials labels each of its own sections ("Your credentials" /
			"Organisation credentials") internally — no duplicate heading is
			added here, only spacing between the two scopes.
		-->
		<section class="agent-credentials-settings__section">
			<CnCredentials
				scope="personal"
				:app-id="appId"
				:app-name="appName"
				:app-credentials="appCredentials" />
		</section>

		<section class="agent-credentials-settings__section">
			<CnCredentials
				scope="organisation"
				:app-id="appId"
				:app-name="appName"
				:app-credentials="appCredentials" />
		</section>
	</div>
</template>

<script>
import { CnCredentials } from '@conduction/nextcloud-vue'
import manifest from '../../manifest.json'

export default {
	name: 'AgentCredentialsSettings',

	components: {
		CnCredentials,
	},

	data() {
		return {
			appId: 'hermiq',
			appName: 'Hermiq',
			// The manifest's own credentials[] declarations (provider/reason/scopes) —
			// rendered read-only by CnCredentials under its personal-scope intro as
			// "What Hermiq uses".
			appCredentials: manifest.credentials || [],
		}
	},
}
</script>

<style scoped>
.agent-credentials-settings__heading {
	margin: 0 0 8px;
}

.agent-credentials-settings__intro {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0 0 16px;
}

.agent-credentials-settings__section {
	margin-bottom: 24px;
}
</style>
