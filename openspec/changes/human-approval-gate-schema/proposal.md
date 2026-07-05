---
kind: config
---

# Proposal: human-approval-gate-schema

## Why

EU AI Act Article 14 requires durable human oversight and a stop mechanism for
autonomous agents. Hermiq's dispatcher currently fires every due schedule with no
human gate and no way for an org admin to halt a tenant's runs. Before any dispatcher
enforcement can exist, the durable state it enforces on must be declared: an
`Approval` object state machine, a per-schedule `requiresApproval` flag, and a
per-organisation kill-switch state. This change declares that data and nothing else.

## What Changes

- Add a new declarative OpenRegister schema **`Approval`** to
  `lib/Settings/hermiq_register.json`: a state machine object with `status`
  (`pending`|`approved`|`denied`), `scheduleId`, `agentId`, `prompt`, `requestedAt`,
  `decidedAt`, `decidedBy`, `reason`, and the resolved `reviewer` + `reviewerType`
  (separation of duties — the approver may differ from the owner). Tenant scoping
  (`owner`/`organisation`) comes from OpenRegister `ObjectEntity`. This is the
  auditable Art. 14 approval queue.
- Add a new declarative OpenRegister schema **`TenantControl`** (the org kill-switch):
  a durable object with `engaged` (boolean), `reason`, `engagedAt`, `engagedBy`,
  keyed to an organisation via `ObjectEntity.organisation`. Engaged ⇒ the dispatcher
  halts all of that org's runs.
- **MODIFY** the existing `Schedule` schema: add an optional boolean
  `requiresApproval` (default `false`) plus an optional `reviewer` (NC user id or
  group id) and `reviewerType` (`user`|`group`, default `user`) designating who must
  approve a gated run (empty ⇒ defaults to the owner, backward compatible). A schedule
  with `requiresApproval=true` causes the dispatcher (downstream) to create a pending
  `Approval` routed to the reviewer instead of running the agent.
- This is **config-only** — a JSON register patch imported via
  `ConfigurationService::importFromApp()` in the existing repair step. No PHP, no
  controller, no service, no new write path (ADR-031 declarative side).

This is the **head of a three-change chain** (ADR-032 split of the mixed
`human-approval-gate` feature — a mixed schema+code change MUST NOT be generated):

1. **`human-approval-gate-schema`** (this change, kind `config`) — declares the
   `Approval` + `TenantControl` schemas and the `Schedule.requiresApproval` +
   `reviewer`/`reviewerType` fields.
2. **`human-approval-gate-enforcement`** (kind `code`,
   `depends_on: [human-approval-gate-schema]`) — the dispatcher gate (create pending
   Approval routed to the reviewer + skip on kill-switch, both synchronous), the
   reviewer/admin-guarded approve/deny endpoints, and the subadmin/instance-admin
   kill-switch-toggle endpoint, plus the notification hook.
3. **`human-approval-gate-ui`** (kind `code`,
   `depends_on: [human-approval-gate-enforcement]`) — the thin Vue surface: an
   Approval inbox routed to the current reviewer with Approve/Deny, and a kill-switch
   toggle for org subadmins / instance admins.

Splitting the schemas (declarative) from the enforcement and UI (imperative) keeps
each change single-kind and within the ≤20-task cap, and lets the schema land and
validate independently.

## Capabilities

### New Capabilities
- `human-approval-gate-schema`: the declarative `Approval` state-machine schema
  (pending→approved/denied) and the `TenantControl` org kill-switch schema — their
  properties, enums, defaults, and the tenant scoping inherited from `ObjectEntity`.

### Modified Capabilities
<!-- None as a spec delta. The new Schedule.requiresApproval flag is a new concern
     (not a change to existing Schedule behavior), so it is captured as an ADDED
     requirement within the human-approval-gate-schema capability rather than a
     MODIFIED delta against the coarse-grained agent-schedule MVP spec. -->
- <!-- none -->

### Note on the Schedule schema
The `Schedule` schema JSON in `lib/Settings/hermiq_register.json` gains an optional
`requiresApproval` boolean (default `false`) plus optional `reviewer` (string) and
`reviewerType` (`user`|`group`) fields; their requirements are specified under the
new capability's ADDED requirements.

## Impact

- **Config:** `lib/Settings/hermiq_register.json` gains `Approval` and
  `TenantControl` entries under `components.schemas`, and the `Schedule` schema gains
  `requiresApproval`, `reviewer`, and `reviewerType` properties.
- **Data:** OpenRegister creates magic tables for `Approval` and `TenantControl`
  objects in the `hermiq` register on import; existing schemas are untouched (union
  import, no regression).
- **No code, no API surface, no dependencies** added by this change. The downstream
  `human-approval-gate-enforcement` change consumes these schemas and adds the runtime.
- **Downstream:** unblocks `human-approval-gate-enforcement`.
