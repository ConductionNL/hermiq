# run-replay-and-dry-run Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- observability-compare-two-runs

## Purpose

A person compares two chosen runs side by side, including two runs of a flow. Row `hermiq:dm-run-compare`.

## ADDED Requirements

### Requirement: A person can compare any two runs they may see (REQ-RCMP-001)

Hermiq MUST offer a comparison of two agent runs, scheduled or flow-triggered, when both belong to agents the caller may see in the run list. A run the caller may not see MUST be answered the same way as a run that does not exist.

#### Scenario: An operator compares last night's run with the night before
- GIVEN a functional administrator on `/runs` with two runs of the agent "Nachtelijke zaakcontrole"
- WHEN they tick both and choose "Compare"
- THEN the view shows both runs in columns with start time, duration, status and model, and a summary line such as "Same outcome. Run B called Read file once more and took 4.2 s longer."
- e2e: `tests/e2e/spec-coverage/run-compare.spec.ts`

#### Scenario: A run of an agent the caller may not see
- GIVEN a run of an agent that is private to a colleague
- WHEN the caller requests a comparison naming it
- THEN that side is answered 404
- @e2e exclude a crafted request; covered by PHPUnit and Newman

### Requirement: Steps are aligned so an extra step shows as one difference (REQ-RCMP-002)

Hermiq MUST align the tool steps of the two runs by name and order, and MUST mark each step as present in both, only in one, or present in both with a different outcome. The replay comparison MUST use the same alignment and keep its current result fields.

#### Scenario: One extra lookup does not mark everything changed
- GIVEN run A with steps Search contacts, Read file, Send email and run B with steps Search contacts, Read file, Read file, Send email
- WHEN they are compared
- THEN only the second Read file of run B is marked as only in run B, and the other three steps are marked the same
- @e2e exclude alignment logic; covered by PHPUnit on the comparator

### Requirement: A person can compare two runs of a flow node by node (REQ-RCMP-003)

Hermiq MUST let a person compare two runs of one flow, read from OpenRegister's flow run API with the person's own rights, lined up by node, with status and duration per node. Hermiq MUST flag when the two runs used different flow versions, and MUST NOT store flow runs itself.

#### Scenario: A process owner sees where a flow run went different
- GIVEN two runs of the flow "Vergunningaanvraag intake", one completed and one failed
- WHEN the process owner ticks both on the flow run comparison page (reached from the Runs page) and chooses "Compare"
- THEN the view lines up the nodes, shows the node where the failed run stopped, and says "These runs used different versions of the flow (4 and 5)." when they did
- e2e: `tests/e2e/spec-coverage/run-compare.spec.ts`
