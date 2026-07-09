---
kind: code
depends_on: []
---

# Proposal: agent-exec-tasktype

## Why

ADR-032 "agent-core" move 5 — plugging Hermiq's agent runtime into the
`hermiq-exec` egress-jailed execution worker via a custom TaskProcessing task
type — has been deferred three times, each deferral explicitly naming it as "its
own future change":

- `agent-engine-port/proposal.md`: "Moves 2-5 (… `hermiq-exec` task types) are
  explicitly NOT in this change — each is its own future change once this port
  lands."
- `agent-engine-port/design.md` (Non-Goals): "TaskProcessing moves 2-5 (… ,
  hermiq-exec task types) — plan §8, each its own future change."
- `contextagent-provider/proposal.md` (Non-Goals): "… hermiq-exec custom task
  types (move 5) — separate future change."

This change discharges all three defers by ratifying the **wire contract** of the
`hermiq:agent-exec` TaskProcessing task type between Hermiq (PHP, the enqueuing
side) and hermiq-exec (Python ExApp, the executing side). The worker side is
already scaffolded with a registered-but-stubbed task type
(`hermiq-exec/ex_app/lib/analyze.py::run_agent_exec`, which today returns a
structured "not implemented" error precisely because "that hand-off does not
exist on the Hermiq side yet"). This change defines Hermiq's side of that
hand-off and freezes the contract so the two repos cannot drift.

Sandboxed execution is **not** a replacement for the shipped in-process path. The
in-process path (`ScheduleService::runAgentAsOwner()` → OpenRegister `ChatService`
or the in-app `Engine`, feature-flagged `hermiq.engine.enabled`) serves
pure-LLM-chat agents that answer from context + fleet MCP tools. It cannot serve
agents that need **sandboxed shell/file/code execution under egress control** —
running a build, editing files in a scratch workspace, driving a CLI tool loop —
because that would execute untrusted tool activity inside the Nextcloud PHP
process with no egress jail. That class of agent is exactly what `hermiq-exec`'s
Claude-CLI-in-a-jail worker exists to run. This change is the opt-in routing
seam: an agent flagged for sandboxed execution has its assembled turn shipped to
the worker instead of run in-process, with **identical** kill-switch, human-
approval, acting-user-attribution, and audit governance applied on Hermiq's side
first.

## What Changes

- **New capability profile field `executionMode`** on the OpenRegister `Agent`
  object (`inProcess` | `sandboxed`, default `inProcess`). This is the selection
  rule: `sandboxed` opts an agent into the external worker; the default preserves
  today's in-process behavior byte-for-byte (no regression).
- **A routing seam** at Hermiq's single agent-turn dispatch point (reached by both
  scheduled runs and flow-triggered runs, AFTER the kill-switch and approval
  gates): when the resolved agent's `executionMode` is `sandboxed`, the assembled
  turn is enqueued as a `hermiq:agent-exec` TaskProcessing task instead of being
  run through `runAgentAsOwner()`'s in-process dual path.
- **The frozen input payload + result envelope** (concrete field tables in
  `design.md`) that Hermiq assembles and the worker consumes/returns. The
  identical contract, from the worker's perspective, is specified in the paired
  change `hermiq-exec/openspec/changes/agent-exec-handler/` — same task-type
  string (`hermiq:agent-exec`), same field names and types both directions.
- **Result ingestion** back into Hermiq's audit trail (ADR-041) and, for
  flow-triggered runs, the triggering object's `resultField` — via the same
  redacted `agent-run` AuditTrail write-path `FlowAgentRunService` already uses.
- **The security boundary** stated as explicit requirements: the worker runs
  inside `hermiq-exec/deploy/`'s egress jail; `acting_user`/`agent_id` are audit
  attribution only and never a credential the worker authenticates as; secrets
  never leak into results/logs; the kill-switch and approval gates stay on
  Hermiq's side, before the task is enqueued.

## Cross-repo dependency

**Paired with `hermiq-exec` change `agent-exec-handler`** (cross-repo — openspec
`depends_on` frontmatter only resolves within one repo, so this is recorded in
prose). The two `design.md` files describe the identical wire contract and
cross-reference each other by repo/path. The task-type string, input field
names/types, and output envelope MUST match verbatim between the two changes.

**Runtime prerequisite (flagged, not a surprise):** NC TaskProcessing will not
accept a `hermiq:agent-exec` task unless a provider has registered that task type.
Only `hermiq-exec` registers it (on its `/enabled` lifecycle callback) — Hermiq
(PHP) is a pure consumer/scheduler here, the mirror-in-reverse of how
`ProviderFactory`'s `nextcloud` driver consumes stock TaskProcessing providers.
Therefore Hermiq MUST fail closed (audited error, no silent drop) when an agent is
flagged `sandboxed` but no `hermiq:agent-exec` provider is installed/enabled.

## Non-Goals

- Replacing or deprecating the in-process path. `inProcess` stays the default and
  is unchanged.
- Implementing the worker handler — that is `agent-exec-handler` in the
  `hermiq-exec` repo.
- A per-turn UI to choose execution mode — `executionMode` is a capability-profile
  field on the Agent, set the same way `actingUser`/`skillInstalls`/`tools` are.
- Streaming sandboxed output to an interactive chat surface — sandboxed runs are
  background/non-interactive (scheduled + flow-triggered), like the worker's
  existing `doc-analyze` task.
