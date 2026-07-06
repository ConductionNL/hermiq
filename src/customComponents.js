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

import CustomExample from './views/CustomExample.vue'
import AgentCatalog from './views/AgentCatalog.vue'
import AgentDetail from './views/AgentDetail.vue'
import ApprovalInbox from './views/ApprovalInbox.vue'
import AgentMemory from './views/AgentMemory.vue'
import SkillsCatalog from './views/SkillsCatalog.vue'
import TenantOps from './views/TenantOps.vue'
// Features & Roadmap page — thin wrapper around the lib's
// CnFeaturesAndRoadmapView (in-product roadmap surface powered by
// OpenRegister's github-issue-proxy). Shipped wired-up so apps scaffolded
// from this template inherit the Settings-section "Features & roadmap"
// entry; change the repo fallback in views/FeaturesRoadmap.vue. See
// ConductionNL/hydra#251.

export default {
	// Example custom component. Keep or delete when scaffolding a new
	// app. The manifest does NOT reference this by default; it is
	// included so the registry's role is visible to first-time
	// cloners. Wire it up by adding a `type: "custom"` page entry to
	// `src/manifest.json` with `"component": "CustomExample"`.
	CustomExample,
	// Agent-management-ui pages (agent-management-ui change). Custom pages because
	// they need bespoke behaviour (agents resource + Run now + run history) that the
	// built-in index/detail page types cannot express.
	AgentCatalog,
	AgentDetail,
	// Approval inbox (human-approval-gate-ui change). Custom page: reviewer-scoped
	// pending Approvals + guarded approve/deny + org kill-switch — not expressible
	// via the built-in index page type.
	ApprovalInbox,
	// Agent memory (agent-memory change). Custom page: agent picker + tenant-scoped
	// Memory/Session objects + char-budget bar + consolidation nudge + OR-search recall.
	AgentMemory,
	// Skills catalog (skills-catalog change). Custom page: import/export agentskills.io
	// packages + install a skill onto an agent.
	SkillsCatalog,
	// Tenant ops (multi-tenant-ops change). Custom page: per-org quota + EU AI Act audit
	// export over OR objects/AuditTrail, capability-gated to org owners/admins.
	TenantOps,
	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView) — wired up
	// in src/manifest.json (the `FeaturesRoadmap` custom page + the
	// `FeaturesRoadmapMenu` settings entry).
}
