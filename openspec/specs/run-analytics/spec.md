# Run Analytics Specification

**Status**: idea

**Feature tier**: V2

**OpenSpec changes:** _(none yet)_

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
