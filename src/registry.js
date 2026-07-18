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
import QuotaUsageWidget from './widgets/QuotaUsageWidget.vue'
import EmailField from './formFields/EmailField.vue'
import Chat from './views/Chat.vue'
import ApprovalInbox from './views/ApprovalInbox.vue'
import AgentMemory from './views/AgentMemory.vue'
import AgentSessions from './views/AgentSessions.vue'
import TenantOps from './views/TenantOps.vue'
// manifest-driven-pages: AgentDetail's six extracted content widgets +
// the agent-memory wrapper (AgentMemoryPanel.vue itself stays unchanged).
import AgentKpiWidget from './widgets/AgentKpiWidget.vue'
import AgentSkillsWidget from './widgets/AgentSkillsWidget.vue'
import AgentToolGovernanceWidget from './widgets/AgentToolGovernanceWidget.vue'
import AgentRunOperationsWidget from './widgets/AgentRunOperationsWidget.vue'
import AgentRunHistoryWidget from './widgets/AgentRunHistoryWidget.vue'
import AgentMemoryWidget from './widgets/AgentMemoryWidget.vue'
// agent-related-files: AgentDetail's curated related-files section, built on the
// shared nc-vue CnRelatedFiles picker over the Context system (ADR-024).
import AgentFilesWidget from './widgets/AgentFilesWidget.vue'
// manifest-driven-pages: the Store page's (formerly AgentTemplateGallery,
// hermiq-github-store) row-actions widget + the EvalDatasetDetail page's sole
// content widget.
import AgentTemplateRowActions from './widgets/AgentTemplateRowActions.vue'
import EvalRunPanelWidget from './widgets/EvalRunPanelWidget.vue'
// skills-catalog: SkillsCatalog's row-actions widget (Approve/Export/Publish/
// Publish-to-GitHub/Install), the same pattern as agent-template-row-actions
// above.
import SkillRowActions from './widgets/SkillRowActions.vue'
// agent-template-github-store: the GitHub-backed store section on the unified
// Store page (formerly AgentTemplateGallery), resolved via
// page.slots.below-header. Generalised by hermiq-github-store to discover
// BOTH agent templates and skills behind a per-kind filter.
import AgentTemplateGithubStore from './widgets/AgentTemplateGithubStore.vue'
// inapp-settings-section: Incidents / EU AI Act audit export / Retention,
// moved off TenantOps.vue onto the Compliance index page's below-header slot.
import ComplianceOperations from './widgets/ComplianceOperations.vue'
// manifest-driven-pages: header-action modals, now resolved via the
// registry's open-modal path instead of being embedded page components.
import AgentFormModal from './modals/AgentFormModal.vue'
import AgentVersionHistoryDialog from './dialogs/agents/AgentVersionHistoryDialog.vue'
import AgentFactsheetDialog from './dialogs/AgentFactsheetDialog.vue'
import SetDefaultAgentDialog from './dialogs/SetDefaultAgentDialog.vue'
import TemplateImportModal from './modals/TemplateImportModal.vue'
import EvalDatasetFormModal from './modals/EvalDatasetFormModal.vue'

export default {
	// -------------------------------------------------------------------------
	// kind: "modal" — opened via actions[].type: "open-modal"
	// -------------------------------------------------------------------------

	/**
	 * Agent form — create/edit an Agent. Used by AgentDetail's "Edit agent"
	 * header action (self-fetches the agent from the route's `:id` when no
	 * `agent` prop is supplied — open-modal action props are static JSON,
	 * not resolved against the current object). AgentCatalog's built-in Add
	 * CTA instead mounts this same component via `customComponents.js` +
	 * the page's top-level `slots.form-dialog` (agent-form-slot), so
	 * CnIndexPage's own create/edit dialog dispatch is replaced entirely —
	 * see AgentFormModal's `item`/`close` props.
	 */
	'agent-form': {
		kind: 'modal',
		component: AgentFormModal,
		propsSchema: {
			type: 'object',
			properties: {
				show: { type: 'boolean' },
			},
		},
	},

	/**
	 * Agent version history — timeline, compare (mounts AgentVersionDiffDialog
	 * internally, task 3), and owner-gated rollback. Self-resolves the agent
	 * id (and, absent an explicit `canRollback`, the owner-only rollback gate)
	 * from the route when opened via AgentDetail's "Version history" action.
	 */
	'agent-version-history': {
		kind: 'modal',
		component: AgentVersionHistoryDialog,
		propsSchema: {
			type: 'object',
			properties: {
				show: { type: 'boolean' },
			},
		},
	},

	/**
	 * Agent compliance factsheet — read-only AI factsheet / model card
	 * (compliance-control-packs). Self-resolves the agent id from the route
	 * when opened via AgentDetail's "View compliance factsheet" action.
	 */
	'agent-factsheet': {
		kind: 'modal',
		component: AgentFactsheetDialog,
		propsSchema: {
			type: 'object',
			properties: {
				show: { type: 'boolean' },
			},
		},
	},

	/**
	 * Set-as-default — make the agent on this detail page the user's default
	 * companion agent (companion-default-agent). Self-resolves the agent id from
	 * the route when opened via AgentDetail's "Set as default" action; writes the
	 * same per-user `default-agent` preference the personal-settings picker uses.
	 */
	'set-default-agent': {
		kind: 'modal',
		component: SetDefaultAgentDialog,
		propsSchema: {
			type: 'object',
			properties: {
				show: { type: 'boolean' },
			},
		},
	},

	/**
	 * Template import — paste an agent-template-gallery JSON package, local or
	 * quarantined-from-another-org. Opened via the Store page's (formerly
	 * AgentTemplateGallery) "Import template" header action.
	 */
	'template-import': {
		kind: 'modal',
		component: TemplateImportModal,
		propsSchema: { type: 'object', properties: {} },
	},

	/**
	 * Eval dataset form — create/edit an EvalDataset (name + ordered cases).
	 * Opened via EvalDatasets' "New dataset" header action (target names the
	 * component directly, mirroring shillinq's PascalCase modal-key
	 * convention for entries with no shorter alias).
	 */
	EvalDatasetFormModal: {
		kind: 'modal',
		component: EvalDatasetFormModal,
		propsSchema: {
			type: 'object',
			properties: {
				show: { type: 'boolean' },
			},
		},
	},

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
	 * Tenant ops — EU AI Act audit export + org-level operational sections
	 * (multi-tenant-ops). Standard nav page, capability-gated to org owners/admins.
	 * Per-org quota usage moved to the Dashboard (dashboard-org-widgets) — see
	 * "quota-usage" below.
	 */
	TenantOps: {
		kind: 'page',
		component: TenantOps,
	},

	/**
	 * Quota usage — the caller's organisation Schedules and Agents-in-use quota
	 * (count vs. configured limit, at-limit warning) as a Dashboard widget
	 * (dashboard-org-widgets), relocated off TenantOps.vue.
	 */
	'quota-usage': {
		kind: 'widget',
		component: QuotaUsageWidget,
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 4, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'The quota\'s atLimit is derived by TenantOpsService::quotaStatus() from a configured limit compared against a derived (distinct-agentId) count, not a plain OR object-count aggregate — stats-block can only bind a dataSource to an object-count query, so a custom fetch widget is required (ADR-049), mirroring analytics-kpis/analytics-breakdown.',
	},

	// -------------------------------------------------------------------------
	// kind: "widget" — AgentDetail's type:"detail" content widgets
	// (manifest-driven-pages). Each resolved via page.slots.widget-<id>,
	// procest CaseDetail's exact InitiatorSection pattern — self-fetches the
	// agent id from `$route.params.id` since that scoped slot only forwards
	// `{ item, widget }`, not the loaded object.
	// -------------------------------------------------------------------------

	/**
	 * Agent-scoped run KPIs (total runs, success rate, avg latency, tokens) —
	 * reuses AnalyticsKpiWidget's /api/analytics endpoint, scoped to this
	 * agent's id instead of tenant-wide. Not a stats-block — the analytics
	 * endpoint is a computed aggregate, not an OR object-count query (same
	 * ADR-049 rationale as analytics-kpis above).
	 */
	'agent-kpis': {
		kind: 'widget',
		component: AgentKpiWidget,
		defaultSize: { w: 6, h: 2 },
		minSize: { w: 4, h: 2 },
		maxSize: { w: 12, h: 3 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'KPI values come from the computed /api/analytics endpoint (agent-scoped), not from an OR object collection — stats-block can only bind object queries, so a custom fetch widget is required (ADR-049), mirroring analytics-kpis.',
	},

	/**
	 * Skills attach/detach — the agent's skillInstalls array against the
	 * tenant skills catalogue. `skillInstalls` is an array-of-uuid field
	 * referencing an independent Skill catalogue — the reverse of an
	 * object-list's FK-child-collection shape, so it can't be expressed
	 * declaratively either.
	 */
	'agent-skills': {
		kind: 'widget',
		component: AgentSkillsWidget,
		defaultSize: { w: 6, h: 3 },
		minSize: { w: 4, h: 2 },
		maxSize: { w: 12, h: 6 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'skillInstalls is an array-of-uuid field referencing an independent Skill catalogue (the reverse of an object-list FK-child-collection shape); attach/detach are guarded install/uninstall endpoints, not a declarative object-op (ADR-049).',
	},

	/**
	 * Tool governance — combines the schema-scoped tool-grant editor
	 * (ToolGrantEditor) and the EU AI Act art.12/14 tool-invocation audit
	 * table (ToolInvocationTable) for one agent: both read/write surfaces
	 * over the SAME ADR-063 derived catalogue capability.
	 */
	'agent-tool-governance': {
		kind: 'widget',
		component: AgentToolGovernanceWidget,
		defaultSize: { w: 6, h: 5 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 10 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Grants (taggable free-text grammar over a live ADR-063 catalogue) and the append-only tool-invocation audit trail are bespoke read/write surfaces no built-in widget (object-list, stats-block) expresses (ADR-049).',
	},

	/**
	 * Run operations — schedule attach/edit, Dry run, Run now, the pre-run
	 * cost estimate, agent-scoped budget status, and the webhook trigger. All
	 * read/write the SAME schedule object and share previewResult/runError
	 * state across dry-run and run-now — the manifest grid has no
	 * cross-widget state channel, so these stay one widget (design.md
	 * Decision 3).
	 */
	'agent-run-operations': {
		kind: 'widget',
		component: AgentRunOperationsWidget,
		defaultSize: { w: 6, h: 6 },
		minSize: { w: 4, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Schedule attach/edit + dry-run/run-now + budget + webhook trigger share tightly-coupled state (previewResult/runError) across actions with no OR-object read equivalent (run-now, dry-run, webhook secret lifecycle) — genuinely bespoke (ADR-049).',
	},

	/**
	 * Run history — this agent's schedule run history with per-row trace
	 * expand-and-cache, a dead-letter-only Re-run, and a Replay (dry-run
	 * replay + inline diff preview). object-list's static columns/rowRoute
	 * shape has no per-row expand-in-place, no per-row conditional action
	 * set, and no trace fetch-and-cache.
	 */
	'agent-run-history': {
		kind: 'widget',
		component: AgentRunHistoryWidget,
		defaultSize: { w: 12, h: 5 },
		minSize: { w: 6, h: 3 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Per-row trace expand-and-cache, a dead_letter-only Re-run, and a Replay dry-run + diff preview are bespoke interactions object-list (static columns/rowRoute, no per-row expand or conditional actions) cannot express (ADR-049).',
	},

	/**
	 * Agent memory — thin self-fetching adapter around the UNCHANGED
	 * AgentMemoryPanel.vue (also used by the standalone agent-picker-driven
	 * /memory page), supplying the one thing that differs between the two
	 * hosts: the agent id source (route param here, not a picker).
	 */
	'agent-memory': {
		kind: 'widget',
		component: AgentMemoryWidget,
		defaultSize: { w: 6, h: 5 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 10 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Wraps the existing AgentMemoryPanel.vue (char-budget bar, consolidation nudge, add-a-fact, entries) unchanged — no built-in widget renders a bespoke budget bar + nudge action over a non-OR-collection endpoint (ADR-049).',
	},

	/**
	 * Agent related files — a curated, Claude-project-style list of existing
	 * Nextcloud files this agent can scan and use (item 7), built on the shared
	 * nc-vue CnRelatedFiles picker. Persisted onto an agent-owned Context
	 * bundle's files[] array (referenced from agent.contextRefs) via the guarded
	 * AgentFilesController, so the files ride the existing ContextAssembler
	 * preamble path at run start (ADR-024) — distinct from chat attachments.
	 */
	'agent-files': {
		kind: 'widget',
		component: AgentFilesWidget,
		defaultSize: { w: 12, h: 4 },
		minSize: { w: 4, h: 2 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'A curated related-files list persisted onto an agent-owned Context bundle (Context.files[] referenced from agent.contextRefs) via guarded add/remove endpoints that also maintain contextRefs (PUT-guarded) — not a declarative object-op: the files ride the existing ContextAssembler run-start preamble path (ADR-024, ADR-049), and the picker UI is the shared nc-vue CnRelatedFiles component.',
	},

	/**
	 * Agent-template row actions — "Use this template" / "Approve"
	 * (quarantined only) / "Export" / "Publish to GitHub", resolved via the
	 * Store page's (formerly AgentTemplateGallery) `page.slots.row-actions`.
	 * Calls the existing guarded AgentTemplateController endpoints unchanged —
	 * approving a quarantined template gates through
	 * `ActionAuthService::requireAction('agenttemplate.approve-quarantined')`
	 * server-side, a check the generic OR object-patch path does not express
	 * (design.md Decision 6); never a declarative object-op.
	 */
	'agent-template-row-actions': {
		kind: 'widget',
		component: AgentTemplateRowActions,
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 6, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['body'],
		propsSchema: {
			type: 'object',
			properties: {
				row: { type: 'object' },
			},
		},
		_note: 'Approve/Use-this-template/Export call guarded, action-authorized Hermiq endpoints (agenttemplate.approve-quarantined, model-policy coercion on instantiate) that a declarative object-op patch would bypass entirely (ADR-049, design.md Decision 6).',
	},

	/**
	 * Skill row actions — "Approve" (quarantined only), "Export", "Publish"
	 * (OpenConnector hub, secondary), "Publish to GitHub" (hermiq-github-store,
	 * primary) and "Install on agent" (lazy agent picker), resolved via
	 * SkillsCatalog's `page.slots.row-actions`. Calls the existing tenant-
	 * scoped SkillController/SkillMarketplaceController endpoints
	 * (src/api/skills.js) unchanged — never a declarative object-op patch,
	 * mirroring agent-template-row-actions.
	 */
	'skill-row-actions': {
		kind: 'widget',
		component: SkillRowActions,
		defaultSize: { w: 12, h: 1 },
		minSize: { w: 6, h: 1 },
		maxSize: { w: 12, h: 2 },
		allowedSlots: ['body'],
		propsSchema: {
			type: 'object',
			properties: {
				row: { type: 'object' },
			},
		},
		_note: 'Approve/Export/Publish/Publish-to-GitHub/Install call tenant-scoped SkillController/SkillMarketplaceController endpoints (skills-catalog, skills-marketplace, hermiq-github-store) — installedOn association, the quarantine review-gate approval, and the broker-mediated GitHub publish are not expressible via a declarative object-op patch (ADR-049).',
	},

	/**
	 * Agent-template GitHub store (agent-template-github-store, generalised by
	 * hermiq-github-store) — the "GitHub store" discovery section of the
	 * unified Store page (formerly AgentTemplateGallery), resolved via
	 * `page.slots.below-header`. Searches/installs against
	 * AgentTemplateController's githubSearch/githubInstall endpoints AND (per
	 * the active kind filter) SkillController's githubSearch/githubInstall
	 * endpoints — a GitHub REST search + broker-mediated install, not an
	 * OpenRegister object collection.
	 */
	'agent-template-github-store': {
		kind: 'widget',
		component: AgentTemplateGithubStore,
		defaultSize: { w: 12, h: 4 },
		minSize: { w: 6, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'GitHub topic-search + broker-mediated install/publish flow (GitHubTemplateCatalogService/GitHubTemplatePushService) has no OpenRegister object-collection equivalent — object-table/object-list cannot express a third-party REST search or a credential-broker push (ADR-049).',
	},

	/**
	 * Compliance operations (inapp-settings-section) — Incidents, EU AI Act
	 * audit export, and Retention, resolved via the Compliance index page's
	 * `page.slots.below-header`. Ported unchanged from TenantOps.vue, which
	 * now retains only true per-organisation operational controls (cost
	 * guardrails, model policy, access review).
	 */
	'compliance-operations': {
		kind: 'widget',
		component: ComplianceOperations,
		defaultSize: { w: 12, h: 6 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Incident records, the AI Act audit export, and the retention statement are governance actions (createIncident/audit-export/retention endpoints) with no OpenRegister object-collection equivalent — object-list/stats-block cannot express them (ADR-049), mirroring agent-template-github-store\'s below-header placement.',
	},

	/**
	 * Eval run panel — one eval dataset's agent-picker + Run + run history, the
	 * sole content widget on the new EvalDatasetDetail page. "Run" has no OR
	 * object equivalent (the one bespoke src/api/evals.js action; every other
	 * eval path is object CRUD).
	 */
	'eval-run-panel': {
		kind: 'widget',
		component: EvalRunPanelWidget,
		defaultSize: { w: 12, h: 5 },
		minSize: { w: 6, h: 3 },
		maxSize: { w: 12, h: 10 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Running a dataset against an agent is a governed, non-delivering Hermiq action (EvalRunController) with no OpenRegister object-trigger equivalent — object-list/object-op cannot express it (ADR-049).',
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
