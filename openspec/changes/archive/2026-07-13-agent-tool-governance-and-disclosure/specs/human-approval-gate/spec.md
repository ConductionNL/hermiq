# human-approval-gate (delta)

Extends the human-approval gate to cover a new trigger: a destructive-hinted tool invocation that an
agent attempts during a run WITHOUT an explicit grant for it. Rather than executing silently or being
dropped, such an invocation routes through the existing `Approval` state machine and dispatch block,
reusing the same synchronous gate that already guards scheduled/gated actions (EU AI Act art.14).

## ADDED Requirements

### Requirement: Un-granted destructive tool invocation routes through the approval gate
The system MUST route an agent's attempted invocation of a write/destructive-hinted tool that is NOT
covered by an explicit per-agent grant through the human-approval gate: it MUST create a pending
`Approval` object and MUST NOT execute the invocation until that `Approval` reaches `approved`. A
destructive invocation the agent HAS been explicitly granted proceeds without a new approval (subject
to OpenRegister RBAC at invoke time).

#### Scenario: An agent attempts an un-granted destructive tool call
- **GIVEN** an agent whose grants do not explicitly include a destructive-hinted tool
- **WHEN** the agent's run attempts to invoke that tool
- **THEN** the system MUST create an `Approval` object in `pending` state for the responsible reviewer
- **AND** the system MUST NOT invoke the tool until the `Approval` reaches `approved`
- **AND** a denied `Approval` MUST prevent the invocation from ever executing

#### Scenario: An explicitly-granted destructive tool call is not re-gated
- **GIVEN** an agent whose grants explicitly include a destructive-hinted tool (by exact id or write
  modifier)
- **WHEN** the agent's run invokes that tool
- **THEN** the system MUST NOT require a new `Approval` solely because the tool is destructive
- **AND** OpenRegister RBAC MUST still authorize the invocation at invoke time
