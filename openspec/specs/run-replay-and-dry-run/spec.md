# run-replay-and-dry-run Specification

## Purpose
TBD - created by archiving change run-replay-and-dry-run. Update Purpose after archive.
## Requirements
### Requirement: Dry-run neutralises side-effecting tool calls
The system MUST let an owner run a schedule's agent as a dry-run: the SAME prompt, model, and context
a real run would use, but with every side-effecting tool call intercepted before it executes and
recorded as a `would-have-called` step instead — read-only tool calls MUST still execute for real so
the preview reflects accurate data. A tool with no explicit classification MUST default to
side-effecting (fail-safe closed).

#### Scenario: A side-effecting tool is neutralised
- GIVEN an agent whose prompt leads it to call a tool classified as side-effecting (e.g. sending a
  Talk message)
- WHEN the owner triggers a dry-run of that agent's schedule
- THEN the system MUST NOT actually invoke that tool
- AND the run's step timeline MUST include a step with `outcome='would-have-called'` naming that tool
  and its (redacted) arguments
- AND the run's persisted audit entry MUST be marked `dryRun: true`

#### Scenario: A read-only tool still executes for real in dry-run
- GIVEN an agent whose prompt leads it to call a tool classified as read-only (e.g. searching
  objects)
- WHEN the owner triggers a dry-run
- THEN the system MUST actually invoke that tool and record its real result
- AND the tool's step outcome MUST be `ok`/`error` exactly as on a real run, never `would-have-called`

#### Scenario: An unclassified tool defaults to neutralised
- GIVEN a tool registry id that has no entry in Hermiq's tool classification map
- WHEN a dry-run's agent calls that tool
- THEN the system MUST treat it as side-effecting and neutralise it, never invoke it for real

#### Scenario: A dry-run never appears as a real run
- GIVEN a completed dry-run
- WHEN the owner views run history
- THEN the run MUST be clearly marked as a dry-run and MUST NOT be counted in the agent's
  status/success-rate breakdown
- AND the run's token usage MUST still count toward the organisation's budget spend, because a real
  LLM call was made

#### Scenario: A dry-run leaves no persisted transcript
- GIVEN a completed dry-run
- WHEN the run finishes (success or failure)
- THEN the scratch conversation and messages created to drive the LLM call MUST be deleted
- AND the only durable artifact of the dry-run MUST be its `dryRun: true` audit entry

### Requirement: Dry-run and replay respect existing governance gates without mutating schedule state
Dry-run and replay MUST be blocked by the same kill-switch, budget hard-cap, and approval-required
gates a real run is blocked by, evaluated in the same order, but MUST NOT advance the schedule's
`nextRun`/`repeat`/`enabled` state or create a new pending Approval — a preview must be repeatable
without affecting the schedule's real cadence or approval queue.

#### Scenario: A kill-switch-halted organisation cannot be dry-run
- GIVEN an organisation whose kill-switch is engaged
- WHEN a user triggers a dry-run or replay for a schedule in that organisation
- THEN the system MUST refuse to run and MUST report the halt, identical to a real run's behavior

#### Scenario: A budget-exhausted schedule cannot be dry-run
- GIVEN a schedule whose organisation/agent budget has reached its hard cap
- WHEN a user triggers a dry-run or replay
- THEN the system MUST refuse to run, identical to a real run's behavior

#### Scenario: An approval-gated schedule cannot be dry-run without approval
- GIVEN a schedule with `requiresApproval=true`
- WHEN a user triggers a dry-run or replay for it (not yet approved)
- THEN the system MUST refuse to run and report that approval is required
- AND MUST NOT create a new pending Approval object as a side effect of the attempt

#### Scenario: A dry-run does not consume the schedule's occurrence state
- GIVEN a `once` schedule with `repeat.completed=0`
- WHEN the owner triggers a dry-run of it
- THEN the schedule's `nextRun`, `repeat.completed`, and `enabled` fields MUST be unchanged afterward

### Requirement: Replay re-executes a run's exact recorded prompt as a dry-run and diffs the outcome
Given a past run, the system MUST be able to re-invoke the SAME agent with that run's exact recorded
prompt text (not today's possibly-edited `Schedule.prompt`) as a dry-run, and MUST return a
step-by-step comparison against the original run's recorded step sequence and final output.

#### Scenario: Replay uses the run's actual historical prompt
- GIVEN a completed run whose recorded prompt differs from the schedule's current `prompt` field
  (the schedule was edited afterward)
- WHEN the owner replays that run
- THEN the system MUST use the run's originally recorded prompt text, not the schedule's current one

#### Scenario: Replay diff shows a changed tool sequence
- GIVEN a completed run that called two tools in a specific order
- WHEN the owner replays it and the agent now calls a different set or order of tools
- THEN the diff MUST show `toolSequenceMatches: false` and list, per position, the original tool name,
  the replay's tool name, and whether they match

#### Scenario: Replay diff shows an unchanged tool sequence
- GIVEN a completed run that called one tool
- WHEN the owner replays it and the agent calls the SAME tool at the same position
- THEN the diff MUST show `toolSequenceMatches: true` for that position

#### Scenario: Replay is always a dry-run
- GIVEN any completed run, gated or not
- WHEN the owner replays it
- THEN the replay execution MUST follow the identical dry-run neutralisation rules as a direct
  dry-run — no side-effecting tool from the replay is ever actually invoked

#### Scenario: Replaying a run recorded before this change is refused cleanly
- GIVEN a run's audit entry that predates this change (no prompt persisted in its context)
- WHEN the owner attempts to replay it
- THEN the system MUST refuse with a clear "not available for replay" error, never a crash or a
  silently empty prompt

### Requirement: Dry-run and replay require the in-app agent engine
Dry-run and replay MUST only be available when `hermiq.engine.enabled` is on, because tool-call
interception depends entirely on the in-app `Engine`/`FacadeToolInvoker` path.

#### Scenario: Dry-run is refused on the default execution path
- GIVEN `hermiq.engine.enabled` is off (the default)
- WHEN a user attempts a dry-run or replay
- THEN the system MUST refuse with a clear, actionable error naming the required feature flag, and
  MUST NOT silently run the agent for real

