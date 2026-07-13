# agent-management-ui (delta)

Adds a "Dry-run" action beside the existing "Run now" button, and a "Replay" action per run row in
the existing Run history section, both rendering their result with the same step-timeline markup
`run-trace-observability` already built, plus a diff view for replay.

## MODIFIED Requirements

### Requirement: Attach a schedule and run now [MVP]
From an agent's detail view the user MUST be able to add/edit a schedule (see `agent-schedule`),
trigger an immediate real run, and trigger a dry-run preview that makes no real side effects.

<!-- Previous behavior: this requirement covered only the real "Run now" action. run-replay-and-dry-run
adds a second, clearly distinguished "Dry-run" action beside it, sharing the same schedule/agent
binding and the same disabled/loading affordances. -->

#### Scenario: Run an agent manually
- GIVEN an agent detail view
- WHEN the user clicks "Run now"
- THEN the system MUST start a run under the user's identity and show its result and audit entry

#### Scenario: Preview an agent run without side effects
- GIVEN an agent detail view with a schedule attached
- WHEN the user clicks "Dry-run"
- THEN the system MUST run the agent's real prompt/model/tools with side-effecting tool calls
  neutralised, and show the resulting step timeline clearly labelled as a dry-run
- AND no real side effect (message sent, object written, notification delivered) MUST occur

#### Scenario: Dry-run is unavailable without the in-app engine
- GIVEN an agent whose instance has `hermiq.engine.enabled` off (the default)
- WHEN the user clicks "Dry-run"
- THEN the system MUST show a clear, actionable message explaining the feature flag is required,
  rather than silently running the agent for real

### Requirement: Run history view [MVP]
Each agent's detail view MUST show its run history (see `run-audit-log`) with status, timing, and
output/audit links, MUST let the user expand any run to see its step timeline and download that run's
trace as a redacted JSON file, and MUST let the user replay any past run as a dry-run and see a
step-by-step diff against the original.

<!-- Previous behavior: this requirement covered viewing and downloading a run's trace.
run-replay-and-dry-run adds a "Replay" action per run and a diff render against the original run,
built on the same trace data already fetched for the Details expand. -->

#### Scenario: View an agent's run history
- GIVEN an agent detail view for an agent whose schedule has run
- WHEN the user views the Run history section
- THEN the system MUST list past runs with their status, timing, and output/audit links
- AND an agent with no runs MUST show an empty-state hint instead of an error
- AND a dry-run/replay entry MUST be visually distinguished from a real run in the list

#### Scenario: View a run's step timeline
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user expands that run
- THEN the system MUST render its ordered step timeline (each step's type, name, duration, and
  outcome) fetched from the run-trace endpoint
- AND a run whose execution path did not record tool-call detail MUST show that plainly rather than
  appearing to have no tool activity
- AND a `would-have-called` step MUST show its (redacted) arguments alongside its name

#### Scenario: Download a run's trace as JSON
- GIVEN an agent detail view showing a completed run in the Run history section
- WHEN the user chooses "Download trace (JSON)" for that run
- THEN the system MUST retrieve the run's full trace via the owner-scoped endpoint and save it as a
  local JSON file
- AND the downloaded content MUST be the same already-redacted data shown in the expanded timeline

#### Scenario: Replay a past run and see the diff
- GIVEN a completed run in the Run history section
- WHEN the user chooses "Replay" for that run
- THEN the system MUST re-execute that run's exact recorded prompt as a dry-run
- AND MUST show, per tool-call position, whether the replay's tool matches the original, and whether
  the final output text changed

#### Scenario: Replay is refused for a gated or blocked schedule
- GIVEN a schedule whose kill-switch is engaged, budget is exhausted, or that requires approval
- WHEN the user chooses "Replay" for one of its past runs
- THEN the system MUST show the same gate-refusal message a blocked "Dry-run"/"Run now" attempt would
  show, rather than silently failing
