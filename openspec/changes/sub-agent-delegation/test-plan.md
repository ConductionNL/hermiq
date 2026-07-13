# Test Plan: sub-agent-delegation

## Test Cases

### TC-1: An agent with an empty allowlist cannot delegate
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-by-default-until-explicitly-allowlisted` (Scenario: An agent with no configured allowlist attempts to delegate)
- **type**: functional
- **preconditions**: An agent with `delegationAllowlist: []` (default), `engine.enabled=true`, running against a target agent it is not allowlisted for
- **steps**: Prompt the agent so its turn calls `hermiq.delegateAgent` targeting any other agent
- **expected result**: The tool returns a `delegation_not_allowed` error; the target agent is never invoked (no new `Conversation`/`AuditTrail` entry for it)
- **test command**: `/test-functional`

### TC-2: Allowlisted delegation succeeds end-to-end
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-refused-by-default-until-explicitly-allowlisted` (Scenario: An agent delegates to a target explicitly named in its allowlist)
- **type**: functional
- **preconditions**: Agent A's `delegationAllowlist` contains agent B's UUID; both in the same organisation, no gates engaged
- **steps**: Prompt agent A so it delegates a task to agent B
- **expected result**: Agent B runs in a fresh conversation, returns a text result, and agent A's turn continues with that result as the tool output
- **test command**: `/test-functional`

### TC-3: Self-delegation and cyclic delegation are refused
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-self-delegation-and-delegation-cycles-are-refused`
- **type**: functional
- **preconditions**: Agent A's own id in its allowlist (self-delegation case); agent A → B → A allowlist chain (cycle case)
- **steps**: Trigger agent A delegating to itself; separately, trigger the A→B→A chain
- **expected result**: Self-delegation refused with `delegation_self`; the cyclic hop refused with `delegation_cycle`, in both cases before any target invocation
- **test command**: `/test-functional`

### TC-4: Delegation depth and fan-out caps are enforced
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-depth-and-fan-out-are-bounded`
- **type**: functional
- **preconditions**: `delegation.maxDepth=2`, `delegation.maxFanOut=3`; an allowlist chain deep/wide enough to exceed each
- **steps**: Drive a delegation chain past depth 2; separately, drive a single turn to attempt a 4th delegate call
- **expected result**: The depth-exceeding call is refused with `delegation_depth_exceeded`; the 4th fan-out call is refused with `delegation_fanout_exceeded`; refused calls do not themselves count toward fan-out
- **test command**: `/test-functional`

### TC-5: Cross-organisation delegation is refused unconditionally
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/multi-tenant-ops/spec.md#requirement-strict-per-tenant-isolation-across-all-object-types` (Scenario: An agent's allowlist names a target in a different organisation)
- **type**: security
- **preconditions**: Agent A (organisation X) has agent B (organisation Y) in its `delegationAllowlist`
- **steps**: Trigger agent A delegating to agent B
- **expected result**: Refused with `delegation_cross_organisation`; agent B is never invoked, regardless of the allowlist entry
- **test command**: `/test-security`

### TC-6: Attribution is not laundered through delegation
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegated-runs-inherit-the-parents-acting-user-attribution`
- **type**: security
- **preconditions**: Agent A running as impersonated user U; target agent B has `actingUser` set to a different user V
- **steps**: Trigger agent A delegating a task to agent B
- **expected result**: Agent B's sub-run executes as user U, not V; the sub-run's `AuditTrail` entry records `runAsUser: U`
- **test command**: `/test-security`

### TC-7: A delegated sub-agent cannot see the parent's conversation history
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-a-delegated-sub-agent-runs-in-an-isolated-conversation`
- **type**: functional
- **preconditions**: A parent conversation with several unrelated prior turns
- **steps**: Delegate a task to a sub-agent and inspect the sub-agent's own conversation/message history
- **expected result**: The sub-agent's conversation contains only the delegated task, not the parent's prior turns; the parent receives only the sub-agent's final text result
- **test command**: `/test-functional`

### TC-8: Kill-switch, budget hard-cap, and approval-required targets all refuse delegation
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/human-approval-gate/spec.md#requirement-org-level-kill-switch-halts-all-runs` (Scenario: An already-running agent attempts to delegate while the kill-switch is engaged); `openspec/changes/sub-agent-delegation/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: A delegation is refused when the triggering budget is already exhausted); `openspec/changes/sub-agent-delegation/specs/human-approval-gate/spec.md#requirement-approval-object-state-machine-enforced-before-execution` (Scenario: A delegation targets an approval-gated agent)
- **type**: functional
- **preconditions**: Three separate setups — (a) organisation kill-switch engaged, (b) organisation/agent budget at hard cap, (c) target agent has `requiresApproval: true`
- **steps**: In each setup, trigger a delegation attempt from an already-running, otherwise-allowed agent
- **expected result**: (a) refused `delegation_killswitch`; (b) refused `delegation_budget_exhausted`; (c) refused `delegation_requires_approval` with no pending `Approval` created; in all three the already-running parent turn completes uninterrupted
- **test command**: `/test-functional`

### TC-9: A delegated sub-run's usage counts against the parent's triggering budget
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails--soft-threshold-and-hard-cap` (Scenario: A delegated sub-agent run counts against the parent's triggering budget)
- **type**: regression
- **preconditions**: A scheduled run for agent A (organisation X) with an organisation-scoped budget close to its hard cap; agent A delegates to agent B
- **steps**: Let agent A's scheduled run delegate to agent B, consuming enough tokens to cross the cap; then trigger a fresh top-level run for organisation X
- **expected result**: Organisation X's budget status reflects agent B's sub-run usage; the fresh top-level run is blocked by the now-exceeded hard cap
- **test command**: `/test-regression`

### TC-10: Delegation is traceable as one auditable tree
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-delegation-is-traceable-as-one-auditable-tree`
- **type**: functional
- **preconditions**: A successful delegated run, and separately a refused delegation attempt
- **steps**: Inspect the parent run's step timeline and the sub-run's `AuditTrail` entry for the successful case; inspect the parent run's step timeline for the refused case
- **expected result**: The successful sub-run's `AuditTrail` entry carries a fresh `runId` and a `parentRunId` referencing the calling run; the parent's step timeline includes a timed step for the delegate call (both cases), with an error outcome for the refused case and no sub-run `AuditTrail` entry created for the refused target
- **test command**: `/test-functional`

### TC-11: Delegation allowlist UI — configure and exclude self
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/agent-management-ui/spec.md#requirement-agent-detail-manages-the-delegation-allowlist-in-place-mvp`
- **type**: functional
- **persona**: Mark Visser (MKB Software Vendor) — configures which agents may call which
- **preconditions**: An organisation with 3 agents (A, B, C)
- **steps**: Open agent A's edit form, select agent B in the delegation-allowlist field, save; reopen the form
- **expected result**: Agent A itself is never offered as a selectable option; after save, the field shows B selected and C excluded until explicitly added
- **test command**: `/test-persona-mark`

### TC-12: Delegation is unreachable when the in-app Engine is off
- **spec_ref**: `openspec/changes/sub-agent-delegation/specs/sub-agent-delegation/spec.md#requirement-a-delegated-sub-agent-runs-in-an-isolated-conversation`
- **type**: regression
- **preconditions**: `hermiq.engine.enabled=false` (default, legacy OpenRegister ChatService path)
- **steps**: Configure a delegation allowlist and attempt to trigger delegation via a prompt on the legacy path
- **expected result**: No behavior change from today — LLM tool-calling on the legacy path remains blocked upstream (OR#269), so delegation is simply unreachable, never a partial/broken execution
- **test command**: `/test-regression`

## Coverage Summary

- Requirement (sub-agent-delegation) "Delegation is refused by default until explicitly allowlisted": covered by TC-1, TC-2
- Requirement (sub-agent-delegation) "Self-delegation and delegation cycles are refused": covered by TC-3
- Requirement (sub-agent-delegation) "Delegation depth and fan-out are bounded": covered by TC-4
- Requirement (sub-agent-delegation) "Delegation is scoped to the same organisation": covered by TC-5
- Requirement (sub-agent-delegation) "Delegated runs inherit the parent's acting-user attribution": covered by TC-6
- Requirement (sub-agent-delegation) "A delegated sub-agent runs in an isolated conversation": covered by TC-7, TC-12
- Requirement (sub-agent-delegation) "Delegation is refused when gated by kill-switch, budget, or a target requiring approval": covered by TC-8
- Requirement (sub-agent-delegation) "Delegation is traceable as one auditable tree": covered by TC-10
- Requirement (multi-tenant-ops, MODIFIED) budget guardrails + delegation rollup: covered by TC-8, TC-9
- Requirement (multi-tenant-ops, MODIFIED) tenant isolation + cross-org delegation: covered by TC-5
- Requirement (human-approval-gate, MODIFIED) kill-switch + approval-gate for delegation: covered by TC-8
- Requirement (agent-management-ui, ADDED) delegation allowlist UI: covered by TC-11

## Out of Scope
- Parallel/concurrent sub-agent execution — not implemented, so not tested.
- A delegation-tree visualisation UI — only the underlying `runId`/`parentRunId` correlation fields
  are tested (TC-10); no rendering of the tree exists yet.
- Per-organisation depth/fan-out overrides — v1 ships instance-wide `IAppConfig` only.
