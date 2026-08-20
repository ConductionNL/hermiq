# Design: agent-versioning

## Architecture Overview

Hermiq owns no database tables (ADR-004/ADR-022): every Agent is an
OpenRegister `ObjectEntity` in the `hermiq`/`agent` register+schema. OR's
`SaveObject` service already calls `AuditTrailMapper::createAuditTrail(old,
new, action)` on **every** save of **every** OR object — verified at HEAD in
`openregister/lib/Service/Object/SaveObject.php` (lines 3333, 5149, 5525),
which diffs `$old->jsonSerialize()` against `$new->jsonSerialize()` field by
field and persists the result as a hash-chained `AuditTrail` row
(`openregister/lib/Db/AuditTrailMapper.php::createAuditTrail()`, line 378).
This is already, silently, a complete version history of every Agent. This
change adds nothing to that write path — it adds a **read shape** and a
**rollback action** on top of it, entirely inside Hermiq.

```
Agent edit (existing)            Version read/rollback (new, this change)
─────────────────────            ──────────────────────────────────────────
AgentsController::update()        AgentVersionController
  └─ ObjectService::saveObject()    ├─ GET  /api/agents/{id}/versions
       └─ SaveObject               │    └─ AgentVersionService::listVersions()
            └─ AuditTrailMapper    │         └─ AuditTrailMapper::findAll(
                 ::createAuditTrail│              object_uuid=…, action=create,update)
                 (writes a row)    ├─ GET  /api/agents/{id}/versions/diff
                                   │    └─ AgentVersionService::diff(a, b)
                                   │         └─ replay changed['old'] backward
                                   │            from the live object (read-only)
                                   └─ POST /api/agents/{id}/versions/{id}/rollback
                                        └─ AgentVersionService::rollback(id)
                                             └─ ObjectService::saveObject()
                                                  (writes a NEW row — see above)
```

The run-audit half is a smaller, orthogonal addition: the four existing
per-run/per-interaction AuditTrail writers (`ScheduleService::writeRunAudit()`,
`FlowAgentRunService::writeRunAudit()`, `WebhookAgentRunService`'s audit call,
`ContextAgentInteractionService::audit()`) each already build a `context`
array passed to `AuditTrailMapper::createAuditTrailEntry()`. Each gains one
more key: the executing Agent's current version identifier, captured at the
same point each writer already has the Agent `ObjectEntity` in hand.

## Goals / Non-Goals

**Goals**
- Read an Agent's full version timeline without adding any new storage.
- Diff two versions across a well-defined "agent config" field set.
- One-click, non-destructive rollback (new version, old content).
- Every run traceable to the exact Agent version that produced it.

**Non-Goals**
- No git export/sync (agent-template-gallery).
- No A/B evaluation of version behavior (agent-evals).
- No versioning of identity/visibility/quota/governance-derived fields (see
  proposal.md Out of Scope) — only the fixed content allowlist below.
- No change to OpenRegister.

## Decisions

### Decision 1: Reuse OR's AuditTrail as the version store; do not add a Hermiq table or a new OR schema
**Rationale:** Hermiq is a thin app (ADR-004/ADR-022) and OR already performs
a full-object diff-and-persist on every save, hash-chained and immutable. A
parallel `AgentVersion` schema/table would duplicate storage, duplicate the
hash-chain integrity guarantee, and desynchronize from the audit trail the
moment anyone edits an Agent through any other path (API, repair job, a
future admin tool) that isn't wired to the new table.
**Alternatives considered:** (a) A new `AgentVersion` OR schema, one object
per version — rejected: exactly the "parallel version table" the brief warns
against, and it would need its own write-hook wired into every save path. (b)
Storing a full JSON snapshot per version inside a new field on `Agent`
itself — rejected: mutates the very object being versioned, and loses
immutability (an "undo" would edit the snapshot list in place).

### Decision 2: A version identifier is the AuditTrail entry UUID, not the object's own `version` semver field
**Rationale:** Verified at HEAD: `ObjectEntity.version` (the generic semver
field, e.g. `"1.0.0"`) is set at creation but is **never bumped** by
`SaveObject` on subsequent updates — no `setVersion()` call exists anywhere
in `openregister/lib/Service/Object/SaveObject.php`. Only type-specific
mappers (`RegisterMapper`, `SchemaMapper`, `ConfigurationMapper`) bump their
own `version`; `Agent` (a plain generic object) does not. The only thing that
reliably changes on every edit is the AuditTrail row itself, so its `uuid`
(already the identifier `RunHistoryService::getRunTrace()` uses for a run,
and the identifier OR's own `RevertController::revert()` accepts as
`auditTrailId`) is the natural, already-precedented version id.
**Alternatives considered:** Using `updated` timestamp as the id — rejected:
not unique enough to be an unambiguous rollback target and not directly
accepted by `AuditTrailMapper::findByObjectUntil()`'s `auditTrailId` branch.

### Decision 3: `AgentVersionService` calls `AuditTrailMapper` directly (in-process); it does NOT call OpenRegister's `GET .../audit-trails` HTTP endpoint or the nc-vue `auditTrailsPlugin`
**Rationale:** Verified at HEAD:
`openregister/lib/Controller/AuditTrailController.php::objects()` calls
`requireAdmin()` (line ~400), which 403s any caller who is not an NC admin
group member. Hermiq's agent owners are ordinary Nextcloud users, not admins
— going through that endpoint (or its ready-made nc-vue store plugin,
`auditTrailsPlugin()` in `@conduction/nextcloud-vue/src/store/plugins/
auditTrails.js`, which targets the identical admin-gated route) would lock
the feature's actual audience out entirely. Hermiq already has the
established alternative: `RunHistoryService`, `BudgetService`,
`TenantOpsService`, `AnalyticsService` all inject `AuditTrailMapper` directly
(cross-app DI is an existing, working pattern in this monorepo — verified via
`grep -rn "OCA\\OpenRegister\\Db\\AuditTrailMapper" lib/`) and apply their
OWN, Hermiq-side RBAC instead of OR's admin gate. `AgentVersionService`/
`AgentVersionController` follow the exact same shape as `RunHistoryService`/
`RunHistoryController`.
**Alternatives considered:** Request an OR change to relax or parametrize the
admin gate — rejected for this change: out of scope per proposal.md
("Cross-Project Dependencies: None"), and the existing in-process pattern
already solves it without touching OR.

### Decision 4: Diffing/reconstructing a historical field value replays `changed['old']` backward from the live object, scoped to a fixed allowlist
**Rationale:** An individual AuditTrail row's `changed` map only contains the
fields that differed in THAT save (verified at HEAD,
`AuditTrailMapper::createAuditTrail()` lines 396-438) — it is not a full
snapshot. To know a field's value "as of version N" you compose the diffs
from the live object backward to N, exactly the algorithm
`AuditTrailMapper::revertObject()`/`revertChanges()` already runs in
production (lines 768-830) via `ReflectionClass`-based property assignment
onto a full `ObjectEntity` clone. This change reimplements that same
backward-walk but (a) scoped to only the fixed "agent config" field allowlist
(cheaper — most Agent edits touch 1-3 fields, not the whole object), (b) pure
and read-only for `diff()` (never calls `saveObject()`), and (c) does not
call OR's `/revert` endpoint even for rollback (see Decision 5).
**VERSIONED_FIELDS allowlist** (matches the brief's "prompt, model, provider,
tools, skills, capability profile" and the schema at
`lib/Settings/hermiq_register.json` lines 478-508): `prompt`, `model`,
`provider`, `temperature`, `maxTokens`, `configuration`, `tools`,
`skillInstalls`, `contextRefs`, `enableRag`, `ragSearchMode`, `ragNumSources`,
`ragIncludeFiles`, `ragIncludeObjects`, `views`, `searchFiles`,
`searchObjects`.
**Alternatives considered:** Calling OR's `POST .../revert` with
`overwriteVersion` as a "preview" — rejected: that endpoint always persists
(`RevertHandler::revert()` calls `$this->objectEntityMapper->update()`
unconditionally, line ~168) — there is no non-destructive preview mode, so it
cannot serve a read-only diff.

### Decision 5: Rollback calls `ObjectService::saveObject()` (Hermiq's own normal write path), not OR's `/revert` endpoint
**Rationale:** `ObjectService::saveObject()` is the exact call
`AgentsController::update()` already makes for every normal Agent edit
(verified at HEAD, line 374) and is admin-gate-free — it is subject to
Hermiq's own owner RBAC, same as any other edit. Reusing it means rollback is
"just another edit" from OR's point of view: it goes through the same
validation, the same automatic `createAuditTrail()` call (which is how the
rollback ITSELF becomes a new, diffable, re-rollback-able version — "never
mutates history" falls out for free), and needs zero new OR-side wiring.
Calling OR's own `/revert` endpoint instead was considered and would work
(it also ends in `objectEntityMapper->update()`), but it operates on the
FULL object (all fields, via its own reverse-diff walk) where this change
needs the allowlisted subset merged onto the CURRENT payload — reconstructing
the allowlisted values with `AgentVersionService` and merging them into the
existing `AgentsController::update()`-style partial-update call is a closer
fit and avoids resurrecting identity/visibility/quota/governance fields that
are deliberately out of scope (Decision 4's allowlist).
**Alternatives considered:** OR's `/revert` endpoint directly — rejected per
above (reverts the whole object, not the allowlisted subset; also
admin-permission-checked via `PermissionHandler`, a second RBAC system to
reconcile with Hermiq's owner-only guard).

### Decision 6: Version identifier pinned on run-audit context is the SAME AuditTrail-entry-UUID scheme as Decision 2, captured at run start
**Rationale:** Each of the four run-audit writers already has the Agent
`ObjectEntity` at hand at the moment it builds its context array (verified:
`ScheduleService::runAgentAsOwner()` line 1528 / `runAgentViaEngine()` line
1671; `FlowAgentRunService`, `WebhookAgentRunService`,
`ContextAgentInteractionService` all resolve the Agent before running). Each
writer calls `AgentVersionService::currentVersionId($agentUuid)` (a thin
wrapper: latest `create`/`update` AuditTrail entry for that object,
`created DESC`, `limit 1`) and adds `'agentVersion' => $versionId` to its
existing `$context` array — the same pattern `attempt`/`steps`/
`toolStepsAvailable` already use in `ScheduleService::writeRunAudit()` (lines
1266-1273). Never fatal: same try/catch every writer already wraps its audit
call in.

## Risks / Trade-offs

- [Risk] `canUserAccessAgent()`/`canUserModifyAgent()` RBAC logic is
  duplicated per-controller in this codebase already (verified:
  `AgentsController` and `ChatStreamController` each keep their own private
  copy, not a shared trait/service) → [Mitigation] `AgentVersionController`
  follows the same existing convention (its own private copies) rather than
  introducing a new shared abstraction as an unrelated side-change; extracting
  a shared `AgentAccessGuard` is a reasonable future cleanup but is scope
  creep for this change.
- [Risk] Replaying `changed['old']` backward for a field that was NEVER
  present in the schema at the time of an old entry (schema evolution) could
  read a stray key → [Mitigation] `diff()`/`rollback()` only ever read/write
  keys in the fixed `VERSIONED_FIELDS` allowlist; an old entry with no
  recorded change for an allowlisted field simply means that field was
  unchanged at that step, which is the correct outcome (mirrors
  `revertChanges()`'s own `$change['old'] ?? null` per-field null-safety).
- [Risk] A very old/large Agent with hundreds of edits makes the backward
  walk O(versions since target) → [Mitigation] `findAll()` is already
  filtered to `object_uuid` + `action=create,update` (indexed, small volume
  in practice — Agents are edited by humans, not machine-frequency); no
  pagination limit is applied to the walk itself since correctness requires
  seeing every entry back to the target, but the version-history LIST view
  (not the walk) is paginated for display.

## Migration Plan

Not applicable — no schema, table, or data migration. See migration.md
skip note.

## Open Questions

- Whether to resolve `skillInstalls` UUIDs to skill names in the diff
  response (backend) or leave that to the frontend (which already has the
  skills catalogue loaded on `AgentDetail.vue`) — leaning frontend-side
  resolution to keep `AgentVersionService` free of a cross-schema join;
  confirmed during implementation if catalogue data isn't reliably available
  at diff-render time.
