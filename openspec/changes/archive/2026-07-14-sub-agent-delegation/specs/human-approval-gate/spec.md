# human-approval-gate (delta)

Extends the kill-switch and approval-gate requirements so a delegated sub-agent run
(`sub-agent-delegation`) is halted by the same instance-tenant kill-switch as any other run, and so an
approval-gated agent cannot be reached at all as a delegation target (no synchronous mid-turn approval
wait is attempted — the target remains reachable only via its own schedule/flow trigger).

## MODIFIED Requirements

### Requirement: Org-level kill-switch halts all runs
The system MUST provide a per-organisation kill-switch that, when activated, immediately halts
dispatch of all pending and new agent runs for that organisation until deactivated, including a
delegated sub-agent run (`sub-agent-delegation`) attempted by an already-running agent in that
organisation.
<!-- Previous behavior: the kill-switch halted scheduled, Run-now, and flow/webhook-triggered runs;
     delegated sub-agent runs did not exist. -->

#### Scenario: An org admin activates the kill-switch
- GIVEN organisation A has one or more agent runs in progress or scheduled
- WHEN an org admin activates the kill-switch for organisation A
- THEN the system MUST halt dispatch of all in-progress and newly scheduled runs for organisation A
- AND runs for other organisations MUST continue unaffected

#### Scenario: An already-running agent attempts to delegate while the kill-switch is engaged
- **GIVEN** organisation A's kill-switch is engaged while agent A (organisation A) is already
  mid-turn
- **WHEN** agent A's turn calls `hermiq.delegateAgent` targeting an allowed agent
- **THEN** the system MUST refuse the delegation with a `delegation_killswitch` error before
  invoking the target agent
- **AND** agent A's already-in-progress turn MUST be allowed to finish uninterrupted

### Requirement: Approval object state machine enforced before execution
The system MUST model each gated action as an `Approval` OpenRegister object with state `pending`,
`approved`, or `denied`, and the dispatch loop MUST block execution of the gated action until the
`Approval` object reaches `approved`. An agent configured with `requiresApproval` MUST NOT be
reachable as a `sub-agent-delegation` target: a delegation attempt targeting such an agent MUST be
refused outright (no `Approval` object is created and no synchronous wait occurs), while the target
agent MUST remain runnable through its own schedule or flow trigger, which retain the full
pending/approved/denied gate.
<!-- Previous behavior: the gate applied to scheduled and flow/webhook-triggered runs, which support
     pausing and resuming via a pending Approval; delegation (a synchronous, in-turn tool call with
     no pause/resume mechanism) did not exist. -->

#### Scenario: An agent run reaches a gated action awaiting approval
- GIVEN an agent's run reaches a step configured to require human approval
- WHEN the dispatch loop creates an `Approval` object in `pending` state
- THEN the system MUST NOT execute the gated action
- AND execution MUST remain blocked until the `Approval` object's state changes to `approved`

#### Scenario: A delegation targets an approval-gated agent
- **GIVEN** target agent B has `requiresApproval` set to true
- **WHEN** any allowed agent calls `hermiq.delegateAgent` targeting agent B
- **THEN** the system MUST refuse the delegation with a `delegation_requires_approval` error
- **AND** the system MUST NOT create a pending `Approval` object for this delegation attempt
- **AND** agent B MUST remain reachable via its own schedule's or flow trigger's existing
  approval gate
