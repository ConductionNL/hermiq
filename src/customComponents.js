// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V1 custom-component registry — kept for backward-compatibility reference.
//
// *** V2 WAY: use src/registry.js instead. ***
//
// This file is the v1 "page-only" registry consumed by CnAppRoot's
// `customComponents` prop. It works for v1 manifests and during the
// v1 → v2 transition period. Once the app fully migrates to a v2 manifest
// and no longer needs the `customComponents` prop, this file can be removed
// and the import in main.js deleted.
//
// CnAppRoot will emit a console.warn once per mount when a v2 manifest is
// loaded alongside a non-empty `customComponents` prop. That is expected
// behaviour during the transition; it does not break anything.
//
// Every entry here has an equivalent `kind: "page"` entry in src/registry.js.
//
// Resolution order at runtime (v1 path):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See hydra ADR-036 for the v2 registry design.

import ApprovalInbox from './views/ApprovalInbox.vue'
import AgentMemory from './views/AgentMemory.vue'
import TenantOps from './views/TenantOps.vue'
import GuardrailPolicySettings from './views/GuardrailPolicySettings.vue'
import AlgorithmRegister from './views/AlgorithmRegister.vue'
import McpTools from './views/McpTools.vue'
import ComplianceDashboard from './views/ComplianceDashboard.vue'
// Features & Roadmap page — thin wrapper around the lib's
// CnFeaturesAndRoadmapView (in-product roadmap surface powered by
// OpenRegister's github-issue-proxy). Shipped wired-up so apps scaffolded
// from this template inherit the Settings-section "Features & roadmap"
// entry; change the repo fallback in views/FeaturesRoadmap.vue. See
// ConductionNL/hydra#251.

export default {
	// Approval inbox (human-approval-gate-ui change). Custom page: reviewer-scoped
	// pending Approvals + guarded approve/deny + org kill-switch — not expressible
	// via the built-in index page type.
	ApprovalInbox,
	// Agent memory (agent-memory change). Custom page: agent picker + tenant-scoped
	// Memory/Session objects + char-budget bar + consolidation nudge + OR-search recall.
	AgentMemory,
	// Tenant ops (multi-tenant-ops change). Custom page: per-org quota + EU AI Act audit
	// export over OR objects/AuditTrail, capability-gated to org owners/admins.
	TenantOps,
	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView) — wired up
	// in src/manifest.json (the `FeaturesRoadmap` custom page + the
	// `FeaturesRoadmapMenu` settings entry).
	//
	// inapp-settings-section: the Settings page's `type: "settings"` tabs
	// are rendered by CnSettingsPage, which resolves {type:"component"}
	// widgets against THIS map (`cnCustomComponents`), never the v2
	// `registry` (verified in CnSettingsPage.vue — it only ever injects
	// `cnCustomComponents`). So every Settings-tab component — new or
	// moved off a former top-level nav page — is registered here instead
	// of registry.js, even though registry.js is otherwise the v2 home for
	// everything else. See design.md Decision 4.
	//
	// Guardrail policy tab (agent-guardrails): extracted from
	// TenantOps.vue — governance policy administration, not a
	// per-organisation operational control.
	GuardrailPolicySettings,
	// Algorithm register tab (algoritmeregister-publication): the first
	// dedicated UI for the Algoritmeregister publication capability.
	AlgorithmRegister,
	// MCP tools tab — re-homed from the removed top-level `/mcp-tools` nav
	// page. Unchanged component.
	McpTools,
	// Compliance tab — re-homed from the removed top-level `/compliance` nav
	// page. Unchanged component.
	ComplianceDashboard,
}
