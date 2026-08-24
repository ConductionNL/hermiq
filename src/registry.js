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
// See: https://github.com/ConductionNL/hydra → openspec/architecture/adr-036-universal-widget-manifest.md

import AgentFactsheetDialog from './dialogs/AgentFactsheetDialog.vue'
import AgentVersionHistoryDialog from './dialogs/agents/AgentVersionHistoryDialog.vue'
import EmailField from './formFields/EmailField.vue'
// manifest-driven-pages: header-action modals, now resolved via the
// registry's open-modal path instead of being embedded page components.
import AgentFormModal from './modals/AgentFormModal.vue'
import EvalDatasetFormModal from './modals/EvalDatasetFormModal.vue'
import TemplateImportModal from './modals/TemplateImportModal.vue'
import AgentMemory from './views/AgentMemory.vue'
import ApprovalInbox from './views/ApprovalInbox.vue'
import Chat from './views/Chat.vue'
import FlowBuilder from './views/FlowBuilder.vue'
import FlowIndex from './views/FlowIndex.vue'
import FlowSidebar from './views/FlowSidebar.vue'
import TenantOps from './views/TenantOps.vue'
import AgentEvalBaselineWidget from './widgets/AgentEvalBaselineWidget.vue'
import AgentMemoryWidget from './widgets/AgentMemoryWidget.vue'
import AgentRunHistoryWidget from './widgets/AgentRunHistoryWidget.vue'
import AgentRunOperationsWidget from './widgets/AgentRunOperationsWidget.vue'
// manifest-driven-pages: AgentDetail's six extracted content widgets +
// the agent-memory wrapper (AgentMemoryPanel.vue itself stays unchanged).
import AgentSkillsWidget from './widgets/AgentSkillsWidget.vue'
// agent-template-github-store: the GitHub-backed store section on the unified
// Store page (formerly AgentTemplateGallery), resolved via
// page.slots.below-header. Generalised by hermiq-github-store to discover
// BOTH agent templates and skills behind a per-kind filter.
import AgentTemplateGithubStore from './widgets/AgentTemplateGithubStore.vue'
// manifest-driven-pages: the Store page's (formerly AgentTemplateGallery,
// hermiq-github-store) row-actions widget + the EvalDatasetDetail page's sole
// content widget.
import AgentTemplateRowActions from './widgets/AgentTemplateRowActions.vue'
import AgentToolActivityWidget from './widgets/AgentToolActivityWidget.vue'
import AgentToolGovernanceWidget from './widgets/AgentToolGovernanceWidget.vue'
import AnalyticsBreakdownWidget from './widgets/AnalyticsBreakdownWidget.vue'
// inapp-settings-section: Incidents / EU AI Act audit export / Retention,
// moved off TenantOps.vue onto the Compliance index page's below-header slot.
import ComplianceOperations from './widgets/ComplianceOperations.vue'
import EvalRunPanelWidget from './widgets/EvalRunPanelWidget.vue'
// skill-self-improvement: the SkillDetail draft review surface (side-by-side
// diff, provenance, verdicts, Accept/Edit/Reject) and the version history +
// rollback + republish widget.
import SkillDraftReview from './widgets/SkillDraftReview.vue'
import SkillEvalEvidence from './widgets/SkillEvalEvidence.vue'
// skill-learnings: the SkillDetail page's read-only Learnings card (rendered
// learnings.md + l6 activity strip; honest empty state; no edit affordance).
import SkillLearnings from './widgets/SkillLearnings.vue'
// skill-evals: the EvalDatasetDetail page's skill link/unlink panel, the
// SkillDetail page's L5 eval-evidence card (+ Run paired eval action), and the
// AgentDetail widget holding evalBaselineMode with its info affordance.
import SkillLinkPanel from './widgets/SkillLinkPanel.vue'
// skill-maturity: the SkillDetail page's durable maturity scorecard widget
// (per-level pass/fail + reasons + Qualify + action-gated Attest-L4).
import SkillMaturityScorecard from './widgets/SkillMaturityScorecard.vue'
// skill-install-idempotency: the SkillDetail page's origin + review-status card
// (where the skill came from, when it was last refreshed, why it is quarantined,
// and whether local learnings are ahead of the source).
import SkillProvenance from './widgets/SkillProvenance.vue'
// skills-catalog: SkillsCatalog's row-actions widget (Approve/Export/Publish/
// Publish-to-GitHub/Install), the same pattern as agent-template-row-actions
// above.
import SkillRowActions from './widgets/SkillRowActions.vue'
import SkillVersionHistory from './widgets/SkillVersionHistory.vue'

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
	 * Per-org quota usage moved to the Dashboard (dashboard-org-widgets) and is
	 * no longer a registry widget at all — as of 2026-08-13 both quota tiles are
	 * declarative `type:"stat"` entries in the manifest using CnStatWidget's
	 * `limitField`, and QuotaStatWidget.vue is deleted.
	 */
	TenantOps: {
		kind: 'page',
		component: TenantOps,
	},

	/**
	 * The list of hermiq's flows. A custom page rather than a `type:index`
	 * because a `type:index` is an OBJECT index bound to a register+schema, and
	 * a flow is not an object (`flow-storage/spec.md`) — there is no pair to
	 * point it at. It still renders CnIndexPage; only the source is different.
	 */
	FlowIndex: {
		kind: 'page',
		component: FlowIndex,
	},

	/**
	 * Visual editor for the flows the engine walks. A custom page because it is
	 * a canvas over a node/edge document, not a record list — no built-in page
	 * type (index/detail/dashboard) can express direct-manipulation authoring.
	 * Browsing/searching flows is the sibling FlowIndex page; this one is
	 * reached per-flow from it. Geometry comes from the shared canvas in
	 * nc-vue; the place cards, step routing and run/trace are hermiq's.
	 */
	FlowBuilder: {
		kind: 'page',
		component: FlowBuilder,
	},

	/**
	 * The flow editor's controls, resolved via FlowDetail's `sidebarComponent`
	 * so CnPageRenderer hands it to CnAppRoot's #sidebar slot — Nextcloud's
	 * real app sidebar, the same place CnObjectSidebar renders. Shares state
	 * with the canvas through the flow-editor store.
	 */
	FlowSidebar: {
		kind: 'page',
		component: FlowSidebar,
	},

	// -------------------------------------------------------------------------
	// kind: "widget" — AgentDetail's type:"detail" content widgets
	// (manifest-driven-pages). Each resolved via page.slots.widget-<id>,
	// procest CaseDetail's exact InitiatorSection pattern — self-fetches the
	// agent id from `$route.params.id` since that scoped slot only forwards
	// `{ item, widget }`, not the loaded object.
	// -------------------------------------------------------------------------

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
	 * Tool grants — the (cluster, subject, verb) matrix for one agent.
	 *
	 * The tool-invocation audit trail used to be this widget's second tab and
	 * is now its own peer (`agent-tool-activity`): "what MAY this agent do" and
	 * "what HAS it done" are read at different times by different people, and a
	 * tab hides whichever one you are not looking at.
	 */
	'agent-tool-governance': {
		kind: 'widget',
		component: AgentToolGovernanceWidget,
		defaultSize: { w: 12, h: 6 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'A grant matrix over the live ADR-063 catalogue, clustered by owning app and rowed by schema — a bespoke read/write surface no built-in widget (object-list, stats-block) expresses (ADR-049).',
	},

	/**
	 * Tool activity — the EU AI Act art.12/14 append-only tool-invocation
	 * audit table for one agent. Split out of `agent-tool-governance` so the
	 * audit trail is visible at the same time as the grants that produced it.
	 */
	'agent-tool-activity': {
		kind: 'widget',
		component: AgentToolActivityWidget,
		defaultSize: { w: 6, h: 5 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 10 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'An append-only, agent-scoped invocation trail (EU AI Act art.12/14) that no built-in widget expresses (ADR-049).',
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
	 * Skill maturity scorecard (skill-maturity) — the SkillDetail page's sole
	 * content widget: seven per-level pass/fail rows with reasons + evidence
	 * timestamps, the owner-guarded Qualify action, and the action-gated
	 * Attest-L4 form. Qualify/attest are bespoke Hermiq endpoints (recompute
	 * + ADR-023 action gate), not OR object CRUD, so a custom widget is
	 * required (ADR-049).
	 */
	'skill-maturity-scorecard': {
		// @custom-widget-ratchet exclude seven-level pass/fail scorecard with per-level reasons plus the owner-guarded Qualify and ADR-023 action-gated Attest-L4 calls (bespoke SkillMaturityController endpoints) — object-table/stats-block bind OR object collections/counts and cannot render a computed scorecard or trigger these gated actions.
		kind: 'widget',
		component: SkillMaturityScorecard,
		defaultSize: { w: 12, h: 6 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Self-fetches the skill from $route.params.id (the eval-run-panel pattern); the maturity scorecard, qualify and attest-l4 are bespoke SkillMaturityController endpoints, not expressible as object-table/object-op (ADR-049).',
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
		_note: "Incident records, the AI Act audit export, and the retention statement are governance actions (createIncident/audit-export/retention endpoints) with no OpenRegister object-collection equivalent — object-list/stats-block cannot express them (ADR-049), mirroring agent-template-github-store's below-header placement.",
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

	/**
	 * Skill link/unlink panel on EvalDatasetDetail (skill-evals): plain
	 * `skillRefs` object writes through the generic store — the picker offers
	 * the caller's visible active skills.
	 */
	'skill-link-panel': {
		// @custom-widget-ratchet exclude reverse-FK link/unlink picker writing EvalDataset.skillRefs against the independent Skill catalogue — object-list renders FK child collections and has no cross-schema picker affordance, so no built-in widget can express this surface.
		kind: 'widget',
		component: SkillLinkPanel,
		defaultSize: { w: 12, h: 4 },
		minSize: { w: 6, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'skillRefs is an array-of-uuid relation on EvalDataset referencing the independent Skill catalogue — the reverse of an object-list FK-child-collection shape (the agent-skills precedent), so it stays a custom widget.',
	},

	/**
	 * Read-only Learnings card on SkillDetail (skill-learnings): renders
	 * files['learnings.md'] as sanitised markdown plus the levelEvidence.l6
	 * activity strip (candidate count, learnings count, last capture, last
	 * promotion). Deliberately NO editing surface — a manual editor would be
	 * a second write channel bypassing the capture pipeline's redaction.
	 */
	/**
	 * Origin + review-status card on SkillDetail (skill-install-idempotency):
	 * sourceUrl, sourceUpdatedAt, review state and the quarantine reason, plus a
	 * notice when local learnings postdate the last sync — the condition under
	 * which an update preserves them. All of this was previously reported ONLY in
	 * the install API response, i.e. nowhere a person would look.
	 */
	'skill-provenance': {
		// @custom-widget-ratchet exclude joins provenance (sourceUrl/sourceUpdatedAt), review state and a learnings-vs-sync time comparison into one advisory card; no built-in widget compares two timestamps on one object to decide whether to warn, and object-table would render the fields without the comparison that gives them meaning.
		kind: 'widget',
		component: SkillProvenance,
		defaultSize: { w: 12, h: 4 },
		minSize: { w: 6, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Read-only advisory card: reports state/origin and a derived learnings-ahead-of-source comparison (ADR-049 — a conditional warning derived from two timestamps, not a field listing).',
	},

	'skill-learnings': {
		// @custom-widget-ratchet exclude renders one files[] entry (learnings.md) as sanitised markdown joined with the levelEvidence.l6 activity strip, read-only by spec — no built-in widget renders a file-map entry as markdown, and adding an editor would open a second write channel bypassing the capture pipeline's redaction.
		kind: 'widget',
		component: SkillLearnings,
		defaultSize: { w: 12, h: 6 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Renders one files[] entry as markdown + the l6 activity stamp — file-content rendering with a joined evidence strip, not expressible as object-table/object-op (ADR-049); read-only by spec (no new write channel).',
	},

	/**
	 * L5 eval-evidence card on SkillDetail (skill-evals): the paired-run
	 * evidence (pass rate, mode-labelled baseline delta, trend, last
	 * validated), an honest empty state, and the owner-guarded Run paired
	 * eval action.
	 */
	'skill-eval-evidence': {
		// @custom-widget-ratchet exclude joins the skill's levelEvidence.l5 with the paired EvalRun trend of every dataset whose skillRefs references it, plus the owner-guarded Run-paired-eval trigger (EvalRunController) — a cross-schema join with a bespoke governed trigger that object-table/stats-block cannot express.
		kind: 'widget',
		component: SkillEvalEvidence,
		defaultSize: { w: 12, h: 5 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 10 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: "Joins the skill's levelEvidence.l5, the datasets whose skillRefs reference it, and the paired EvalRun history — a cross-schema read + a bespoke trigger action (EvalRunController), not expressible as object-table/object-op (ADR-049).",
	},

	/**
	 * Draft review surface on SkillDetail (skill-self-improvement): the
	 * awaiting-approval draft's side-by-side diff, driving learnings entries,
	 * scan verdict, eval delta / verbatim no-eval-evidence flag, and the three
	 * action-gated decisions (Accept / Edit-then-accept / Reject with
	 * bad-learnings marking) — plus the owner-guarded manual Propose trigger.
	 */
	'skill-draft-review': {
		// @custom-widget-ratchet exclude side-by-side diff of proposed vs active skill content with provenance, scan verdict, eval delta and three ADR-023 action-gated decisions routed through the Approval state machine (SkillDraftController) — a review/decision surface no built-in object-table/form-renderer provides.
		kind: 'widget',
		component: SkillDraftReview,
		defaultSize: { w: 12, h: 7 },
		minSize: { w: 6, h: 5 },
		maxSize: { w: 12, h: 14 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Joins the SkillDraft pipeline (bespoke SkillDraftController endpoints deciding via the Approval state machine, ADR-023 action-gated) with the active skill — a review/decision surface, not expressible as object-table/object-op (ADR-049).',
	},

	/**
	 * Version history + rollback + republish on SkillDetail
	 * (skill-self-improvement, mirroring agent-versioning): AuditTrail-backed
	 * history, content-plane diff, explicit rollback-as-new-version, the
	 * behind-badge and the never-automatic one-click Republish, plus the
	 * advisory post-acceptance rollback-suggestion banner.
	 */
	'skill-version-history': {
		// @custom-widget-ratchet exclude version list read from OpenRegister AuditTrail entries via bespoke SkillVersionController endpoints, with per-version diff, explicit rollback-as-new-version and the never-automatic Republish action — object-table binds OR object collections, not audit-entry versions with governed actions.
		kind: 'widget',
		component: SkillVersionHistory,
		defaultSize: { w: 12, h: 6 },
		minSize: { w: 6, h: 4 },
		maxSize: { w: 12, h: 12 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'Versions ARE AuditTrail entries read through bespoke SkillVersionController endpoints (history/diff/rollback/republish) — not expressible as object-table/object-op (ADR-049); rollback and republish are explicit human actions by spec.',
	},

	/**
	 * evalBaselineMode editor + info affordance on AgentDetail (skill-evals):
	 * the register property's consequence-explaining description is shown
	 * exactly where the value is changed.
	 */
	'agent-eval-baseline': {
		// @custom-widget-ratchet exclude inline enum editor with the register property's consequence-explaining description surfaced as an info affordance exactly where the value is changed (spec scenario) — the built-in type:data widget renders values read-only with no per-property info affordance or inline editor at HEAD.
		kind: 'widget',
		component: AgentEvalBaselineWidget,
		defaultSize: { w: 6, h: 3 },
		minSize: { w: 4, h: 2 },
		maxSize: { w: 12, h: 4 },
		allowedSlots: ['body'],
		propsSchema: { type: 'object', properties: {} },
		_note: 'The built-in type:data widget renders values only — no per-property info affordance or inline editor at HEAD — so the property gets a dedicated small widget (spec scenario: the description surfaces where the value is changed).',
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
