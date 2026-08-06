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
import McpTools from './views/McpTools.vue'
import ComplianceDashboard from './views/ComplianceDashboard.vue'
import AgentFormModal from './modals/AgentFormModal.vue'
// Skill form (skill-form-slot, hermiq-skill-markdown-authoring): resolved by
// SkillsCatalog's top-level `slots.form-dialog` -> "SkillFormModal", so
// CnIndexPage's built-in Add CTA + row-edit mount the markdown-authoring form
// (CnMarkdownEditor body, files editor) in place of the generic schema-driven
// create/edit dialog — the skills analogue of AgentFormModal above.
import SkillFormModal from './modals/SkillFormModal.vue'
// NOTE — Features & Roadmap is NOT registered here, deliberately. The
// manifest page `FeaturesRoadmap` is `type: "roadmap"`, a BUILT-IN page type
// that CnPageRenderer resolves from `defaultPageTypes` (→ the lib's
// CnFeaturesAndRoadmapPage). Only `type: "custom"` pages are looked up in this
// map, so an app-local wrapper here would never be mounted. The lib's page
// reads the very same `features_roadmap_repo` / `_features` / `_disabled`
// initial-state keys, so there is nothing left for a wrapper to add.
// See ConductionNL/hydra#251.

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
	// MCP tools tab — re-homed from the removed top-level `/mcp-tools` nav
	// page. Unchanged component.
	McpTools,
	// Compliance — RETAINED DELIBERATELY, though currently unreachable.
	//
	// manifest-driven-pages converted the `Compliance` page from this bespoke
	// component to `type: "index"` + the `compliance-operations` widget, so
	// CnPageRenderer no longer resolves this key (built-in types never consult
	// this map). It is therefore bundled but never mounted.
	//
	// It is NOT deleted because it is the only caller of `getComplianceExport()`
	// — the compliance auditor's-pack export (`GET /api/compliance/export`,
	// still a live registered route). `compliance-operations` ships the EU AI
	// Act audit export, which is a DIFFERENT endpoint; the auditor's pack has no
	// replacement surface. Deleting this would silently drop a user-facing
	// capability. Either re-home the export onto `compliance-operations` or
	// retire the route + this component together — a product decision.
	ComplianceDashboard,
	// Agent form (agent-form-slot): resolved by AgentCatalog's top-level
	// `slots.form-dialog` -> "AgentFormModal", so CnIndexPage's built-in
	// Add CTA mounts the rich agent form (with CnIconPicker) in place of
	// the generic schema-driven create/edit dialog. Also still registered
	// as the `agent-form` v2 modal in registry.js for AgentDetail's
	// route-based "Edit agent" open-modal action.
	AgentFormModal,
	// Skill form (skill-form-slot): resolved by SkillsCatalog's top-level
	// `slots.form-dialog` -> "SkillFormModal" (see the import above).
	SkillFormModal,
}
