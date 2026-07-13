# Test Plan: run-replay-and-dry-run

## Test Cases

### TC-1: A side-effecting tool is neutralised in dry-run
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls` (Scenario: A side-effecting tool is neutralised)
- **type**: functional
- **preconditions**: `hermiq.engine.enabled=true`; an agent with a schedule whose prompt leads it to
  call a tool classified as side-effecting (e.g. Talk delivery)
- **steps**: Trigger "Dry-run" for that schedule
- **expected result**: The tool is not actually invoked (no Talk message sent); the trace shows a
  step with `outcome='would-have-called'` and the tool's arguments; the audit entry is `dryRun: true`
- **test command**: `/test-functional`

### TC-2: A read-only tool still executes for real in dry-run
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls` (Scenario: A read-only tool still executes for real in dry-run)
- **type**: functional
- **preconditions**: An agent with a tool classified as read-only (e.g. `openregister.searchObjects`)
- **steps**: Trigger "Dry-run" so the agent calls the read-only tool
- **expected result**: The tool step outcome is `ok`/`error` (real invocation), never
  `would-have-called`
- **test command**: `/test-functional`

### TC-3: An unclassified tool defaults to neutralised (fail-safe closed)
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls` (Scenario: An unclassified tool defaults to neutralised)
- **type**: functional
- **preconditions**: A tool registry id with no entry in `ToolClassificationService`'s map
- **steps**: Trigger a dry-run whose agent calls that tool
- **expected result**: The tool is neutralised exactly like an explicitly side-effecting one
- **test command**: `/test-functional`

### TC-4: Dry-run token usage counts toward budget but not toward success-rate analytics
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls` (Scenario: A dry-run never appears as a real run)
- **type**: functional
- **preconditions**: An organisation with a configured budget; an agent with prior real runs
- **steps**: Trigger a dry-run, then check the organisation's budget spend and the agent's analytics
- **expected result**: Budget spend increases by the dry-run's token usage; the agent's status/
  success-rate breakdown is unchanged by the dry-run
- **test command**: `/test-functional`

### TC-5: Dry-run leaves no persisted transcript
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-neutralises-side-effecting-tool-calls` (Scenario: A dry-run leaves no persisted transcript)
- **type**: regression
- **preconditions**: A completed dry-run
- **steps**: Inspect the `hermiq` register's `conversation`/`message` objects after the run
- **expected result**: No scratch conversation/messages remain for that dry-run
- **test command**: `/test-regression`

### TC-6: Kill-switch, budget, and approval gates block dry-run/replay identically to a real run
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state`
- **type**: security
- **preconditions**: Three schedules: one in a kill-switch-engaged org, one budget-exhausted, one
  with `requiresApproval=true` and not yet approved
- **steps**: Attempt "Dry-run" on each
- **expected result**: All three are refused with the matching gate reason; no new pending
  `Approval` object is created for the approval case
- **test command**: `/test-security`

### TC-7: Dry-run does not consume the schedule's occurrence state
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-respect-existing-governance-gates-without-mutating-schedule-state` (Scenario: A dry-run does not consume the schedule's occurrence state)
- **type**: regression
- **preconditions**: A `once` schedule with `repeat.completed=0`
- **steps**: Trigger "Dry-run" on it, then re-read the schedule
- **expected result**: `nextRun`, `repeat.completed`, and `enabled` are unchanged
- **test command**: `/test-regression`

### TC-8: Replay uses the run's historically recorded prompt
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome` (Scenario: Replay uses the run's actual historical prompt)
- **type**: functional
- **preconditions**: A completed run; the schedule's `prompt` is edited afterward
- **steps**: Trigger "Replay" for that run
- **expected result**: The replay's trace/summary reflects the ORIGINAL prompt text, not the
  schedule's current one
- **test command**: `/test-functional`

### TC-9: Replay diff surfaces a changed tool sequence
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome` (Scenario: Replay diff shows a changed tool sequence)
- **type**: functional
- **preconditions**: A completed run that called tools `[A, B]`; the agent's prompt/tools changed so
  a replay now calls `[A, C]`
- **steps**: Trigger "Replay" for that run
- **expected result**: The diff reports `toolSequenceMatches: false`, position 0 matching, position 1
  showing `original: B, replay: C, match: false`
- **test command**: `/test-functional`

### TC-10: Replaying a pre-change run is refused cleanly
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-replay-re-executes-a-runs-exact-recorded-prompt-as-a-dry-run-and-diffs-the-outcome` (Scenario: Replaying a run recorded before this change is refused cleanly)
- **type**: regression
- **preconditions**: A run entry with no persisted `prompt` field (pre-change data)
- **steps**: Trigger "Replay" for that run
- **expected result**: A clear "not available for replay" error, no crash
- **test command**: `/test-regression`

### TC-11: Dry-run/replay refused when the in-app engine is off
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-replay-and-dry-run/spec.md#requirement-dry-run-and-replay-require-the-in-app-agent-engine`
- **type**: functional
- **preconditions**: `hermiq.engine.enabled=false` (default)
- **steps**: Attempt "Dry-run" and "Replay"
- **expected result**: Both show a clear message naming the required feature flag; the agent is
  never actually run
- **test command**: `/test-functional`

### TC-12: Dry-run/replay endpoints refuse a non-owner (IDOR)
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **type**: security
- **preconditions**: A schedule owned by user A with a completed run
- **steps**: As user B, call `POST /api/schedules/{scheduleId}/dry-run` and
  `POST /api/schedules/{scheduleId}/runs/{runId}/replay`
- **expected result**: HTTP 404 (not 403) for both, identical to the existing run-history/trace
  anti-probing convention
- **test command**: `/test-security`

### TC-13: `would-have-called` step arguments are redacted
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: A would-have-called step's arguments are redacted)
- **type**: security
- **preconditions**: A dry-run whose neutralised tool call arguments include a secret-shaped value
- **steps**: Trigger the dry-run, then fetch its trace as the owner
- **expected result**: The `would-have-called` step's arguments show the value masked, never in the
  clear
- **test command**: `/test-security`

### TC-14: Run history UI distinguishes dry-run/replay entries and renders the diff
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp` (Scenario: Replay a past run and see the diff)
- **type**: persona
- **persona**: Priya (ZZP Developer / Integrator) — debugs what an agent actually did vs. would do
- **preconditions**: An agent detail view with a completed real run
- **steps**: Click "Dry-run", then click "Replay" on a past real run
- **expected result**: The dry-run entry is visually distinguished in the list; the replay view shows
  a clear original-vs-replay tool-sequence comparison and output-changed indicator
- **test command**: `/test-persona-priya`

### TC-15: Existing "Run now" and run-history behavior are unchanged
- **spec_ref**: `openspec/changes/run-replay-and-dry-run/specs/agent-management-ui/spec.md#requirement-attach-a-schedule-and-run-now-mvp` (Scenario: Run an agent manually)
- **type**: regression
- **preconditions**: An agent detail view with a schedule attached
- **steps**: Click "Run now" (a real run)
- **expected result**: Behavior is byte-for-byte unchanged from before this change — a real run still
  executes all tools for real
- **test command**: `/test-regression`

## Coverage Summary

- `run-replay-and-dry-run` (ADDED) "Dry-run neutralises side-effecting tool calls": TC-1, TC-2, TC-3,
  TC-4, TC-5
- `run-replay-and-dry-run` (ADDED) "Dry-run and replay respect existing governance gates...": TC-6,
  TC-7
- `run-replay-and-dry-run` (ADDED) "Replay re-executes a run's exact recorded prompt...": TC-8, TC-9,
  TC-10
- `run-replay-and-dry-run` (ADDED) "Dry-run and replay require the in-app agent engine": TC-11
- `run-audit-log` (MODIFIED) "Every run and tool call is audited": TC-1, TC-12
- `run-audit-log` (MODIFIED) "Downloadable, redacted run trace": TC-13
- `agent-management-ui` (MODIFIED) "Attach a schedule and run now": TC-1, TC-11, TC-15
- `agent-management-ui` (MODIFIED) "Run history view": TC-14

## Out of Scope

- Full deterministic checkpoint/restore of engine state mid-run (LangGraph's model) — not built in
  this change (proposal Out of Scope), not testable here.
- Exact-argument replay of the ORIGINAL run's real tool calls — arguments were never persisted for
  real (`ok`/`error`) steps by design; TC-9 tests the tool-sequence diff instead, not argument replay.
- Dry-run/replay on the default OpenRegister `ChatService` path — explicitly unsupported; TC-11
  asserts the honest refusal instead of attempting it.
- Interactive-chat dry-run (the chat widget path) — this change targets scheduled-agent runs only.
