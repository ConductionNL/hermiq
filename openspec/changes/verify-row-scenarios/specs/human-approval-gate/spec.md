## MODIFIED Requirements

### Requirement: Org-level kill-switch halts all runs
The system MUST provide a per-organisation kill-switch that, when activated, immediately halts
dispatch of all pending and new agent runs for that organisation until deactivated, including a
delegated sub-agent run (`sub-agent-delegation`) attempted by an already-running agent in that
organisation.

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

#### Scenario: An org admin checks that AI is off
- **GIVEN** the org admin of organisation A engaged its kill-switch on 1 October at 09:00
- **WHEN** an instance admin or the organisation's owner reads the switch of organisation A
- **THEN** the answer says the switch is engaged, with who engaged it and when
