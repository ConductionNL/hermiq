---
kind: code
---

# Proposal: beta-surface-alignment

# Why

Hermiq (v0.1.44) had two beta-release blockers found during a fleet-wide
cross-surface alignment pass (code metadata ↔ code features ↔ product page ↔ docs):

1. `appinfo/info.xml` `<summary lang="nl">` was a byte-for-byte English copy
   ("Schedule autonomous AI agents in Nextcloud") of the English summary, despite
   `<description lang="nl">` already carrying a real Dutch translation. Per hydra
   ADR-007 (English primary, Dutch required), a Dutch-locale App Store listing showed
   an untranslated summary line.
2. `conduction-website/src/pages/apps/hermiq.mdx` did not exist. Hermiq had no product
   page at conduction.nl/apps/hermiq at all — no Dutch i18n counterpart either. Every
   other beta/GA Conduction app (shillinq, openbuild, doriath, ...) has one.

A third, lower-severity finding: `hermiq/docs/intro.md` (the hermiq.conduction.nl
Docusaurus landing) still described the generic app-template scaffold — an
`ExampleWidget` Dashboard widget, an `ExampleToolProvider` MCP provider, a generic
"admin settings panel" — none of which reflect Hermiq's actual shipped feature set
(agents, scheduling+Talk delivery, chat/sessions/memory, skills marketplace, MCP
tools, human approval gate, run analytics, AI-feature governance, multi-tenant ops).

# What Changes

- `appinfo/info.xml`: translate `<summary lang="nl">` to
  "Plan autonome AI-agents in Nextcloud" (real Dutch, matching the register/register
  verb tense already used in the Dutch description).
- Author `conduction-website/src/pages/apps/hermiq.mdx` (EN) and
  `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/hermiq.mdx` (NL),
  following the `shillinq.mdx` / `openbuild.mdx` structure (DetailHero + FeatureList +
  PairRow + CtaBanner), using the canonical feature list below — derived from
  `src/manifest.json` `menu[]`/`pages[]` and the corresponding `lib/Controller/`,
  `lib/Service/` classes, not from marketing copy.
- Rewrite `hermiq/docs/intro.md` "What is this?" bullet list to describe the real
  shipped features instead of the generic app-template scaffold list.

## Canonical feature list (source of truth: `src/manifest.json`, verified against `lib/`)

| Feature | Manifest page/menu | Backing code |
|---|---|---|
| Agents (catalog + detail, prompt/model/tools/skills) | `AgentCatalog`, `AgentDetail` | `AgentsController` |
| Scheduling, delivered to Nextcloud Talk (or notification fallback) | schedule attached from `AgentDetail`; `Cron/ScheduleTask` | `ScheduleService`, `DeliveryService` (`OCP\Talk\IBroker` probe, graceful no-Talk fallback) |
| Chat (live streaming thread) | `Chat` | `ChatController`, `ChatStreamController`, `ChatHealthController`, `Service/Llm/ChatDriver` |
| Sessions (conversation history) | `AgentSessions` | `ConversationController` |
| Memory (consolidating, char-budget) | `AgentMemory` | `MemoryController` |
| Skills marketplace (agentskills.io import/export, install-onto-agent) | `SkillsCatalog` | `SkillController`, `SkillMarketplaceController`, `SkillMarketplaceService`, `SkillSerializer`, `Cron/SkillCuratorTask` |
| MCP tools catalogue | `McpTools` | facade-backed `/api/agents/tools` |
| Human approval gate (reviewer inbox, org kill switch) | `ApprovalInbox` | `ApprovalController`, `Service/ApprovalService` |
| Run analytics (KPIs + per-agent breakdown) | `RunAnalytics` | `AnalyticsController`, `RunHistoryController`, `RunNowController` |
| AI feature governance (DPO-gated enable/disable, EU AI Act) | `AiFeatureRegister` | `AiFeatureController` |
| Multi-tenant ops (per-org quota, audit export) | `TenantOps` | `TenantOpsController`, `TenantControlController` |
| OpenRegister-backed governance (every agent/run/memory/skill = OR object) | n/a (cross-cutting) | `manifest.dependencies: [openregister]`, repair-step `CheckOpenRegisterCompatibility` |

Explicitly **excluded** from the canonical list: the `Examples`/`ExampleDetail`
pages and `example` schema in `src/manifest.json`/`lib/Settings/hermiq_register.json`.
These are confirmed scaffold leftovers already tracked for removal in
`openspec/changes/remove-scaffold-leftovers` (not started as of this proposal) — they
are not a real Hermiq feature and were not used in the product page or docs rewrite.

## Claims verified vs removed

- "Autonomous AI agents ... run it on a schedule" (info.xml, README, project.md) —
  VERIFIED: `ScheduleService` + `Cron/ScheduleTask` background job.
- "Delivered to Nextcloud Talk" — VERIFIED: `DeliveryService` calls into
  `OCP\Talk\IBroker`/`spreed` classes behind a `class_exists()`/`hasBackend()` guard,
  with a notification fallback when Talk is absent. Product-page copy states Talk as
  the delivery surface with the fallback noted, not an unconditional dependency.
  Talk is deliberately **not** added as an info.xml `<dependency>` app entry — it is
  an optional runtime integration (guarded, degrades gracefully), unlike OpenRegister
  which is a hard boot-time dependency already declared.
- "Governed, auditable and multi-tenant" (agents/memory/skills as OpenRegister
  objects) — VERIFIED: `manifest.dependencies: [openregister]`,
  `CheckOpenRegisterCompatibility` repair step, `TenantOpsController`/
  `TenantControlController` for per-org scoping.
- "Human approval gate" — VERIFIED: `ApprovalController` + `ApprovalService`,
  `ApprovalInbox` page.
- "Skills marketplace / agentskills.io" — VERIFIED: `SkillSerializer` (round-trip
  serialization), `SkillMarketplaceService`/`SkillMarketplaceController`.
- No compliance/standard claims (Peppol, SEPA, BBV, DigiD, etc.) were present or
  added — Hermiq is an AI-agent scheduler, not a compliance-scoped app; none were
  fabricated for the product page.
- No dependency-app claim beyond OpenRegister was added; Talk was intentionally left
  undeclared as a hard `<dependency>` (see above).

## Icon status

`img/app.svg` is a white-fill, 24×24, single-path glyph (a generic "document/list"
icon) per the app-icon convention — consistent across `app.svg`/`app-dark.svg`/
`app-store.svg`. The new product-page hero uses a distinct inline SVG (a
person/agent glyph) rather than re-embedding `app.svg`, matching the existing
per-app-page convention (each product page draws its own hero icon rather than
importing the NC app icon file) — see `shillinq.mdx`/`openbuild.mdx` precedent. No
mismatch to reconcile; noting for the record that `img/app.svg`'s glyph does not
depict "agent" iconography, but this is a pre-existing, non-blocking design
choice out of scope for this change (raising it is not required by the app-icon
ADR, which only mandates white-fill/24×24/512-on-brand-blue for the store asset).

## Still misaligned (needs a decision, not fixed in this change)

- Hermiq is **not** listed in `conduction-website/src/data/apps-catalog.js`
  `PRESENTATION` or in `@conduction/docusaurus-preset`'s `apps-registry` (an external
  published package, not editable from this repo). Precedent (`openbuild`, `doriath`)
  shows a product page still renders and functions correctly without a registry
  entry (`AppCrossLinks`/`PartnersForApp` degrade gracefully; `DetailHero`'s
  JSON-LD/SoftwareApplication schema simply doesn't emit). Decision needed: either
  (a) add `hermiq` to `apps-catalog.js` now (it will still be filtered out of
  `/apps` and `AppsPreview` per that file's own "not in store + no downloads" rule
  until Hermiq has an app-store listing with download counts), or (b) wait until
  Hermiq is published to the App Store and let the next `app-downloads.yml` refresh
  drive the addition.
- `hermiq/docs/tutorials/user/01-first-launch.md` and
  `hermiq/docs/tutorials/admin/01-admin-settings.md` are still all-TODO journeydoc
  scaffolds (no real step content, no captured screenshots). Out of scope here per
  this change's brief ("don't scaffold a whole site unless trivial") — flagged as a
  follow-up; use `/journeydoc-add-story` + `/journeydoc-instrument` to fill them in
  against a live instance.
- `hermiq/README.md` and `hermiq/project.md` still describe the generic
  app-template scaffold (ExampleWidget, generic "Dashboard"/"Admin Settings"
  feature bullets) exactly like `docs/intro.md` did before this change. They are
  not one of the 4 surfaces in scope (info.xml, manifest, product page, docs site)
  but carry the same drift and should be reconciled in a follow-up.
