# run-analytics Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- observability-feedback-per-agent

## Purpose

Show how people rated an agent's answers. Row `hermiq:ob-feedback-stats`.

## ADDED Requirements

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
