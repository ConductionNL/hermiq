# run-analytics (delta)

Implements the read-only analytics surface of the `run-analytics` capability over Hermiq's
`action='run'` OR AuditTrail entries. Cost/token/tool-usage remain an OR seam.

## ADDED Requirements

### Requirement: Dashboard metrics derived from AuditTrail
The system MUST compute success rate, latency, status breakdown, and per-agent run metrics
directly from OpenRegister's `AuditTrail` run records, without a separate analytics store.

#### Scenario: An admin views run analytics for an agent
- **GIVEN** an agent with runs recorded in OR `AuditTrail`
- **WHEN** the analytics view is opened for that agent
- **THEN** success rate, latency, and status-breakdown metrics MUST be computed from the
  existing `AuditTrail` run entries
- **AND** no separate analytics data store MUST be required

### Requirement: Per-agent and per-tenant breakdown
The system MUST let a user view metrics for a single agent or aggregated across all agents
in their organisation, and MUST NOT show data belonging to a different organisation.

#### Scenario: A tenant admin views organisation-wide analytics
- **GIVEN** an organisation with several agents' run history
- **WHEN** the aggregated analytics view is opened
- **THEN** combined metrics for the caller's agents MUST be shown
- **AND** run data from any other organisation MUST NOT be included (only the caller's own
  schedules' run entries are aggregated)

### Requirement: Cost/token/tool-usage surfaced honestly
The system MUST surface LLM cost, token, and tool-usage metrics as unavailable
(awaiting OpenRegister run-cost recording) wherever Hermiq's run entry does not
record them, rather than presenting fabricated values.

#### Scenario: The analytics view renders without OR cost data
- **WHEN** the analytics view renders and OR has not recorded per-run token/cost/tool data
- **THEN** the cost/token/tool-usage metrics MUST be shown as "not recorded yet", not as zero
  or invented numbers
