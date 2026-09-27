# agent-tool-governance Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- agents-switch-off-and-stop

## Purpose

Stop an agent after a number of tool calls its owner sets, on every provider path. Row `hermiq:dm-tool-call-cap`.

## ADDED Requirements

### Requirement: An agent stops after the tool calls its owner allows (REQ-AGOFF-005)

The system MUST let an agent owner set `maxToolCalls` on an agent, an integer from 1 to 100 with default 10. The system MUST count tool calls within one turn on every provider path and MUST NOT invoke a tool past the cap. The turn MUST end with the model's last answer and the run trace MUST record "Tool call limit reached for this turn".

#### Scenario: A looping agent hits its cap
- GIVEN an agent with `maxToolCalls` 5 that keeps asking for the same search
- WHEN it asks for a sixth tool call in one turn
- THEN the sixth tool is not invoked, the turn ends, and the run trace shows "Tool call limit reached for this turn"
- @e2e exclude engine behaviour, covered by PHPUnit on FacadeToolInvoker and ProviderFactory

#### Scenario: An owner raises the cap on the agent form
- GIVEN the owner of an agent editing it on the agent form
- WHEN they set "Maximum tool calls per answer" to 25 and save
- THEN the agent page shows 25 and the next turn may make up to 25 tool calls
