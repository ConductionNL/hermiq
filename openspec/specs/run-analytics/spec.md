# Run Analytics Specification

**Status**: active (live-verified; cost/token/tool-usage await an OpenRegister recording seam)

**Feature tier**: V2

**OpenSpec changes:** `run-analytics` — DONE: `AnalyticsService` computes totalRuns/successRate/statusBreakdown/latency/perAgent from the `action='run'` OR AuditTrail entries (no separate store), tenant-scoped to the caller's own schedules; `AnalyticsController` (`/api/analytics`, optional `agentId`); `RunAnalytics` UI (metric cards + status breakdown + per-agent table + agent filter). Cost/token/tool-usage surfaced as "not recorded yet" (an OpenRegister seam, not fabricated).

## Purpose

Surfaces dashboards over agent run and audit data — success rate, cost/tokens, latency, and tool
usage — broken down per-agent and per-tenant. Built entirely on OpenRegister's existing `AuditTrail`
and `SearchTrail` records and rendered with nc-vue dashboard widgets, so no separate analytics store
or ETL pipeline is introduced.
## Requirements
### Requirement: Dashboard metrics derived from AuditTrail/SearchTrail
The system MUST compute success rate, cost/token usage, latency, and tool-usage metrics directly
from OpenRegister's `AuditTrail` and `SearchTrail` records, without duplicating that data into a
separate analytics database.

#### Scenario: An admin views run analytics for an agent
- GIVEN agent X has completed multiple runs recorded in OR `AuditTrail`
- WHEN an admin opens the run analytics dashboard for agent X
- THEN the system MUST compute success rate, cost/token, and latency metrics from the existing `AuditTrail`/`SearchTrail` records
- AND the system MUST NOT require a separate analytics data store to render the dashboard

### Requirement: Per-agent and per-tenant breakdown
The system MUST let a user view metrics scoped either to a single agent or aggregated across all
agents within their organisation, and MUST NOT show data belonging to a different organisation.

#### Scenario: A tenant admin views organisation-wide analytics
- GIVEN organisation A has three agents with run history
- WHEN a tenant admin of organisation A opens the aggregated analytics view
- THEN the system MUST show combined metrics for all three of organisation A's agents
- AND the system MUST NOT include run data from any other organisation

### Requirement: Pre-run cost estimate derived from trailing per-agent run history
The system MUST derive a pre-run cost/token estimate for a given agent from that agent's own
trailing run history (the same `action='run'` AuditTrail entries `AnalyticsService` aggregates),
and MUST clearly label the figure as an estimate, never as a guarantee or a hard prediction.
The estimate MUST NOT be used as an enforcement input — only actual recorded usage may block a
run (see `multi-tenant-ops`'s budget hard-cap requirement).

#### Scenario: A user opens Run now for an agent with prior run history
- **GIVEN** agent X has completed several runs with recorded token usage in OR `AuditTrail`
- **WHEN** a user opens the Run now action (or the schedule-creation form) for agent X
- **THEN** the system MUST show a trailing-average token/cost estimate for agent X
- **AND** the estimate MUST be visibly labelled as an estimate (not a committed figure)

#### Scenario: A user opens Run now for an agent with no run history yet
- **GIVEN** agent Y has never completed a run
- **WHEN** a user opens the Run now action for agent Y
- **THEN** the system MUST report the estimate as unavailable rather than fabricating a zero or
  a default figure
- **AND** the Run now action MUST still be available (an unavailable estimate never blocks a run)

#### Scenario: The estimate never influences the hard-cap gate
- **GIVEN** agent X has a pre-run estimate showing high projected token usage
- **WHEN** the dispatch gate evaluates whether agent X's budget is exhausted
- **THEN** the gate MUST evaluate only actual current-period recorded usage
- **AND** the estimate value MUST NOT be read by the gate at all

## User Stories

- As a tenant admin, I want to see success rate and cost trends across my agents so that I can judge whether autonomous runs are worth the spend.
- As an agent builder, I want per-agent latency and tool-usage breakdowns so that I can spot slow or misbehaving tool calls.
- As a compliance officer, I want analytics sourced from the same audited records used for governance so that dashboard numbers and audit exports never disagree.

## Acceptance Criteria

- [ ] Success rate, cost/tokens, latency, and tool-usage metrics are computed from OR `AuditTrail`/`SearchTrail`
- [ ] Dashboard is available per-agent and aggregated per-tenant
- [ ] Cross-tenant data leakage is not possible in the aggregated view
- [ ] Metrics render via nc-vue dashboard widgets consistent with the rest of the fleet
- [ ] No new analytics-specific data store is introduced

## Notes

Depends on OpenRegister's `AuditTrail` (hash-chain, GDPR) and `SearchTrail`, and on nc-vue dashboard
widget components. Related: ADR-004 (governance via OR AuditTrail), ADR-001 (Option C+). Should reuse
the fleet's existing dashboard-widget patterns rather than a bespoke charting layer (see
`reference_pipelinq-dash-fixes` conventions).
