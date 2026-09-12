# Design: human-approval-gate-schema

## Context

Hermiq is a thin Nextcloud app that owns no database tables — all persistence goes
through OpenRegister's `ObjectService` against declarative schemas declared in the
app's schema register `lib/Settings/hermiq_register.json` (ADR-001). Governance,
human oversight, and the kill-switch are modeled as durable OpenRegister object
**states**, not in-memory queues, enforced synchronously in the dispatch loop and
recorded in OpenRegister's hash-chained `AuditTrail` (ADR-004). Every write goes
through `ObjectService` (single write-path) so tenancy and the audit trail are
inherited.

The `human-approval-gate` feature is mixed (new schemas + dispatcher enforcement +
guarded endpoints). Under ADR-032 a mixed change MUST NOT be generated. It is split
into a config head (this change — the durable state) and a code change
(`human-approval-gate-enforcement` — the dispatcher gate + endpoints). This change
declares the `Approval` and `TenantControl` schemas and the `Schedule.requiresApproval`
flag only. No imperative code is added here — schemas are data, and OpenRegister
validates and persists them.

## Goals / Non-Goals

**Goals:**
- Declare an `Approval` schema modeling the Article 14 state machine
  (`status: pending → approved/denied`) plus the record fields (`scheduleId`,
  `agentId`, `prompt`, `requestedAt`, `decidedAt`, `decidedBy`, `reason`) and the
  resolved reviewer designation (`reviewer`, `reviewerType`) for separation of duties.
- Declare a `TenantControl` schema representing the per-organisation kill-switch as a
  durable OR object (`engaged`, `reason`, `engagedAt`, `engagedBy`), tenant-scoped via
  `ObjectEntity.organisation`.
- Add optional `requiresApproval` (default `false`), `reviewer`, and `reviewerType`
  (default `user`) fields to the existing `Schedule` schema, backward compatible with
  all existing schedules.
- Keep the change config-only and union-import-safe (existing `example`, `Schedule`,
  and other schemas untouched apart from the one added `Schedule` property).

**Non-Goals:**
- Any PHP: no dispatcher gate, no approve/deny endpoint, no kill-switch toggle
  endpoint, no notification wiring. Those belong to `human-approval-gate-enforcement`.
- The synchronous enforcement semantics themselves (create-pending-instead-of-run,
  skip-on-engaged) — specified and implemented downstream.
- Frontend UI for approving/denying or toggling the kill-switch — built in the
  downstream `human-approval-gate-ui` change, not here.

## Decisions

**Declarative schemas in the register (ADR-001 / ADR-031).** `Approval` and
`TenantControl` are pure data declared in `components.schemas` of the OpenAPI-3.0.0
register document. This is the declarative side of ADR-031 — no
derived-field/lifecycle/aggregation logic lives here; they are plain object schemas.
There is no imperative code in this change at all.

**Approval as its own schema, not a Schedule sub-state (recommended).** The approval
record is a **separate `Approval` object**, not fields grafted onto `Schedule`.
Rationale: (a) it gives an auditable *queue* — many pending/decided approvals accrue
over time, each a durable object with its own `AuditTrail` hash-chain entry, which a
single overwritten Schedule sub-state cannot hold; (b) it is the first-class EU AI
Act Art. 14 record of *who approved/denied what and when* (`decidedBy`/`decidedAt`/
`reason`), preserved independently of the schedule's mutable run-state; (c) the
source-of-truth spec explicitly models "approval as an OpenRegister object state
machine (pending → approved/denied)" and requires "`Approval` OR object schema
exists". The trade-off is one extra schema and one extra magic table, accepted as the
compliance-correct choice.

**Kill-switch as a `TenantControl` OR object, not app-config (recommended).** The
kill-switch is a durable per-organisation OR object rather than an `IAppConfig` value
or an OR register-config flag. Rationale: (a) ADR-004 requires every governance state
change to flow through `ObjectService` so it is tamper-evident and appears in the
hash-chained `AuditTrail` — an app-config write bypasses the single write-path and
leaves no audit record; (b) it is naturally tenant-scoped via
`ObjectEntity.organisation` (one control object per org), whereas `IAppConfig` is
instance-global and would need ad-hoc org-key encoding outside the RBAC model; (c)
the dispatcher already reads OR objects each tick, so checking engaged controls is the
same `ObjectService.findAll` path it uses for schedules. Alternatives considered:
*app-config keyed by org* (rejected — bypasses audit + single write-path, not
RBAC-scoped); *OR register-config flag* (rejected — register config is app-global, not
per-tenant, and not an auditable object).

**requiresApproval on Schedule, defaulting false.** The gate is opt-in per schedule.
Declaring it optional with default `false` means every existing schedule remains
ungated and behaviorally unchanged — no migration of existing objects is required.

**Reviewer designation on Schedule (separation of duties, Art. 14).** The reviewer is
declared on the `Schedule` as `reviewer` (string) + `reviewerType` (enum `user` |
`group`, default `user`), and copied onto each pending `Approval` at creation so the
decision is a durable record of *who was asked*. Both an NC user id and an NC group id
are supported (`reviewerType` disambiguates) so a real role-based, separation-of-duties
model is possible (approver ≠ owner): a group reviewer means "any member of this group
may approve". When `reviewer` is empty the downstream dispatcher defaults it to the
owner (backward compatible — a gated schedule with no reviewer still works, just
without separation of duties). *Alternatives considered:* a single free `reviewer`
string with heuristic user-vs-group detection (rejected — ambiguous, and NC ids can
collide between users and groups); reviewer only on `Approval` (rejected — the
dispatcher needs it on the `Schedule` at gate time to route the new `Approval`). The
declarative schema only stores the fields; the resolve-and-guard logic (group
membership, owner-default) is imperative and lives downstream.

**Tenant scoping is inherited.** `owner`/`organisation` are NOT schema properties on
either new schema; OpenRegister's `ObjectEntity` supplies them automatically. This
keeps a single write-path and the multi-tenant model (ADR-001 / ADR-004).

## Seed Data (ADR-001)

Illustrative objects (declarative — schemas are data, not code). All UUIDs use the
NIL UUID `00000000-0000-0000-0000-000000000000`; user ids and org values are
`<angle-bracket>` placeholders — never a realistic-looking UUID or secret.

A gated `Schedule` (a permit-approval agent a municipality wants a human to sign off
before it acts):

```json
{
  "name": "Draft permit decisions for review",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "kind": "cron",
  "cronExpr": "0 8 * * 1-5",
  "prompt": "Draft a decision for each permit application that is ready, but do not send anything.",
  "deliver": "notification",
  "enabled": true,
  "requiresApproval": true,
  "reviewer": "<permit-reviewers-group-id>",
  "reviewerType": "group",
  "repeat": null,
  "nextRun": null,
  "lastStatus": null,
  "lastError": null
}
```

A sample pending `Approval` (created by the dispatcher when the gated schedule above
came due; `reviewer`/`reviewerType` are copied from the schedule so the decision is
routed to a party distinct from the owner):

```json
{
  "status": "pending",
  "scheduleId": "00000000-0000-0000-0000-000000000000",
  "agentId": "00000000-0000-0000-0000-000000000000",
  "prompt": "Draft a decision for each permit application that is ready, but do not send anything.",
  "requestedAt": "2026-07-05T08:00:00+00:00",
  "reviewer": "<permit-reviewers-group-id>",
  "reviewerType": "group",
  "decidedAt": null,
  "decidedBy": null,
  "reason": null
}
```

A sample engaged `TenantControl` (an org admin has hit the kill-switch during an
incident); the organisation it applies to comes from `ObjectEntity.organisation`, not
a property:

```json
{
  "engaged": true,
  "reason": "Incident response: pausing all autonomous runs pending investigation",
  "engagedAt": "2026-07-05T09:15:00+00:00",
  "engagedBy": "<org-admin-uid>"
}
```

- A **municipality** gates a permit-drafting agent with `requiresApproval=true`; each
  due run yields a pending `Approval` a caseworker must approve before the agent acts.
- The **pending `Approval`** carries the `scheduleId`/`agentId`/`prompt`/`requestedAt`
  plus the resolved `reviewer`/`reviewerType` so a member of the permit-reviewers
  group (not the owner) sees exactly what would run; `decidedAt`/`decidedBy`/`reason`
  fill in on the decision.
- An **org admin** engages a `TenantControl` for their organisation during an
  incident, halting every run for that tenant only.

## Risks / Trade-offs

- **Extra schemas / magic tables.** Two new schemas add two OR magic tables. → Accepted:
  the auditable-queue and per-tenant-kill-switch requirements make a separate Approval
  object and a TenantControl object the compliance-correct model over sub-states or
  app-config.
- **Union import collisions.** The register import unions duplicate schema definitions;
  `Approval` and `TenantControl` must not collide with existing schema names, and the
  merged register must be re-validated as well-formed JSON (a merge can silently
  dup-keys JSON). → Re-parse `hermiq_register.json` after editing; verify the existing
  `example`/`Schedule` schemas are unchanged.
- **Conditional-requirement expressiveness.** As documented for the `Schedule` schema,
  OpenRegister's importer rejected JSON-Schema `if`/`then` conditionals
  (`SchemaMapper::loadSchema` expects a string identifier). → Keep `Approval` /
  `TenantControl` requirements flat (required arrays + enums only); any cross-field
  rule (e.g. `decidedBy` present when `status!=pending`) is enforced by the downstream
  enforcement code and endpoints, not declaratively.
- **Soft references.** `Approval.scheduleId`/`agentId` are soft UUID references;
  OpenRegister does not enforce they point at live objects — a dangling reference is
  handled by the downstream dispatcher/endpoints.
