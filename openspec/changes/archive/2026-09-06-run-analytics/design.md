# Design: run-analytics

## Context

Read-only analytics over Hermiq's run-audit entries. ADR-004: governance + run records live
in OR's `AuditTrail`; ADR-001 Option C+: Hermiq owns the read/shape surface, not a store.
Reuses the same `action='run'` entries `run-audit-log` writes + `RunHistoryService` reads.

## Decisions

**Tenant scope = the caller's own schedules.** `AuditTrailMapper::findAll` is not
tenant-filtered, so the security boundary is: load the caller's schedules through
`ObjectService` (RBAC + multitenancy ON) → that yields the set of schedule UUIDs the caller
may see → aggregate ONLY the `run` audit entries whose `object_uuid` is in that set. An
entry for another org's schedule is never counted because that schedule is never in the
caller's set. Per-agent view filters the schedule set to those with the given `agentId`.

**Metrics from what the run entry records.** Each `run` entry's `changed` payload carries
`status`, `agentId`, `durationMs`, `startedAt/endedAt`. So `AnalyticsService` computes:
`totalRuns`, `successRate` (`status='ok'`), `statusBreakdown` (count per status —
`ok`/`error`/`awaiting_approval`/`skipped_killswitch`/…), `latency` (avg/min/max
`durationMs`), and `perAgent` (runs + successes per agent). No separate store, no ETL.

**Cost/tokens/tool-usage are an OR seam.** Hermiq's run entry does not record LLM token
usage, cost, or which tools a turn called — those belong to OR's `ChatService`/`SearchTrail`
audit of the LLM call. The service returns them as `null`/"not recorded" and the UI shows a
clear "awaiting OpenRegister run-cost recording" note rather than fabricating numbers. When
OR exposes per-run token/cost/tool data, `AnalyticsService` extends to read it.

## Risks / Trade-offs

- **Full `run` scan.** [findAll('run') returns all tenants' run entries before filtering] →
  Acceptable at current volume; the caller's schedule-set filter is applied in memory. If it
  grows, add an `object_uuid IN (...)` filter (OR findAll supports column filters).
- **AuditTrail object=NULL gotcha.** Inherited from `run-audit-log`: app-written run entries
  set `object_uuid` but not the integer `object`, so reads go by `object_uuid` (same as
  `RunHistoryService`), not `getLogs()`.

## Open Questions

- **OR seam — run cost/tokens/tools.** Blocked on OR recording per-run LLM usage. Documented;
  the metric slots exist and are null until then.
