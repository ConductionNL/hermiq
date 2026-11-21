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

### Requirement: A cross-agent run list, on the same tenant boundary as the metrics

The system MUST provide one list of the caller's runs across every agent they may see,
newest first, filterable by agent and by status and paged with an unpaged `total`. It
MUST resolve visibility through the same agent-set boundary the metrics above use, so
the list and the metrics can never disagree about which runs are the caller's. It MUST
include runs from BOTH run channels, and MUST say on each row which channel it arrived
on. It MUST exclude dry-run and replay previews, exactly as the metrics do.

🔴 The schedule-scoped read (`/api/schedules/{scheduleId}/runs`) cannot satisfy this and
is not a substitute. It is addressed by one schedule, and a flow-triggered `agent-run`
entry hangs on the object that triggered the flow, which routinely lives in another
register. No schedule's `object_uuid` ever matches one, so those runs were counted by
the metrics and absent from every list.

#### Scenario: An operator looks for last night's failures
- **GIVEN** several agents ran overnight and some runs failed
- **WHEN** the operator opens the run list and filters on the failed status
- **THEN** the system MUST list those runs newest first, naming the agent for each
- **AND** the system MUST NOT include a run belonging to another organisation

#### Scenario: A flow-triggered run is listed
- **GIVEN** an agent run was started by a flow, and its audit entry hangs on the
  triggering object rather than on a schedule
- **WHEN** the run list is opened
- **THEN** that run MUST appear in the list
- **AND** the row MUST identify its channel as a flow rather than a schedule

#### Scenario: A dry run never appears
- **GIVEN** an agent has a real run and a dry-run preview recorded
- **WHEN** the run list is opened
- **THEN** only the real run MUST be listed
- **AND** the list's total MUST count only the real run, matching the metrics

#### Scenario: A run's delivered link opens the run it describes
- **GIVEN** a run's output was delivered to Talk with a link
- **WHEN** the recipient follows that link
- **THEN** the system MUST open a surface showing that schedule's runs
- **AND** the system MUST NOT resolve the link to an unrelated page

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

### Requirement: An agent owner sees how answers were rated (REQ-FBSTAT-001)

`GET /api/analytics` MUST return a `feedback` block with `positive`, `negative` and `helpfulRate`, on the same tenant boundary and agent scope as the run metrics, and each `perAgent` row MUST carry `positive` and `negative`. The agent page MUST show a "Rated helpful" tile with the share and the counts, and "No ratings yet" when there are none.

#### Scenario: An owner checks how an agent is received
- GIVEN the owner of "Permit helper", whose answers got three thumbs up and one thumbs down
- WHEN they open the agent page
- THEN the "Rated helpful" tile shows 75% with 3 up and 1 down

#### Scenario: The dashboard compares agents
- GIVEN two agents with ratings
- WHEN a user opens the dashboard
- THEN the per-agent breakdown shows the thumbs up and thumbs down for each agent beside its runs

### Requirement: An agent owner reads the latest low ratings (REQ-FBSTAT-002)

The agent page MUST list the last ten thumbs-down ratings that carry a comment, newest first, with the date and without the rater's name. `GET /api/analytics/agents/{agentId}/low-ratings` MUST answer HTTP 404 to a user who may not read the agent.

#### Scenario: An owner reads why answers were rated down
- GIVEN a thumbs down on "Permit helper" with the comment "Gave the old opening hours"
- WHEN the owner opens the agent page
- THEN "Latest low ratings" lists "Gave the old opening hours" with its date

#### Scenario: A user without access
- GIVEN a private agent the user may not read
- WHEN they call `GET /api/analytics/agents/{agentId}/low-ratings`
- THEN the answer is HTTP 404
- @e2e exclude authorization contract on the endpoint, covered by PHPUnit

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
