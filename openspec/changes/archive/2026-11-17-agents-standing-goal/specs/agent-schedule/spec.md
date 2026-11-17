# agent-schedule Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-standing-goal

## Purpose

Give an agent a standing goal it keeps working on across turns until a check says it is reached. Row `hermiq:dm-standing-goal`.

## ADDED Requirements

### Requirement: A person can give an agent a standing goal with a check (REQ-AGGOAL-001)

The system MUST let a person set one active goal on a session with an agent they may use, with a statement, a check, an interval of 15 to 1440 minutes and a turn limit of 1 to 50. The check MUST be either an object count with a target, evaluated as the goal owner, or a judge question answered through the model provider chokepoint.

#### Scenario: A permit officer sets a reminder goal
- GIVEN a permit officer in a session with the agent "Permit reminder"
- WHEN they choose "Set a goal", enter "Every overdue permit application has had a reminder", pick the check "Count of overdue applications without a reminder is 0", every 60 minutes, at most 10 turns, and save
- THEN the session header shows the goal, "turn 0 of 10" and "Stop goal"

### Requirement: Goal turns continue the same session through the scheduled-run gates (REQ-AGGOAL-002)

The system MUST run each goal turn in the goal's session, through the kill switch, budget and approval gates of a scheduled run. After each turn it MUST run the check and store the result. It MUST set the goal to reached when the check passes, to exhausted when the turn limit is used, and MUST notify the goal owner in both cases.

#### Scenario: The goal is reached after three turns
- GIVEN an active goal whose check counts 4, 1 and then 0 overdue applications after turns one to three
- WHEN the third turn's check runs
- THEN the goal is reached, no fourth turn runs, and the officer gets a notification "Goal reached: Every overdue permit application has had a reminder"
- @e2e exclude background job, covered by PHPUnit on ScheduleService and GoalCheckService

#### Scenario: The kill switch holds a goal
- GIVEN an active goal whose organisation's kill switch is engaged
- WHEN its next turn is due
- THEN no turn runs, the goal shows "blocked" for that turn, and it retries at the next interval
- @e2e exclude background job, covered by PHPUnit

### Requirement: A goal can be stopped by its owner or the agent owner (REQ-AGGOAL-003)

The system MUST let the goal owner and the agent owner stop an active goal. A stopped goal MUST run no further turns. Any other user MUST get HTTP 404 for the goal.

#### Scenario: The officer stops the goal
- GIVEN an active goal in its session
- WHEN the officer chooses "Stop goal"
- THEN the header shows "Goal stopped" and no further turn runs
