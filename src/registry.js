// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// v2 component registry for the manifest-driven app shell.
//
// This file is the v2 replacement for customComponents.js. Where
// customComponents.js only supported `type: "custom"` page components,
// this registry supports all five kinds defined in hydra ADR-036:
//
//   - widget       — placeable in any allowed slot via grid coords
//   - modal        — opened by action reference; not gridded externally
//   - page         — full-page custom component (escape hatch; keep near-zero)
//   - form-field   — custom property editor (auto-bound by format/property)
//   - cell-renderer — custom table-cell rendering (auto-bound by schema/property)
//
// Each entry: { kind, component, ...kindMetadata }
//
// Resolution at runtime:
//   1. Built-in widgets    (object-table, form-renderer, wiki-renderer, …)
//   2. This registry       ← consumer-injected components
//
// How to add a new widget:
//   1. Create src/widgets/<YourWidget>.vue.
//   2. Add an entry here with kind: "widget" + required metadata.
//   3. Reference it in src/manifest.json via widgetKey: "<your-key>".
//
// How to add a new modal:
//   1. Create src/modals/<YourModal>.vue.
//   2. Add an entry here with kind: "modal" + propsSchema.
//   3. Trigger it in manifest actions via type: "open-modal", target: "<your-key>".
//
// How to add a custom page:
//   1. Create src/views/<YourPage>.vue.
//   2. Add an entry here with kind: "page".
//   3. Add a manifest page entry with type: "custom", component: "<your-key>",
//      and a _note explaining why a standard page type was not feasible.
//
// See: https://codeberg.org/Conduction/hydra → openspec/architecture/adr-036-universal-widget-manifest.md

import AnalyticsKpiWidget from './widgets/AnalyticsKpiWidget.vue'
import AnalyticsBreakdownWidget from './widgets/AnalyticsBreakdownWidget.vue'
import EmailField from './formFields/EmailField.vue'
import Chat from './views/Chat.vue'
import AgentCatalog from './views/AgentCatalog.vue'
import AgentDetail from './views/AgentDetail.vue'
import ApprovalInbox from './views/ApprovalInbox.vue'
import AgentMemory from './views/AgentMemory.vue'
import AgentSessions from './views/AgentSessions.vue'
import SkillsCatalog from './views/SkillsCatalog.vue'
import AgentTemplateGallery from './views/AgentTemplateGallery.vue'
import AiFeatureRegister from './views/AiFeatureRegister.vue'
import TenantOps from './views/TenantOps.vue'
import McpTools from './views/McpTools.vue'
import ComplianceDashboard from './views/ComplianceDashboard.vue'
import EvalDatasets from './views/EvalDatasets.vue'

export default {
	// -------------------------------------------------------------------------
	// kind: "modal" — opened via actions[].type: "open-modal"
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// kind: "page" — full-page custom components (escape hatch; keep near-zero)
	//
	// PascalCase keys match the manifest's `component` field so the v1
	// customComponents.js entries work unchanged during the v1 → v2 transition.
	// -------------------------------------------------------------------------

	/**
	 * Chat — the AI chat page merged from OpenRegister's chat surface
	 * (agent-engine-port task 5.1): conversation list + streaming thread +
	 * composer + agent selector + feedback, against the Hermiq engine routes.
	 * Standard nav page, not a dashboard.
	 */
	Chat: {
		kind: 'page',
		component: Chat,
	},

	/**
	 * Agent catalog — the Hermiq main nav page (agent-management-ui). Standard
	 * nav page (not a dashboard), so no dashboard-in-dashboard nesting.
	 */
	AgentCatalog: {
		kind: 'page',
		component: AgentCatalog,
	},

	/**
	 * Agent detail — schedule attach/edit, Run now, and run history for one agent.
	 */
	AgentDetail: {
		kind: 'page',
		component: AgentDetail,
	},

	/**
	 * Approval inbox — the reviewer's pending human-approval-gate queue plus the
	 * org kill-switch (human-approval-gate-ui). Standard nav page, not a dashboard.
	 */
	ApprovalInbox: {
		kind: 'page',
		component: ApprovalInbox,
	},

	/**
	 * Agent memory — a selected agent's long-term memory (char budget + consolidation
	 * nudge) and OR-search recall (agent-memory). Standard nav page.
	 */
	AgentMemory: {
		kind: 'page',
		component: AgentMemory,
	},

	/**
	 * Agent sessions — a selected agent's recorded conversation sessions plus a
	 * turn-search (recall) box. Split out from Memory so chats are their own thing.
	 */
	AgentSessions: {
		kind: 'page',
		component: AgentSessions,
	},

	/**
	 * Skills catalog — browse tenant skills, import/export agentskills.io packages, and
	 * install a skill onto an agent (skills-catalog). Standard nav page.
	 */
	SkillsCatalog: {
		kind: 'page',
		component: SkillsCatalog,
	},

	/**
	 * Agent template gallery — browse/import/export portable agent definitions and
	 * "Use this template" to instantiate a real Agent (agent-template-gallery). Standard
	 * nav page, not a dashboard.
	 */
	AgentTemplateGallery: {
		kind: 'page',
		component: AgentTemplateGallery,
	},

	/**
	 * AI-feature governance register — the design-time inventory of high-risk AI
	 * features, risk-classified and gated by a DPO-acknowledgement lifecycle
	 * (ai-feature-governance-register). Standard nav page, not a dashboard.
	 */
	AiFeatureRegister: {
		kind: 'page',
		component: AiFeatureRegister,
	},

	/**
	 * Run-analytics KPIs (total runs, success rate, avg latency, tokens) — a
	 * dashboard widget over the computed /api/analytics endpoint.
	 */
	'analytics-kpis': {
		kind: 'widget',
		component: AnalyticsKpiWidget,
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 4, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'KPI values come from the computed /api/analytics endpoint (success rate, cost, latency aggregates), not from an OR object collection — stats-block can only bind object queries, so a custom fetch widget is required (ADR-049).',
	},

	/**
	 * Run-analytics detail — status breakdown + per-agent table — as a dashboard
	 * widget over the computed /api/analytics endpoint.
	 */
	'analytics-breakdown': {
		kind: 'widget',
		component: AnalyticsBreakdownWidget,
		defaultSize: { w: 12, h: 3 },
		minSize: { w: 4, h: 2 },
		maxSize: { w: 12, h: 6 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Renders the status breakdown + per-agent table from the computed /api/analytics endpoint; object-table cannot bind a computed aggregate endpoint, so a custom widget is required (ADR-049).',
	},

	/**
	 * Tenant ops — per-org quota usage + EU AI Act audit export (multi-tenant-ops).
	 * Standard nav page, capability-gated to org owners/admins.
	 */
	TenantOps: {
		kind: 'page',
		component: TenantOps,
	},

	/**
	 * MCP tools — the catalogue of Model Context Protocol tools available to
	 * agents across the instance (read-only). Standard nav page.
	 */
	McpTools: {
		kind: 'page',
		component: McpTools,
	},

	/**
	 * Compliance dashboard — per-framework (EU AI Act, ISO/IEC 42001, NIST AI RMF)
	 * coverage + gap list, computed live from existing governance data, plus an
	 * auditor's-pack export (compliance-control-packs). Standard nav page, not a
	 * dashboard-kind page (per-framework tables, not gridded widgets).
	 */
	ComplianceDashboard: {
		kind: 'page',
		component: ComplianceDashboard,
	},

	/**
	 * Evaluations — eval datasets and their governed, non-delivering agent runs with
	 * deterministic + LLM-as-judge scoring and a pass-rate/regression history
	 * (agent-evals). Standard nav page; the run action has no OR object equivalent.
	 */
	EvalDatasets: {
		kind: 'page',
		component: EvalDatasets,
	},

	// -------------------------------------------------------------------------
	// kind: "form-field" — custom property editors
	// -------------------------------------------------------------------------

	/**
	 * Email address input. Auto-bound by the form renderer to any JSON Schema
	 * property with format: "email". Replace or extend for your app's fields.
	 */
	'email-field': {
		kind: 'form-field',
		component: EmailField,
		appliesTo: {
			format: 'email',
		},
	},

	// -------------------------------------------------------------------------
	// kind: "cell-renderer" — custom table-cell rendering
	// -------------------------------------------------------------------------
}
