// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Hermiq agent render leaf — JS registration (agent-object-leaf, ADR-019).
 *
 * This is the always-loaded bundle Application.php attaches on EVERY Nextcloud
 * page via `\OCP\Util::addInitScript('hermiq', 'hermiq-agent-leaf')`. It registers
 * the `hermiq-agent` integration under the SAME id as the server-side
 * `LeafDescriptor` (RegisterAgentLeafListener), so an Agent tab + widget appears
 * on any OpenRegister object in any OpenBuild app that renders the integration
 * registry.
 *
 * `registerIntegration()` is the load-order-safe entry point: when OpenRegister's
 * bundle has not installed the real registry yet, the call is queued on a stub and
 * replayed on install (ADR-019 cross-Vue-bundle trap, openregister#1958). This
 * bundle MUST NOT call `installIntegrationRegistry()` — that would clobber OR's
 * singleton.
 *
 * Render-only: the tab reuses the tool-free `converse` endpoint; the widget reads
 * OR's audit trail and POSTs the single governed run-on-object command. No agent
 * logic lives here.
 *
 * @spec openspec/changes/hermiq-agent-leaf/specs/agent-object-leaf/spec.md#requirement-agent-integration-leaf-registration
 */

import { registerIntegration } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import CnAgentChatTab from './components/CnAgentChatTab/CnAgentChatTab.vue'
import CnAgentRunsWidget from './components/CnAgentRunsWidget/CnAgentRunsWidget.vue'

registerIntegration({
	id: 'hermiq-agent',
	label: t('hermiq', 'Agent'),
	appName: t('hermiq', 'Hermiq'),
	icon: 'RobotOutline',
	requiredApp: 'hermiq',
	order: 60,
	group: 'workflow',
	referenceType: 'hermiq-agent',
	tab: CnAgentChatTab,
	widget: CnAgentRunsWidget,
	defaultSize: { w: 4, h: 4 },
})
