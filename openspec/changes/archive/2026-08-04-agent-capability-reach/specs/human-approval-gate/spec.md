# human-approval-gate (delta)

The gate stops firing on the data verb alone. It fires when an un-granted invocation is
write/destructive **or** when its `reach` is `instance` or higher — a union, so nothing that is
gated today stops being gated. A grant may carry a `#noapproval` waiver that suppresses the gate for
that one already-narrowed grant, and nothing else.

## MODIFIED Requirements

### Requirement: Un-granted destructive tool invocation routes through the approval gate

The system MUST route an agent's attempted invocation of a tool that is NOT covered by an explicit per-agent grant (see `agent-tool-governance`) through this same human-approval gate whenever that tool is classified write/destructive OR its resolved `reach` is `instance` or higher: it MUST create a pending `Approval` object (`sourceType: "tool"`, idempotent on the `agentId` + `toolId` pair) and MUST NOT execute the invocation until that `Approval` reaches `approved`. The two triggers MUST compose as a union, so a tool gated today by its write/destructive classification MUST remain gated whatever its reach. An invocation the agent HAS been explicitly granted proceeds without a new approval (subject to OpenRegister RBAC at invoke time).

The system MUST pass the catalog descriptor to the classification the gate uses, so that a declared `scope`/`destructiveHint`/`readOnlyHint` hint and a declared `reach` are both visible on the gating path and the gate cannot reach a different verdict from default-deny for the same id and descriptor.

The refusal the system returns to the model MUST name the trigger that produced it, including the resolved `reach` when reach is what fired the gate, so a run trace distinguishes a reach-triggered gate from a verb-triggered one rather than reporting an undifferentiated denial.

The system MUST suppress the gate for an invocation whose matching grant entry carries the `#noapproval` waiver, and MUST evaluate that waiver only AFTER the tool has been placed in the agent's resolved set and AFTER argument-constraint enforcement has accepted the invocation's arguments. The waiver MUST NOT add a tool to the resolved set, relax an argument constraint, or affect OpenRegister RBAC.

Unlike the `schedule` and `flow` source types, a `tool` approval resumes NOTHING on decision — the chat turn that attempted the call has already returned. Flipping the status IS the effect: the next invocation attempt of that `(agentId, toolId)` pair finds the decided `Approval` and proceeds, or is blocked permanently by a `denied` one.

<!-- Previous behavior: the gate fired only on the write/destructive classification, evaluated from the
     tool id alone without its descriptor, and there was no way to suppress it for a single grant. -->

#### Scenario: An agent attempts an un-granted destructive tool call

- GIVEN an agent whose grants do not explicitly include a destructive tool
- WHEN the agent's run attempts to invoke that tool
- THEN the system MUST create an `Approval` object in `pending` state for the responsible reviewer
- AND the system MUST NOT invoke the tool until the `Approval` reaches `approved`
- AND a denied `Approval` MUST prevent the invocation from ever executing
@e2e exclude Requires driving a model turn to attempt an un-granted tool call, which no Hermiq UI produces; asserted by unit test on the invoker and by the existing approvals surface coverage.

#### Scenario: An explicitly-granted destructive tool call is not re-gated

- GIVEN an agent whose grants explicitly include a destructive tool (by exact id or write modifier)
- WHEN the agent's run invokes that tool
- THEN the system MUST NOT require a new `Approval` solely because the tool is destructive
- AND OpenRegister RBAC MUST still authorize the invocation at invoke time
@e2e exclude Requires driving a model turn; asserted by unit test on the gate predicate.

#### Scenario: An un-granted external-reach read tool is gated

- **GIVEN** an agent whose grants do not explicitly include a `read`-scoped tool whose resolved
  `reach` is `external`
- **WHEN** the agent's run attempts to invoke that tool
- **THEN** the system MUST create a pending `Approval` object rather than dispatching
- **AND** the refusal returned to the model MUST name `external` as the reach that fired the gate
@e2e exclude Requires driving a model turn against an egress tool; asserted by unit test on the gate predicate and its refusal envelope.

#### Scenario: A low reach does not un-gate a destructive tool

- **GIVEN** an agent whose grants do not include a `delete`-scoped tool whose resolved `reach` is
  `self`
- **WHEN** the agent's run attempts to invoke that tool
- **THEN** the system MUST still create a pending `Approval` object rather than dispatching
@e2e exclude Non-regression assertion on the gate predicate; asserted by unit test comparing pre- and post-change verdicts.

#### Scenario: The gate honours a declared hint that contradicts the verb suffix

- **GIVEN** a 3-segment derived id whose verb reads `get` but whose descriptor declares
  `destructiveHint: true`, not covered by any explicit grant
- **WHEN** the agent's run attempts to invoke it
- **THEN** the system MUST gate the invocation
- **AND** the gate's verdict MUST match the verdict default-deny reaches for the same id and
  descriptor
@e2e exclude Closes a descriptor-not-threaded bypass on an internal call path; asserted by unit test asserting both classification call sites agree.

#### Scenario: A waived grant suppresses the gate for that grant only

- **GIVEN** an agent granted `{toolId}#noapproval` for a tool the gate would otherwise fire on
- **WHEN** the agent invokes `{toolId}` with conforming arguments
- **THEN** the system MUST dispatch the invocation without creating a pending `Approval`
- **AND** an invocation of any other gated tool MUST still create a pending `Approval`
@e2e exclude Requires driving a model turn against a waived tool; asserted by unit test on the gate predicate.

#### Scenario: A waiver does not suppress the gate when the arguments do not conform

- **GIVEN** an agent granted `{toolId}?{argument}=in:{a},{b}#noapproval`
- **WHEN** the agent invokes `{toolId}` with `{argument}` set outside that set
- **THEN** the system MUST refuse the invocation with the existing `grant_constraint_violated`
  outcome before the gate is consulted
- **AND** the system MUST NOT dispatch the invocation
@e2e exclude Requires driving a constrained tool call from a model turn; asserted by unit test on the invoker's ordering of constraint check and gate.
