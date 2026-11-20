# Design: contextagent-provider

## Context

`core:contextagent:interaction` (NC 31+, verified on the 33.0.0-dev checkout at
`lib/public/TaskProcessing/TaskTypes/ContextAgentInteraction.php`) is a confirmation-
gated agent loop. Its shape:

| Direction | Slot | Type | Meaning |
|---|---|---|---|
| in | `input` | Text | the chat message |
| in | `confirmation` | Number | confirm (1) / deny (0) previously-requested actions |
| in | `conversation_token` | Text | a token representing the conversation |
| out | `output` | Text | the agent's reply |
| out | `conversation_token` | Text | the new token to send with the next interaction |
| out | `actions` | Text | actions the agent would like to carry out, as JSON |

`ITriggerableProvider` is ABSENT in NC 33 — providers are pull/sync, so
`ISynchronousProvider::process()` (a single blocking call) is the correct and only
shape. Streaming is not available; that is fine — Assistant's agent chat is
turn-based.

## Mapping to Hermiq governance

| ContextAgent concept | Hermiq primitive |
|---|---|
| `conversation_token` | a `Conversation` OR object UUID in the `hermiq` register (create on first turn, reuse thereafter, ownership-checked against the task user) |
| `confirmation` 0/1 | an approval-gate decision — deny/approve — on the user's pending `Approval` for the serving agent (via `ApprovalService`) |
| `actions` JSON | the serving agent's `tools` allowlist (`Agent.tools`) — the governance disclosure of what the agent may do |
| (implicit) | the org **kill-switch** (`ScheduleService::isOrganisationEngaged`) halts the interaction before the agent runs |

The serving agent is resolved from the `contextagent_agent` app-config UUID, falling
back to the first active agent in the register. This is deliberately an admin choice —
which governed agent stands behind Assistant's agent chat.

## Decisions

**Register as an ALTERNATIVE, not a replacement.** NC's stock `context_agent` ExApp
provides this task type too. Both can be installed; the admin selects the preferred
provider per task type in the Assistant admin settings. Hermiq does not attempt to
disable or supersede the stock provider — the plan's §8 move 3 "our differentiator is
governance" coexistence is honoured verbatim.

**Use the `Engine` directly, not `ScheduleService::runAgentAsOwner`.** A ContextAgent
interaction needs a persisted, resumable conversation (the `conversation_token`
contract), whereas `runAgentAsOwner` creates a throwaway conversation per call. The
`Engine` service operates on `hermiq`-register `Conversation`/`Message` objects
regardless of the `hermiq.engine.enabled` flag (that flag only re-points
`ScheduleService`), so a net-new surface using it directly is self-contained and
correct.

**Single blocking turn; Hermiq's tool loop runs inline.** Hermiq's engine executes
its own allowlist-gated tool loop within `processMessage()`. So by the time a turn
returns, permitted tools have already run under the per-agent allowlist. The `actions`
output therefore discloses the allowlist (what the agent MAY do) rather than a queue
of not-yet-executed proposals.

## DEFERRED: the multi-turn action-confirmation loop

This change ships a **single-turn** path. The full stateful loop is deferred and NOT
faked:

- **What is deferred:** a turn that, instead of executing tools inline, PROPOSES a set
  of actions, creates a `sourceType: "contextagent"` pending `Approval` carrying the
  paused tool-execution context, emits those proposed actions in `actions`, and
  RETURNS without executing them; the NEXT interaction's `confirmation=1` then
  resolves that specific Approval and RESUMES the exact paused tool execution
  (`confirmation=0` denies and discards it). This requires (a) an engine mode that can
  pause before tool execution and serialise the resume context, and (b) a new
  `ApprovalService::approve()` branch for `sourceType: "contextagent"` that resumes
  the paused turn rather than a schedule/flow run.
- **What ships now:** the provider registration, the full interaction shape, the
  conversation binding, the kill-switch gate, the actions (allowlist) disclosure, and
  a `confirmation`→approve/deny mapping that resolves the user's PRE-EXISTING pending
  Approval for the agent (e.g. a gated scheduled run the user confirms from the chat).
  In the common case (no pending approval, no proposed actions) `confirmation` is a
  recorded no-op — honest, not a stubbed pending queue.

## Risks / Trade-offs

- **`actions` semantics differ from stock `context_agent`.** The stock ExApp emits a
  live proposed-action queue; Hermiq (single-turn) emits the allowlist. Documented in
  the provider docblock; the deferred loop closes the gap.
- **Kill-switch is enforced; approval-gate is partially wired.** The kill-switch is a
  hard gate now. The approval-gate mapping is real but only reaches pre-existing
  approvals until the deferred pause/resume lands — an intentional, documented
  boundary, not a silent omission.
