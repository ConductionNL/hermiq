# agent-schedule Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- scheduling-overview-and-start-choice

## Purpose

One list of schedules, one place to choose how an agent starts, and schedules set in plain words. Rows `hermiq:sc-list`, `hermiq:sc-manual-event-choice` and `hermiq:sc-nl`.

## ADDED Requirements

### Requirement: Every schedule a person may see is on one page (REQ-SCHED-001)

The system MUST offer a Schedules page listing the schedules the user owns or of agents the user may use, and every schedule of the organisation to an organisation admin, with agent, when, next run, last result and enabled, filterable by agent, enabled and failed last result.

#### Scenario: A functional admin checks which schedules failed last night
- GIVEN a functional admin with twelve schedules across five agents, two of which failed
- WHEN they open Schedules and filter on "Last result: failed"
- THEN two rows show, each with its agent, next run and the error on hover

#### Scenario: A colleague does not see another person's private agent's schedule
- GIVEN a schedule of a private agent the user may not use
- WHEN the user calls `GET /api/schedules`
- THEN that schedule is not in the answer
- @e2e exclude authorization contract, covered by PHPUnit and Newman

### Requirement: An agent's page offers one choice of how it starts (REQ-SCHED-002)

The system MUST show on the agent page how the agent starts: by hand, on a schedule and on an event, each with whether it is active. A user who may use the agent MUST be able to run it by hand with a prompt without a schedule, through the same governance gates as a scheduled run. "Add event" MUST create a draft flow with a trigger and this agent's step for the person to finish on the canvas.

#### Scenario: A case handler runs an agent by hand without a schedule
- GIVEN an agent with no schedule
- WHEN a case handler enters "Summarise the open objections" under "By hand" and chooses "Run now"
- THEN the run starts, appears in run history with trigger "manual", and no schedule was created

#### Scenario: An owner adds an event start
- GIVEN the owner on the agent page
- WHEN they choose "Add event" and "When an object is created or changed"
- THEN a draft flow opens on the canvas with a trigger node and this agent's step, inactive until they activate it

### Requirement: A schedule can be set in plain words (REQ-SCHED-003)

The system MUST read a plain English or Dutch description of when a schedule runs, turn it into a rule, and show a readback and the next three runs before saving. It MUST store the rule, not the words. Text it cannot read MUST be refused with an example.

#### Scenario: A team lead schedules a weekday digest
- GIVEN a team lead creating a schedule
- WHEN they type "every weekday at eight" in "When should it run?"
- THEN the form shows "Every weekday at 08:00, Europe/Amsterdam" and the next three weekday dates, and saving stores the cron rule `0 8 * * 1-5`

#### Scenario: A Dutch phrase is read too
- GIVEN the same form in Dutch
- WHEN the team lead types "elke maandag om negen uur"
- THEN the readback is "Elke maandag om 09:00" and the rule is `0 9 * * 1`
- @e2e exclude parser table, covered by PHPUnit on ScheduleWhenParser
