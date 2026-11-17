# Design: multi-tenant-ops

## Context

MSP/org operational controls on Hermiq's OR-native multi-tenancy (ADR-001 Option C+,
ADR-004 governance). Builds on `organisation`/`owner`/`groups` — no parallel tenancy layer.

## Decisions

**Quota = surface + advise; hard create-reject is an OR seam.** Hermiq objects are created
through OpenRegister's generic object API (the SPA's `createObjectStore` → OR), NOT a Hermiq
endpoint — so Hermiq cannot intercept creation to hard-reject over-quota writes. Instead
`quotaStatus()` reports the caller's usage (schedules — accurate, Hermiq-owned; agents — the
distinct agentIds bound to the caller's schedules, a Hermiq-visible proxy) against
configurable limits, and the UI advises + warns at the limit. The authoritative
create-time reject belongs to OR (a create-hook on the object API) and to OR's agent
inventory — documented seams, not faked in Hermiq.

**Tenant scope by loading the caller's objects first.** Both `quotaStatus()` and
`exportAuditTrail()` start by loading the caller's Hermiq objects through `ObjectService`
(RBAC + multitenancy ON). That yields exactly the tenant's object set; the audit export
then reads `AuditTrail` entries by those objects' `object_uuid`. An object (or its audit)
from another org is never in the caller's set, so the export can't leak cross-tenant — the
same boundary `run-analytics` uses, and it doesn't depend on the audit row's own org column.

**AI Act export = the org's governance trail, already redacted.** The export assembles the
`run`/gate/kill-switch audit records for the caller's schedules + the decision records for
their approvals into `{generatedAt, recordCount, records[]}`. Entries were redacted at
write time (`ScheduleService`/`ApprovalService` redact before persist), so the export needs
no extra masking. The controller serves it with a download `Content-Disposition`.

**Isolation is verified, not newly built.** Every Hermiq object already carries OR
`organisation`/`owner`/`groups` and all reads are RBAC-on (schema, memory, skills,
approvals, tenant-control all shipped this way). The org-scoped export is the proof point;
this change documents the invariant rather than adding a second enforcement layer.

**Local inference = config.** Agents already target the Ollama provider (`localhost:11434`).
Sovereign local-only inference is an agent-config choice (no external egress required),
documented — no new Hermiq mechanism.

## Risks / Trade-offs

- **Agent count is a proxy.** [distinct agentIds in schedules ≠ the org's full agent
  inventory] → Reported as "agents in use"; the authoritative count + create-reject is an OR
  seam. Honest labelling in the UI.
- **Full audit read per object.** [reads each object's AuditTrail] → Acceptable at current
  volume; bounded by the caller's object count. Path to optimise: a single org-filtered
  audit query when OR's audit org column is guaranteed populated.

## Open Questions

- **OR seam — create-time quota reject + agent inventory.** Blocked on an OR object-API
  create-hook and an org agent-count API. Documented; Hermiq surfaces + advises today.
