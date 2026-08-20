# Design: agent-template-gallery

## Architecture Overview
Hermiq is a thin OpenRegister-backed app: every capability so far is a schema + a service that
talks to OR's `ObjectService` + a controller + a Vue view. `agent-template-gallery` adds one
more schema (`agenttemplate`) and reuses two collaborators that already exist for exactly this
shape of problem:

- **`skills-marketplace`'s quarantine/scan/review-gate pattern** (`SkillMarketplaceService` →
  OR `ContentScanService`) — an `AgentTemplate`'s `systemPrompt` is scanned the same way a
  `Skill`'s `body` is.
- **`tenant-model-policy`'s enforcement chokepoint** (`TenantModelPolicyService`) — a
  template's suggested provider/model is coerced through `effectivePolicyFor()` at
  instantiate time, the same check `ProviderFactory` applies at run time.

No new external dependency, no new database, no new write path: everything flows through OR's
`ObjectService` exactly like `Agent`, `Skill`, and `ModelPolicy` already do.

```
AgentTemplateGallery.vue ──┬─> agentTemplates.js (axios) ──> AgentTemplateController
                            │                                       │
TemplateImportModal.vue ───┘                                       ▼
                                                          AgentTemplateService
                                                        ┌──────────┼───────────┐
                                                        ▼          ▼           ▼
                                              ObjectService  ContentScanService TenantModelPolicyService
                                              (hermiq reg.)   (OpenRegister)     (existing)
                                                        │
                                                        ▼
                                              SkillService::installOnAgent()
                                              (best-effort skill-ref resolution)
```

## Goals / Non-Goals

**Goals**
- Give a fresh Hermiq install something better to click than a blank `AgentFormModal`.
- Let an org export a working `Agent` as a reusable, secret-free template and re-import it
  (locally, or from another org/hub) with the same quarantine discipline `skills-marketplace`
  already established.
- Guarantee a template's suggested model never silently violates the caller's org policy.

**Non-Goals**
- Generating a template from natural language (Lindy-style) — explicitly deferred (proposal
  Out of Scope).
- Hosting a cross-instance public hub — `skills-marketplace` already owns that surface;
  `AgentTemplate` import/export is a package (string), not a hosted listing.
- Changing how `Agent`, `Skill`, or `Schedule` are created, run, or governed — instantiate
  calls the *same* creation paths those already use.

## Decisions

### Decision 1: A dedicated `AgentTemplate` schema, not a flag on `Agent`
**Choice:** New `agenttemplate` schema, structurally similar to `Agent` but a strict subset of
fields (no `views`, `invitedUsers`, `groups`, quotas, `actingUser`, or any field that could
carry tenant data or a secret-adjacent reference).
**Alternative considered:** Reuse the `Agent` schema with an `isTemplate` boolean. Rejected —
an `Agent` schema carries fields (quotas, RAG source toggles, invited users, `actingUser`) that
have no meaning before instantiation and would either need to be nulled out ad hoc per
template or risk leaking into an exported package. A dedicated schema makes "templates carry
no secrets and no tenant data" a structural guarantee, not a convention every export path must
remember to uphold.

### Decision 2: JSON package format, not agentskills.io frontmatter
**Choice:** `AgentTemplateSerializer::toPackage()`/`fromPackage()` (de)serialise to/from a
plain JSON string containing the template's declared fields.
**Alternative considered:** Reuse `SkillSerializer`'s `---`-fenced YAML-frontmatter + body
format. Rejected — that format exists to preserve a skill's markdown body byte-for-byte; an
`AgentTemplate` has no natural "body", only structured fields (provider, model, tools array,
skill refs, schedule hint). JSON is the honest fit and needs no bespoke parser.

### Decision 3: Skill refs are name-hints resolved best-effort at instantiate, not live installs
**Choice:** `AgentTemplate.skillRefs` stores `{skillId, name}` pairs. At `instantiate()`, each
`skillId` is looked up via `SkillService::getSkill()`; a hit that is `active` and visible to the
caller's org is installed via `SkillService::installOnAgent()`. A miss (skill doesn't exist in
this org, or isn't active) is silently skipped from the *install* but reported in the
`instantiate()` response's `unresolvedSkillRefs` list.
**Alternative considered:** Fail the whole instantiate when any skill ref doesn't resolve.
Rejected — an imported template (from another org/hub) legitimately references skills that
simply don't exist locally yet; failing the entire agent-creation over an optional
skill-install regresses the primary "click and get a working agent" value the gallery exists
to deliver. Mirrors the existing best-effort contract in
`SkillService::syncAgentSkillInstalls()`.

### Decision 4: Suggested schedule is a hint, never auto-materialised
**Choice:** `AgentTemplate.suggestedSchedule` is a plain object (`kind`, `cronExpr`/
`intervalMinutes`, `deliver`) with no `agentId` — it cannot be a live `Schedule` reference by
construction (the `Schedule` schema requires `agentId`, which doesn't exist until after
instantiate). `instantiate()`'s response includes the hint verbatim; the frontend prefills
`ScheduleFormModal` with it, but the user still explicitly confirms/creates the schedule
through the existing, already-governed schedule-creation path (reviewer, approval gate,
retry/circuit-breaker settings all still apply).
**Alternative considered:** Auto-create the `Schedule` at instantiate. Rejected — that would
silently start a recurring, governed dispatch surface (budget consumption, potential Talk
delivery, potential approval-gated runs) without the user ever seeing or confirming the
schedule's reviewer/delivery settings — a governance regression the wave-1 `human-approval-
gate`/`run-reliability` work explicitly hardened against.

### Decision 5: Reuse `ActionAuthService` with a template-scoped action pair, mirroring `skill.*`
**Choice:** Two new ADR-023 actions: `agenttemplate.approve-quarantined` (gates the review-gate
endpoint) and `agenttemplate.override-scan-verdict` (additionally required when the caller
passes `force=true` on a `dangerous` verdict) — both default `["admin"]` in
`lib/actions.seed.json`, exactly mirroring `skill.approve-quarantined` /
`skill.override-scan-verdict` in `SkillMarketplaceController::approve()`.
**Alternative considered:** A single `agenttemplate.manage` action covering both. Rejected —
`skills-marketplace` deliberately split these two so that a caller trusted to wave through a
clean scan is not automatically trusted to override a *dangerous* one; reusing the same split
keeps the two systems' RBAC posture consistent and lets an admin broaden one without the
other.

### Decision 6: Model coercion happens once, at instantiate — never persisted back onto the template
**Choice:** `instantiate()` calls `TenantModelPolicyService::effectivePolicyFor($organisation)`
and, if the template's `suggestedProvider`/`suggestedModel` pair fails
`matchesAllowed()`, substitutes the policy's `defaultModel` (or the first allowed
provider with an empty model, forcing an explicit user choice) on the **created Agent only**.
The template object itself is never mutated by an instantiate call — it stays a reusable,
org-agnostic suggestion for the next org that instantiates it.
**Alternative considered:** Reject instantiate outright when the suggestion is out-of-policy.
Rejected — the template's suggestion is explicitly a *suggestion* (proposal: "subject to the
org's tenant-model-policy at instantiation"); rejecting the whole action for a policy mismatch
that has an obvious, safe resolution (the org's own default) would make templates from a
partner org effectively useless whenever policies differ, which is the common case.

## Risks / Trade-offs
- [Risk] A template exported from an org with a permissive policy, then imported and
  instantiated by a locked-down org, still needs the user to notice the model was swapped →
  [Mitigation] `instantiate()`'s response always includes both `requestedModel` and
  `resolvedModel` (with a `modelCoerced: true/false` flag); the gallery surfaces this as a
  note-card, not a silent field change.
- [Risk] `AgentTemplateSerializer`'s JSON format has no versioning of its own → [Mitigation]
  the template's `version` field (a free-text string, e.g. semver) travels inside the package;
  parsing is tolerant of missing optional fields (same posture as `SkillSerializer::
  fromPackage()`'s empty-string defaults).
- [Risk] Seeded starter templates could drift out of sync with the actual tool/skill catalogue
  over time → [Mitigation] seeded templates reference tools by name string (the same
  `{appId}.{toolName}` shape `Agent.tools` already uses) and skill refs are optional/best-
  effort by Decision 3, so a missing tool/skill degrades gracefully rather than breaking the
  seed.

## Migration Plan
No database migration (OpenRegister schemas are not Nextcloud DB migrations). Deploy steps:
1. Add the `agenttemplate` schema to `lib/Settings/hermiq_register.json`; bump
   `appinfo/info.xml`'s `<version>` by one patch so the version-gated register re-import picks
   it up.
2. Add the two `agenttemplate.*` entries to `lib/actions.seed.json`.
3. Register `SeedAgentTemplates` as an `IRepairStep` (same registration point as
   `SeedModelPolicies`/`SeedBudgets` in `lib/AppInfo/Application.php` or
   `lib/Migration/`'s repair registration, whichever the existing seeds use).
4. On `occ upgrade`/reinstall, the repair step idempotently seeds 4-6 starter templates
   (never overwrites an existing template with the same seeded name).

**Rollback:** Revert the code changes; existing `agenttemplate` OR objects are orphaned (never
auto-deleted, consistent with `skills-marketplace`'s never-hard-delete posture) and simply
stop being reachable through the (now removed) UI/API.

## Open Questions
None — see proposal.md.
