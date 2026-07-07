# Design: agent-exec-tasktype (Hermiq / PHP side)

This is the authoritative design for ADR-032 move 5 from **Hermiq's (enqueuing)
side**. The **worker's (executing) side** of the *same* wire contract lives in
`hermiq-exec/openspec/changes/agent-exec-handler/design.md`. The two documents
describe one contract: the task-type string, every input field name/type, and
every output field name/type MUST be identical in both. Where they differ in
emphasis, they never differ in the wire shape.

## Context

Shipped, do-not-contradict reality this integrates with:

- `lib/Listener/AgentRunRequestedListener.php` consumes OR's
  `AgentRunRequestedEvent` (ADR-041) and enqueues `AgentRunRequestedJob`.
- `lib/Service/FlowAgentRunService.php` applies GATE 1 (kill-switch, via
  `ScheduleService::isOrganisationEngaged()`) and GATE 2 (human approval, via
  `ApprovalService`), THEN calls `ScheduleService::runAgentAsOwner()` and writes
  the output to the triggering object's `resultField`.
- `lib/Service/ScheduleService.php::runAgentAsOwner()` is the single agent-turn
  dispatch seam both scheduled runs and flow-triggered runs pass through. Behind
  `hermiq.engine.enabled` it routes to OR `ChatService` (default) or the in-app
  `Engine` (`lib/Service/Engine/*`, incl. `ContextAssembler`, `ToolLoop`).
- `lib/Service/Llm/ProviderFactory.php` is BOTH a TaskProcessing consumer (the
  `nextcloud` driver) and, per `taskprocessing-provide-text2text`, a provider.
  The sandboxed hand-off mirrors that consumer role in reverse: Hermiq schedules
  a task that an *external* provider (`hermiq-exec`) fulfils.
- `agent-capability-profile` adds `actingUser`, `skillInstalls`, and formalises
  `tools` on the OR `Agent`. This change adds `executionMode` alongside them.

## The central design decision: in-process vs. sandboxed selection rule

**Rule: route to `hermiq:agent-exec` when the resolved `Agent.executionMode` is
`"sandboxed"`; otherwise run in-process (the default, unchanged).**

`executionMode` is a new enum field on the OR `Agent` object:

| value | meaning |
|---|---|
| `inProcess` (default) | Run the turn through `runAgentAsOwner()`'s existing dual path (OR `ChatService` / in-app `Engine`). Byte-for-byte today's behavior. |
| `sandboxed` | Assemble the turn, then enqueue it as a `hermiq:agent-exec` TaskProcessing task for `hermiq-exec` to execute inside its egress jail. |

**Why an explicit field, not inference.** Two alternatives were rejected:

1. *Sniff the tool allowlist for "execution/shell" tools.* Rejected: `Agent.tools`
   holds opaque `{appId}.{toolName}` fleet-registry ids (`ToolLoop`'s contract);
   there is no reliable, stable classifier for "this tool needs a sandbox," and a
   silent heuristic that changes an agent's execution backend when its tool list
   changes is exactly the kind of implicit routing that makes governance
   unauditable.
2. *A global instance flag.* Rejected: the decision is per-agent (one instance
   mixes chat agents and code-execution agents), and a global flag can't be
   opt-in per agent.

An explicit per-agent enum is minimal, auditable (the routing decision is a
recorded object field), and opt-in with a safe default. It sits naturally in the
capability profile next to `actingUser`/`skillInstalls`/`tools`.

**Where the rule is evaluated — one seam, both entry paths.** The selection
happens at the agent-turn dispatch point that BOTH scheduled runs (`ScheduleService`
dispatch) and flow-triggered runs (`FlowAgentRunService::runAgentAndWriteBack()`)
reach, and only AFTER GATE 1 (kill-switch) and GATE 2 (approval) have passed. This
guarantees a sandboxed run gets the *same governance* as an in-process run — the
gates are upstream of the fork, unchanged. The in-process branch is untouched;
`sandboxed` is a new branch that enqueues instead of calling `runAgentAsOwner()`.

**No regression.** `executionMode` defaults to `inProcess`; every existing agent
(which has no such field) resolves to `inProcess`. The sandboxed branch is
unreachable until an operator sets `executionMode: sandboxed` on an agent AND
`hermiq-exec` is installed and enabled.

**Fail-closed.** When an agent is `sandboxed` but no `hermiq:agent-exec` provider
is registered (`hermiq-exec` not installed/enabled), Hermiq MUST NOT silently fall
back to in-process (that would run under weaker isolation than the operator asked
for) and MUST NOT silently drop the run. It records an `agent-run` AuditTrail
entry with `status: "error"` and a clear message, and the run does not execute.

## Execution backend

The sandboxed backend is the **Claude CLI (`claude -p` with OAuth)** running inside
`hermiq-exec`'s jail — the fleet-standard execution mechanism, installed by
`hermiq-exec/Dockerfile`. This is deliberately a *different* backend from the four
in-process LLM providers (`openai`/`ollama`/`fireworks`/`nextcloud`): sandboxed
agents are Claude-CLI-backed regardless of the `hermiq.llm` `chatProvider`. The
`model` payload field selects the Claude model (`sonnet`/`haiku`/`opus`/`fable`).

## Transport

- **Enqueue.** Hermiq schedules the task via `OCP\TaskProcessing\IManager`, task
  type `hermiq:agent-exec`, `appId` `hermiq`, `userId` the acting user (for NC's
  own bookkeeping), and `customId` set to the run's `correlation_id`. Scheduling
  is **asynchronous** (`scheduleTask()`), not the blocking `runTask()`: a
  sandboxed turn can run up to `timeout_seconds` and must not block a cron tick or
  the flow job for that long.
- **Poll + execute.** `hermiq-exec`'s poll worker picks the task up via
  `next_task`, dispatches to its `run_agent_exec` handler, executes in the jail,
  and calls `report_result`.
- **Ingest.** Hermiq ingests the completed task via a TaskProcessing
  completion listener (`OCP\TaskProcessing\Events\TaskSuccessfulEvent` /
  `TaskFailedEvent`), correlating by `customId` == `correlation_id`. Ingestion
  writes the `agent-run` AuditTrail entry (ADR-041) and, for a flow-triggered run,
  the triggering object's `resultField`.

**TaskProcessing constraint (implementation prerequisite, flagged).** A task type
must be *registered by a provider* before `scheduleTask()` will accept a task of
that type. Only `hermiq-exec` registers `hermiq:agent-exec` (on `/enabled`). Hermiq
(PHP) does not and must not register it — it is the consumer. Hence the
fail-closed requirement above: absence of the provider is an audited error, not a
crash and not a silent in-process fallback.

## The wire contract — INPUT (`hermiq:agent-exec` task `input`)

TaskProcessing input/output shapes are limited scalar types (`TEXT`, `NUMBER`,
`ENUM`, …). Structured values are carried as JSON-encoded `TEXT`. Hermiq assembles
this payload (via its `ContextAssembler`) before enqueuing.

| field | type | req | meaning / Hermiq responsibility |
|---|---|---|---|
| `correlation_id` | TEXT | yes | The run/approval/audit correlation id (= `AgentRunRequestedEvent.correlationId`, also the task `customId`). Ties the returned envelope back to the audit entry and the pending flow/approval context. |
| `agent_id` | TEXT | yes | The OR `Agent` UUID. **Attribution only** — for the audit trail. NOT a credential; the worker never authenticates as this agent. |
| `acting_user` | TEXT | yes | The NC user id the run is attributed to (the resolved agent's `actingUser`/`owner`). **Attribution only.** The worker never receives this user's session/credentials and never impersonates them; see Security boundary. |
| `model` | TEXT | yes | Claude model id for the CLI (`--model`), e.g. `sonnet`/`haiku`/`opus`/`fable`. |
| `system_prompt` | TEXT | no | Assembled system prompt / agent persona (`ContextAssembler` output). |
| `prompt` | TEXT | yes | Assembled user instructions + inlined RAG/context (`ContextAssembler` output). This is the fully-prepared turn — the worker does no context retrieval of its own. |
| `skill_set` | TEXT (JSON array) | no | The agent's `skillInstalls`, **materialized as inlined content**: `[{"slug": string, "instructions": string}]`. Skills are inlined, NOT shipped as package references — the jail forbids arbitrary network fetch, so Hermiq resolves each installed Skill object to its instruction text and inlines it. The worker writes these into its scratch workspace for the CLI to pick up. |
| `tool_allowlist` | TEXT (JSON array) | no | The agent's `tools` allowlist as `{appId}.{toolName}` id strings. Maps to the CLI's allowed-tools set. Empty/absent = no tools offered (matches `ToolLoop`'s "empty array → no tools" for the sandboxed case). |
| `context_files` | TEXT (JSON object) | no | Workspace bootstrap: `{ "<filename>": "<content>" }` of read-only reference material to seed the scratch workspace. |
| `max_turns` | NUMBER | no | Upper bound on the CLI tool loop (`--max-turns`). Worker clamps to its own ceiling. |
| `timeout_seconds` | NUMBER | no | Hard wall-clock execution budget. Worker clamps to its own ceiling. |

## The wire contract — OUTPUT (`hermiq:agent-exec` task `output`) + error channel

Two channels, per TaskProcessing semantics:

- **Execution outcomes** (the run reached the worker and produced a verdict —
  including a refusal or a timeout) are returned as the `output` dict below, with
  `report_result(error_message=None)`.
- **Transport-level failures** (the worker could not run the task at all: malformed
  input, CLI missing, jail failure) are returned as
  `report_result(output=None, error_message=<reason>)`, marking the task failed.

Hermiq ingests **both**: a failed task and an `output.status != "success"` both
produce an audited `agent-run` entry.

| field | type | meaning |
|---|---|---|
| `status` | TEXT | One of `success` \| `failure` \| `timeout` \| `refused`. |
| `response_text` | TEXT | The agent's final response text. |
| `artifacts` | TEXT (JSON array) | Optional produced artifacts: `[{"name": string, "content": string}]`. |
| `audit_json` | TEXT (JSON object) | Captured execution metadata for the ADR-041 audit trail: `{ "model": string, "turns": int, "tool_calls": [{"tool": string, "ok": bool}], "exit_code": int, "duration_ms": int }`. |
| `error_detail` | TEXT | Human-readable detail when `status != "success"` (already redacted worker-side; Hermiq redacts again on ingest via `RedactionService`). |

## Security boundary (spec'd as requirements)

1. **Egress jail.** The worker executes inside `hermiq-exec/deploy/`'s jail
   (`--internal` Docker network + iptables sidecar allowlisting only
   `api.anthropic.com`). Nothing in the input payload can widen egress — there is
   no field that carries a URL, host, or network target the worker acts on.
2. **Attribution, not impersonation.** `acting_user` and `agent_id` are audit
   attribution only. The worker receives no NC session, cookie, token, or
   credential for that user, and MUST NOT authenticate to Nextcloud or any fleet
   app as them. The only credential in the jail is the Claude CLI OAuth, mounted
   read-only, scoped to Anthropic.
3. **No secret leakage.** The worker MUST NOT emit the Claude OAuth token or any
   jail environment secret into `response_text`, `audit_json`, `artifacts`, or its
   logs. Hermiq additionally redacts the envelope on ingest (`RedactionService`),
   matching `FlowAgentRunService`'s redaction-before-persist contract.
4. **Gates stay on Hermiq's side.** GATE 1 (kill-switch) and GATE 2 (human
   approval) run in Hermiq BEFORE the task is enqueued — a killed or unapproved run
   never reaches the worker. The worker executes only what Hermiq already cleared.
5. **In-flight kill-switch.** NC TaskProcessing has no push-cancel channel to a
   worker mid-execution (a *known constraint*, flagged). Hermiq bounds an in-flight
   run two ways: (a) `timeout_seconds` caps wall-clock, and (b) on ingest, Hermiq
   re-checks the kill-switch/approval state by `correlation_id` and **drops a late
   result** (no `resultField` write, audit entry `status: "cancelled"`) if the run
   was cancelled or the organisation's kill-switch engaged while it was in flight.

## Integration without regression — summary

- `executionMode` defaults to `inProcess`; existing agents are unaffected.
- The gates and the in-process dispatch (`runAgentAsOwner`) are untouched.
- The sandboxed branch is opt-in, gated the same way, fail-closed when the worker
  is absent, and reuses the existing redacted `agent-run` audit + `resultField`
  write-back paths.

## Open implementation questions (for tasks.md, not blocking the contract)

- Exact placement of the routing branch (inside `ScheduleService` vs. a small
  `SandboxedRunDispatcher` collaborator both entry paths call). Contract is
  identical either way.
- Whether the completion listener writes `resultField` for scheduled runs too, or
  only flow-triggered runs (scheduled runs today capture the return string for
  analytics, not a `resultField`). The audit write is required for both.
