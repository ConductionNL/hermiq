# run-analytics (delta)

Adds a pre-run rough cost estimate, derived from the SAME per-agent token-usage aggregation
`AnalyticsService::computeAnalytics()` already computes, surfaced at the point a run is about
to be created (Run now, schedule creation) so a user can see likely spend before committing —
without introducing a new telemetry pipeline or duplicating the audit-trail read.

## ADDED Requirements

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
