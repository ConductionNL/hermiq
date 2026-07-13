# Proposal: agent-versioning

## Summary

Every change to an Agent's configuration (prompt, model, provider, tools, skills,
capability profile) already lands in OpenRegister's hash-chained AuditTrail —
`SaveObject::createAuditTrail()` diffs and persists the full old/new state on
every save, automatically, for every OR object including `Agent`. This change
does not add a version table: it builds a **read + rollback surface** on top of
that existing mechanism. Concretely: (1) a Hermiq-owned, owner-scoped read
service/endpoint that lists an Agent's audit-trail entries as a human-readable
version timeline and computes a field-level diff between any two of them, (2)
one-click rollback that reconstructs a prior version's field values and saves
them through the normal `ObjectService::saveObject()` path — which itself
creates a fresh audit-trail entry, so history is never mutated — and (3)
threading the executing Agent's current version identifier onto the run-audit
entry (`ScheduleService::writeRunAudit()` and the three sibling per-run audit
writers) so every trace can be tied back to the exact prompt/model/tools that
produced it.

## Motivation

The Spectr competitive sweep classified "workflow/prompt versioning" as a
genuine gap for Hermiq: 12 rivals ship it — n8n (git-backed, Enterprise-gated),
Windmill (git sync + history), Temporal (versioning/patching), Zapier/Make
(version history), Prefect/Airflow/Kestra/Trigger.dev (versioned deployments),
ProcessMaker (process versions), Langfuse ("Prompt management & versioning" —
OSS tier), Node-RED (git projects). Tender-demand scoring for this cluster
(~t179) ranks it above several features Hermiq has already shipped. Today, an
Agent's `prompt`/`model`/`tools`/`skillInstalls` can be edited in place with no
way to see what changed, compare two points in time, or undo an edit — and a
run's audit entry records `attempt`/`steps`/`usage` but not which agent
configuration actually executed. For a governed, auditable, EU AI Act–facing
product this is a real gap: Art. 12 traceability is weaker without it, and
"who changed the prompt and can I get it back" is a support request waiting
to happen.

## Affected Projects

- [x] Project: `hermiq` — new read/rollback service + controller over
  OpenRegister's existing AuditTrail for the `Agent` object; run-audit context
  on four existing per-run audit writers; new AgentDetail.vue version-history
  and diff UI.
- [ ] Project: `openregister` — NOT modified. Its `AuditTrailMapper`
  (`createAuditTrail`, `findAll`, `revertObject`) already provides everything
  this change needs; verified at HEAD (`lib/Db/AuditTrailMapper.php`,
  `lib/Service/Object/SaveObject.php`, `lib/Service/Object/RevertHandler.php`).

## Scope

### In Scope

- Listing an Agent's version history: every `create`/`update` AuditTrail entry
  OpenRegister already wrote for that Agent object, newest-first, each exposed
  as a version (id = audit-trail entry UUID, timestamp, actor, and a short
  changed-fields summary).
- Diffing any two versions across a fixed allowlist of "agent config" fields:
  `prompt`, `model`, `provider`, `temperature`, `maxTokens`, `configuration`,
  `tools`, `skillInstalls`, `contextRefs`, `enableRag`, `ragSearchMode`,
  `ragNumSources`, `ragIncludeFiles`, `ragIncludeObjects`, `views`,
  `searchFiles`, `searchObjects` (the "capability profile" fields already on
  the `Agent` schema — see `lib/Settings/hermiq_register.json` lines 468-509).
  Computed by walking the audit-trail `changed` diffs backward from the live
  object, the same technique `AuditTrailMapper::revertObject()` already uses
  internally — never persisted, read-only.
- One-click rollback to a previous version: reconstructs the allowlisted
  field values as of the target version and saves them via the existing
  `ObjectService::saveObject()` write-path (same call `AgentsController::update()`
  already makes), which itself creates a brand-new `update` AuditTrail entry —
  history before it is never touched, and the rollback itself becomes a new,
  diffable, revertable version.
- Pinning the executed Agent version on a run's audit entry: the version
  identifier of the Agent config that actually ran, recorded next to the
  existing `attempt`/`steps`/`usage` context in all four places Hermiq writes
  a per-run/per-interaction AuditTrail entry (`ScheduleService::writeRunAudit()`
  — covers both scheduled and flow-triggered runs since `FlowAgentRunService`
  calls `ScheduleService::runAgentAsOwner()` directly; `FlowAgentRunService`'s
  own `writeRunAudit()`; `WebhookAgentRunService`'s audit write; and
  `ContextAgentInteractionService::audit()`).
- Surfacing the pinned version on the existing run-history/run-trace UI
  (`RunHistoryService::toRunRecord()`, AgentDetail.vue's run rows).
- Owner-scoped RBAC on the new read/rollback endpoints, mirroring
  `AgentsController`'s existing `canUserAccessAgent()` (read) /
  `canUserModifyAgent()` (write) guards.

### Out of Scope

- **Git sync / export of agent definitions.** Version history here lives
  entirely inside OpenRegister's AuditTrail — there is no git repo, no
  export/import format, and no external sync target. That is
  `agent-template-gallery`'s concern (import/export), not this change's.
- **Prompt A/B testing / evaluation.** Comparing how two versions *perform*
  (accuracy, cost, latency across a test set) is `agent-evals`'s concern.
  This change only compares what two versions *contain*.
- Versioning fields outside the fixed allowlist above (`name`, `description`,
  `type`, `active`, `isPrivate`, `invitedUsers`, `groups`, `requestQuota`,
  `tokenQuota`, `actingUser`, `user`, and the derived governance fields
  `reassignmentFlag`/`reviewedAt`/`reviewedBy`) — these are identity,
  visibility, quota, and lifecycle-governance concerns already owned by other
  shipped changes (`agent-lifecycle-governance`, `agent-capability-profile`);
  rolling them back would fight those changes' own derived-state invariants.
  A future change can widen the allowlist if a real need surfaces.
- Any change to OpenRegister itself. `AuditTrailMapper`/`SaveObject`/
  `RevertHandler` are used exactly as they exist today.

## Approach

Add one Hermiq-owned service (`AgentVersionService`) that reads the Agent's
AuditTrail entries via the same directly-injected `AuditTrailMapper` pattern
`RunHistoryService`/`BudgetService`/`TenantOpsService` already use (no HTTP
call to OpenRegister — OR's own `AuditTrailController::objects()` endpoint is
hard admin-gated, which would lock out the non-admin agent owners who are this
feature's actual audience), plus a thin owner-scoped controller
(`AgentVersionController`, mirroring `RunHistoryController`). Rollback reuses
`ObjectService::saveObject()` — no new persistence primitive. The four
run-audit writers each gain one additional context key. The frontend gets a
new isolated dialog (`src/dialogs/agents/`, ADR-004) for the version timeline
and diff, and a rollback confirm action, wired from `AgentDetail.vue`.

## New Dependencies

None.

## Impact

- New: `lib/Service/AgentVersionService.php`, `lib/Controller/AgentVersionController.php`,
  new routes in `appinfo/routes.php`, `src/api/agents.js` additions,
  `src/dialogs/agents/AgentVersionHistoryDialog.vue`,
  `src/dialogs/agents/AgentVersionDiffDialog.vue`, l10n additions.
- Modified: `lib/Service/ScheduleService.php` (`writeRunAudit()` context),
  `lib/Service/FlowAgentRunService.php` (`writeRunAudit()` context),
  `lib/Service/WebhookAgentRunService.php` (audit context),
  `lib/Service/ContextAgentInteractionService.php` (`audit()` context),
  `lib/Service/RunHistoryService.php` (`toRunRecord()` surfaces the pinned
  version), `src/views/AgentDetail.vue` (version-history entry point + rollback
  action).
- No changes to `lib/Settings/hermiq_register.json` and no `appinfo/info.xml`
  version bump — no schema fields are added or changed.

## Cross-Project Dependencies

None. OpenRegister's `AuditTrailMapper` is consumed exactly as it already
exists at HEAD; no OR change is requested or required.

## Risks

### Risk 1: OpenRegister's own audit-trail HTTP endpoint is admin-gated
**Severity:** Medium — **Mitigation:** Confirmed at HEAD
(`openregister/lib/Controller/AuditTrailController.php::requireAdmin()`) that
`GET .../audit-trails` 403s any non-NC-admin caller. Hermiq's non-admin agent
owners are exactly this feature's audience, so the design deliberately does
NOT call that HTTP endpoint or the nc-vue `auditTrailsPlugin` (which targets
the same admin-gated route) from the frontend. Instead `AgentVersionService`
injects `AuditTrailMapper` directly, in-process — the same pattern
`RunHistoryService` already uses for the identical reason — and Hermiq's own
controller applies Hermiq's own (non-admin-gated) owner/access RBAC.

### Risk 2: Reconstructing a historical field value replays diffs rather than reading a stored snapshot
**Severity:** Low — **Mitigation:** This is the same technique
`AuditTrailMapper::revertObject()`/`revertChanges()` already uses in
production (walk `changed['old']` backward from the live object). Scoping the
replay to the fixed field allowlist keeps it cheap (a handful of fields, not
the whole object) and read-only diffs never persist anything, so a bug here
cannot corrupt the live Agent — only rollback's final `saveObject()` call
writes, and that goes through the exact same validated path as a normal edit.

### Risk 3: Rollback and a concurrent edit race
**Severity:** Low — **Mitigation:** Rollback applies the allowlisted fields
as a partial update through the existing `saveObject()` path — the same
optimistic, last-write-wins semantics every other Agent edit already has.
No new concurrency primitive is introduced; a rollback immediately followed
by another edit simply produces one more diffable version, consistent with
"never mutates history."

## Rollback Strategy

Feature is additive: two new files, four small context-array additions to
existing per-run audit writers (each wrapped in the same non-fatal try/catch
those writers already use), and one new frontend dialog wired from an
existing view. Reverting is deleting the new files/routes/dialog and the
four added context keys; no data migration exists to undo since no schema or
table was introduced.

## Open Questions

- Should skill names (not just `skillInstalls` UUIDs) be resolved for the
  diff display, or is showing raw UUIDs acceptable for v1? Leaning toward
  resolving names client-side via the already-loaded skills catalogue rather
  than adding a backend join — deferred to design.md.
