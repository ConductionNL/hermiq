# run-audit-log (delta)

Extends the existing per-tool-call trace step (name/timing/outcome only, deliberately
argument-free to avoid a secret-leak surface) with an OPTIONAL redacted target field,
populated only for `hermiq.webSearch`/`hermiq.webFetch` — because for an outbound web
tool, "which external host did this agent reach" is itself the compliance-relevant fact,
not an incidental argument. No other tool's step shape changes.

## MODIFIED Requirements

### Requirement: Every run and tool call is audited [MVP]
The system MUST write an `AuditTrail` entry for each agent run (start, completion, error),
scoped to the run owner's `organisation`, and MUST record each tool invocation made
during that run as a timed step within that same entry's step timeline — never as a
separate `AuditTrail` entry per tool call. For `hermiq.webSearch`/`hermiq.webFetch`
specifically, that step MUST additionally carry the call's target (the search query or
fetch URL, reduced to host+path with any query string dropped entirely) — every other
tool's step continues to carry only name/timing/outcome, unchanged.

<!-- Previous behavior: every tool-call step carried only name/timing/outcome, by design,
to avoid reintroducing a secret-leak surface into the audit trail. web-research-tool adds
an optional target field populated only for the two web-research tool ids, because for an
outbound web call the destination itself (not just the fact that a call happened) is the
fact a compliance reviewer needs — and a host+path (with the query string dropped
entirely, not selectively masked) carries materially lower leak risk than the raw
arguments/results the original design was protecting against. -->

#### Scenario: A scheduled run is fully audited
- GIVEN a scheduled agent run that calls two tools and completes
- WHEN the run finishes
- THEN the system MUST have written exactly one `AuditTrail` entry for the run, carrying
  `action`, `user`, `organisation`, timestamp, and a hash chained to the previous entry
- AND that entry's step timeline MUST include one step per tool call, each carrying the
  tool name, start/end timestamps, duration, and outcome (`ok`/`error`)

#### Scenario: A web-research tool call's trace step shows its target
- GIVEN a scheduled agent run that calls `hermiq.webFetch` with a URL
- WHEN the run completes and its trace is retrieved by the owner
- THEN the step for that call MUST show the fetched URL reduced to host+path (no query
  string), alongside its existing name/timing/outcome fields

#### Scenario: A web-research tool call's target never carries a query string
- GIVEN a scheduled agent run that calls `hermiq.webFetch` with a URL that includes a
  query string (e.g. containing a session token or search parameter)
- WHEN the step is recorded
- THEN the persisted target MUST NOT include any part of the query string, regardless of
  whether it looks sensitive — the query string is dropped entirely, not selectively
  redacted

#### Scenario: Tool-call step detail depends on the execution path
- GIVEN a scheduled agent run executed via the default OpenRegister `ChatService` path
  (in-app Engine feature flag off)
- WHEN the run finishes
- THEN the system MUST still record context-retrieval, history-build, and
  LLM-generation steps in the run's step timeline
- AND the system MAY omit individual tool-call steps for that run, because Hermiq does
  not instrument OpenRegister's internal tool loop
- AND the persisted entry MUST indicate whether tool-call steps are available for that
  run, so a reader can distinguish "no tools were called" from "tool-level detail
  unavailable on this path"
