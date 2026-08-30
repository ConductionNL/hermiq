# Test Plan: run-trace-observability

## Test Cases

### TC-1: Engine-path run records a full step timeline including tool calls
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp` (Scenario: A scheduled run is fully audited)
- **type**: functional
- **preconditions**: `engine.enabled=true`; an agent with a whitelisted tool; a due schedule for it
- **steps**: Trigger the schedule (Run now or a dispatch tick) so the agent calls the tool once
- **expected result**: The run's `AuditTrail` entry has one `changed.steps` entry per context/history/
  llm/tool/delivery phase, the tool step carries the tool name/duration/outcome, and
  `toolStepsAvailable=true`
- **test command**: `/test-functional`

### TC-2: Default OpenRegister path records coarse steps only, honestly labelled
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp` (Scenario: Tool-call step detail depends on the execution path)
- **type**: functional
- **preconditions**: `engine.enabled=false` (default); an agent with a tool; a due schedule
- **steps**: Trigger the schedule so the agent calls a tool once
- **expected result**: The run's entry has context/history/llm/delivery steps but no `tool`-type
  step, and `toolStepsAvailable=false`
- **test command**: `/test-functional`

### TC-3: Trace endpoint returns the full timeline to the owner
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: An owner retrieves a run's step timeline)
- **type**: api
- **preconditions**: A completed run exists for a schedule owned by user A
- **steps**: As user A, call `GET /api/schedules/{scheduleId}/runs/{runId}/trace`
- **expected result**: 200 with the ordered step timeline matching the persisted `changed.steps`
- **test command**: `/test-api`

### TC-4: Trace endpoint refuses a non-owner (IDOR)
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: A non-owner cannot retrieve another owner's run trace)
- **type**: security
- **preconditions**: A completed run exists for a schedule owned by user A; user B is a different
  authenticated user
- **steps**: As user B, call `GET /api/schedules/{scheduleId}/runs/{runId}/trace` for user A's schedule
- **expected result**: HTTP 404 (not 403), zero step data returned
- **test command**: `/test-security`

### TC-5: Redaction is preserved end-to-end in the trace
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: An owner retrieves a run's step timeline)
- **type**: security
- **preconditions**: A run whose output/tool result contained an API-key-shaped token
- **steps**: Trigger the run, then fetch its trace as the owner
- **expected result**: The trace response's `summary` (and any step detail text) shows the token
  masked, never the raw secret — matching `run-audit-log`'s existing redaction guarantee
- **test command**: `/test-security`

### TC-6: Gated run's trace includes a reconstructed approval-wait step
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: A gated run's trace shows the approval wait)
- **type**: functional
- **preconditions**: A schedule with `requiresApproval=true` is due, gets gated for at least one
  tick (`awaiting_approval`), then is approved and runs
- **steps**: Let the schedule gate for one or more ticks, approve it, wait for it to run, then fetch
  the run's trace
- **expected result**: The trace's first step is `type=gate_wait`, spanning from the first
  `awaiting_approval` entry's timestamp to the run's actual start
- **test command**: `/test-functional`

### TC-7: Ungated run's trace has no fabricated gate-wait step
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-downloadable-redacted-run-trace-mvp` (Scenario: A gated run's trace shows the approval wait)
- **type**: regression
- **preconditions**: A schedule with `requiresApproval=false` runs normally on its due tick
- **steps**: Trigger the run, fetch its trace
- **expected result**: No `gate_wait` step appears in the timeline
- **test command**: `/test-regression`

### TC-8: Delivery failure is recorded as a step but never fails the run
- **spec_ref**: `openspec/changes/run-trace-observability/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp`
- **type**: regression
- **preconditions**: A schedule configured to deliver via a channel that will fail (e.g. Talk
  misconfigured, per `talk-delivery`)
- **steps**: Trigger the run
- **expected result**: The run still completes and is audited as `ok`; the trace's `delivery` step
  shows `outcome=error`; `lastDeliveryError` is still set exactly as before this change
- **test command**: `/test-regression`

### TC-9: Run history UI renders the step timeline and honest availability labelling
- **spec_ref**: `openspec/changes/run-trace-observability/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp` (Scenario: View a run's step timeline)
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — inspects what an agent actually did
- **preconditions**: An agent detail view with at least one completed run (one via the engine path,
  one via the default path)
- **steps**: Expand each run in the Run history section
- **expected result**: The engine-path run shows a full timeline including tool steps; the
  default-path run shows coarse steps and a plain "tool-level detail unavailable" indicator, never
  implying zero tool activity
- **test command**: `/test-persona-priya`

### TC-10: Download trace produces the exact rendered JSON
- **spec_ref**: `openspec/changes/run-trace-observability/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp` (Scenario: Download a run's trace as JSON)
- **type**: functional
- **preconditions**: An agent detail view with an expanded run's timeline visible
- **steps**: Click "Download trace (JSON)"
- **expected result**: A `.json` file is saved whose contents match the trace endpoint's response
  for that run, unmodified beyond formatting
- **test command**: `/test-functional`

### TC-11: Run history empty-state and existing list behavior are unchanged
- **spec_ref**: `openspec/changes/run-trace-observability/specs/agent-management-ui/spec.md#requirement-run-history-view-mvp` (Scenario: View an agent's run history)
- **type**: regression
- **preconditions**: An agent with no runs yet
- **steps**: Open its detail view
- **expected result**: The existing empty-state hint still renders exactly as before this change
  (no trace/timeline elements appear when there is nothing to expand)
- **test command**: `/test-regression`

## Coverage Summary

- `run-audit-log` (MODIFIED) "Every run and tool call is audited": covered by TC-1, TC-2, TC-8
- `run-audit-log` (ADDED) "Downloadable, redacted run trace": covered by TC-3, TC-4, TC-5, TC-6, TC-7
- `agent-management-ui` (MODIFIED) "Run history view": covered by TC-9, TC-10, TC-11

## Out of Scope

- Per-tool-call granularity on the default OpenRegister `ChatService` path — not testable here since
  it is explicitly not implemented (proposal Out of Scope); TC-2 asserts the honest absence instead.
- Flow/webhook-triggered run traces (`FlowAgentRunService`) — pre-existing gap, not widened or
  narrowed by this change, not tested here.
- Replay/checkpointing and drift detection — roadmap items, not built in this change.
