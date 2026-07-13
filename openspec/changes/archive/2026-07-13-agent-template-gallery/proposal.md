# Proposal: agent-template-gallery

## Summary
Every rival agent platform ships a gallery to escape the blank-form cold start: AutoGPT's
marketplace of agents, Salesforce Agentforce's prebuilt service/sales/SDR library, Google
Gemini Enterprise's prebuilt expert agents (Deep Research, Idea Generation) plus user-created
Gems, Khoj's role-based custom agents, and LibreChat's prompt library and conversation
presets. Hermiq's `AgentCatalog.vue` today opens straight into `AgentFormModal` with every
field empty. This change adds an **AgentTemplate** OpenRegister object — a portable,
versionable agent definition with no secrets and no tenant data — plus "Use this template"
instantiation (model coerced into the caller's org model policy), export of an existing Agent
to a template package, import of a package into quarantine (content-scanned exactly like
`skills-marketplace`), and 4-6 seeded starter templates so a fresh install already has
something useful to click.

## Motivation
Hermiq already ships the skills-level analogue of this problem (`skills-catalog` +
`skills-marketplace`, agentskills.io import/export, quarantine + content-scan + Curator). The
same cold-start gap exists one level up, at the agent-definition level, and the evidence
cluster below (Spectr DB, `competitor_features` WHERE `app_slug='hermiq'` AND `provided_by='gap'`
AND `resolved_by LIKE '%agent-template-gallery%'`) shows six competitors closing it:

| Feature | Competitor | Description |
|---|---|---|
| Marketplace of agents | AutoGPT | Share and reuse prebuilt agents in the marketplace |
| Prebuilt agent library | Salesforce Agentforce 360 | Ready-made service/sales/SDR agents |
| Prebuilt expert agents | Google Gemini Enterprise | Deep Research, Idea Generation, NotebookLM-style agents |
| Gems (custom agents) | Google Gemini Enterprise | User-created custom Gemini agents with instructions + knowledge |
| Custom personal AI agents | Khoj | Role-based agents (teacher, copywriter) with custom instructions |
| Natural-language agent creation | Lindy | Describe an agent in plain English and it builds the workflow |
| Prompt library & sharing | LibreChat | Shared prompt templates across users |
| Conversation branching & presets | LibreChat | Fork conversations and save reusable presets |

Without a gallery, every new Hermiq org starts from zero, re-typing system prompts other
tenants (or Conduction) have already refined — a genuine adoption friction point that a thin,
governed gallery removes without Hermiq growing into a marketplace SaaS or a low-code agent
builder.

## Affected Projects
- [ ] Project: `hermiq` — new `AgentTemplate` schema, `AgentTemplateService`/`AgentTemplateSerializer`,
  `AgentTemplateController` + routes, `SeedAgentTemplates` repair step, new
  `AgentTemplateGallery.vue` view + `TemplateImportModal.vue`, ADR-023 action-matrix entries,
  l10n strings.

No other `apps-extra` project is touched: the change is entirely internal to Hermiq's own
OpenRegister register and Vue SPA.

## Scope

### In Scope
- An `AgentTemplate` OR object (hermiq register): name, description, category, system prompt,
  suggested provider/model, tool allowlist, skill refs (name-only hints, not live installs),
  suggested schedule hint (kind/cron/interval/deliver — never a live `Schedule` object), a
  lifecycle state (`active`/`quarantined`/`archived` — mirrors `Skill.state`), source
  (`local`/`org`/`hub` — mirrors `Skill.source`), and a version string. Templates carry
  **no secrets and no tenant data** by construction (no fields for API keys, RAG source ids,
  invited users, or quotas).
- `AgentTemplateSerializer`: template ⇄ portable JSON package string (analogous to
  `SkillSerializer`, but JSON rather than YAML-frontmatter since a template has no natural
  markdown body).
- `AgentTemplateService`:
  - `exportFromAgent(agentId)` — an existing Agent → a template package (secrets/tenant
    fields never copied — only the fields the AgentTemplate schema declares).
  - `importPackage(package, source, createdBy)` — a package → a new template. A package from
    an external source (`source='org'|'hub'`) lands **quarantined** and is content-scanned via
    OpenRegister's `ContentScanService` (reusing `SkillMarketplaceService`'s exact pattern) —
    the system prompt is as much a prompt-injection vector as a skill body.
  - `approveQuarantined(templateId, force)` — the review gate, blocking one-click approval on
    a `dangerous` verdict unless explicitly overridden (mirrors
    `SkillMarketplaceService::approveQuarantined()`).
  - `instantiate(templateId, overrides)` — "Use this template" → creates a real `Agent` in the
    caller's org. The suggested provider/model is checked against
    `TenantModelPolicyService::effectivePolicyFor()`; an out-of-policy suggestion is replaced
    by the org's policy default (or first allowed provider) and the substitution is returned
    to the caller — **never silently violated, never silently ignored**. Skill refs that
    resolve to a real, visible `Skill` in the caller's org are installed via
    `SkillService::installOnAgent()`; unresolved refs are dropped with a reported list
    (best-effort, mirrors `SkillService::syncAgentSkillInstalls()`). The suggested schedule
    hint is passed back to the frontend to prefill `ScheduleFormModal` — instantiate never
    silently creates a live, running `Schedule`.
- `AgentTemplateController` + routes: list/get/create/update/delete, `export`, `import`
  (quarantined), `approve` (action-gated), `instantiate`.
- ADR-023 action-matrix seed entries: `agenttemplate.approve-quarantined` and
  `agenttemplate.override-scan-verdict` (mirrors `skill.approve-quarantined` /
  `skill.override-scan-verdict` exactly — admin-only by default).
- `SeedAgentTemplates` idempotent repair step (mirrors `SeedBudgets`/`SeedModelPolicies`):
  4-6 starter templates grounded in the research journeys — Morning briefing, Inbox triage,
  Website/monitor watcher, Weekly report, Meeting-notes summariser.
- Frontend: a new "Agent templates" nav page (`AgentTemplateGallery.vue`) listing templates
  with "Use this template" / "Export" / "Approve" (quarantined) actions through the shared
  `CnDataTable`; a `TemplateImportModal.vue` for pasting an import package; `AgentCatalog.vue`
  gains a "Browse templates" entry point next to "Create agent" so the blank-form path stays
  available.
- l10n: new user-facing strings in `l10n/en.json` + `l10n/nl.json` (English keys).

### Out of Scope
- **Natural-language agent creation** ("describe your agent in plain English" → generated
  config, the Lindy capability from the evidence cluster). This sits naturally on top of the
  `AgentTemplate` schema this change introduces (an NL-generated draft would simply be an
  unsaved `AgentTemplate`), but generating a template from a free-text prompt is a distinct,
  LLM-in-the-loop capability with its own prompt-injection and cost surface — deferred to a
  follow-up change once this schema exists to build on.
- **A cross-instance public hub** for browsing/publishing templates beyond the org. That hub
  concept is already owned end-to-end by `skills-marketplace` (`publishToHub()` via
  OpenConnector's `CallService`); this change's import/export is a local package (paste/upload
  JSON) plus the existing org-to-org quarantine path, not a second hosted hub.
- Any change to the `Agent`, `Skill`, or `ModelPolicy` schemas themselves — this change only
  references them (skill refs by uuid, provider/model coercion via the existing policy
  service).
- A visual template diff/versioning UI — the `version` field is stored but not rendered as a
  diff in this change.
- Hermiq growing a node-canvas / visual workflow builder (n8n-nextcloud's territory) or its
  own connector catalogue (openconnector's territory) — templates configure an existing
  Hermiq `Agent`, nothing more.

## Approach
Mirror the `skills-catalog` + `skills-marketplace` shape one level up, at agent-definition
granularity, reusing the same collaborators rather than rebuilding them: OpenRegister's
`ObjectService` (single write-path), `ContentScanService` (heuristic scan on untrusted
import), and Hermiq's own `TenantModelPolicyService` (model coercion at instantiate) and
`SkillService` (best-effort skill-ref resolution). No new external dependency, no new write
path, no new RBAC primitive — `ActionAuthService`/ADR-023 gates the two privileged mutations
exactly as it already gates the skill marketplace.

## New Dependencies
None.

## Impact
- New OpenRegister schema `agenttemplate` added to `lib/Settings/hermiq_register.json`
  (`appinfo/info.xml` version bumped by one patch — register re-import is version-gated).
- New PHP: `lib/Service/AgentTemplateService.php`, `lib/Service/AgentTemplateSerializer.php`,
  `lib/Controller/AgentTemplateController.php`, `lib/Repair/SeedAgentTemplates.php`.
- New routes in `appinfo/routes.php` under `/api/agent-templates`.
- `lib/actions.seed.json` gains two entries.
- New Vue: `src/views/AgentTemplateGallery.vue`, `src/modals/TemplateImportModal.vue`,
  `src/api/agentTemplates.js`; `src/views/AgentCatalog.vue` gains a nav button; nav
  registration in `appinfo/info.xml` (or wherever the nav entries are declared) gains one
  entry.
- `l10n/en.json` + `l10n/nl.json` gain new keys (English keys).

## Cross-Project Dependencies
None — self-contained within Hermiq. It calls no OpenConnector/n8n/other-app service; it only
consumes OpenRegister's existing `ObjectService`/`ContentScanService` the way
`skills-marketplace` already does.

## Risks

### Risk 1: An imported template's system prompt is a prompt-injection vector
**Severity:** High — **Mitigation:** Every externally-sourced template (`source='org'|'hub'`)
lands `quarantined` and is scanned by OR's `ContentScanService` before it can be approved,
exactly like `skills-marketplace`; a `dangerous` verdict blocks one-click approval and
requires the stricter `agenttemplate.override-scan-verdict` action to force through.

### Risk 2: Instantiate silently violates the org's model policy
**Severity:** Medium — **Mitigation:** `instantiate()` always resolves the suggested
provider/model against `TenantModelPolicyService::effectivePolicyFor()`; an out-of-policy
suggestion is replaced with the policy's default/first-allowed combination and the
substitution is returned in the response for the UI to surface — never silently swapped
without telling the caller, never silently left out-of-policy.

### Risk 3: Instantiate silently creates a live, unreviewed schedule
**Severity:** Low — **Mitigation:** `instantiate()` never creates a `Schedule` object itself;
the suggested-schedule hint is returned to the frontend to prefill `ScheduleFormModal`, so a
schedule (with its own reviewer/approval-gate settings) is only ever created through the
existing, already-governed schedule-creation path.

## Rollback Strategy
The `agenttemplate` schema and its controller/service/repair step are additive — disabling or
reverting this change removes the nav entry and endpoints; no existing `Agent`, `Skill`, or
`ModelPolicy` data is touched, and OpenRegister objects of the removed schema are simply
orphaned (never auto-deleted), consistent with how `skills-marketplace` was rolled out.

## Open Questions
None at time of writing — the seams (`ContentScanService`, `TenantModelPolicyService`,
`SkillService::installOnAgent()`, `ActionAuthService`) all exist at HEAD and are reused
as-is.
