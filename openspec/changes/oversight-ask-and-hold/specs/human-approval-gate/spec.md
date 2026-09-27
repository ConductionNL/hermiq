# human-approval-gate Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- oversight-ask-and-hold

## Purpose

An agent asks a person a question mid-run, and written output is held until a person has checked it. Rows `hermiq:dm-ask-mid-run` and `hermiq:ov-draft-hold`.

## ADDED Requirements

### Requirement: An agent can ask a person a question with suggested answers (REQ-ASKHOLD-001)

The system MUST offer agents the tool `hermiq.askPerson` with a question, up to four suggested answers and an optional free-text answer. Calling it MUST end the turn. In interactive chat the question MUST show under the answer with a button per suggested answer; choosing one MUST send it as the next message.

#### Scenario: An agent asks which municipality a request is for
- GIVEN a case handler in chat with an intake agent that cannot tell which municipality a request belongs to
- WHEN the agent calls `hermiq.askPerson` with "Which municipality is this for?" and the options "Utrecht", "Nieuwegein" and "Houten"
- THEN the chat shows the question with three buttons and a text box, and choosing "Houten" sends "Houten" and the agent continues with it

### Requirement: A question in an unattended run goes to the reviewer and the run continues with the answer (REQ-ASKHOLD-002)

The system MUST turn a question asked in a scheduled, flow or webhook run into a pending approval of source type `question`, routed to the run's reviewer or else the agent owner, shown in the approval inbox and in Talk. Answering it MUST re-dispatch the run with the answer added. An unanswered question MUST expire as denied after 7 days.

#### Scenario: A nightly run asks the team lead and continues in the morning
- GIVEN a nightly agent run that asks "Close the three duplicate reports?" with the options "Yes, close them" and "No, keep them"
- WHEN the team lead opens the approval inbox in the morning and chooses "Yes, close them"
- THEN the run is dispatched again with the answer, and its run history links the question, the answer and the new run

### Requirement: Written output can be held until a reviewer releases it (REQ-ASKHOLD-003)

The system MUST let an agent owner set `holdOutput` on an agent or a schedule. With it set, scheduled delivery and outbound message tools MUST create a pending approval of source type `draft` instead of sending. The reviewer MUST be able to release it, edit the message and release it, or discard it with a reason. Recipients MUST NOT be editable. The approval MUST record the original and the sent text.

#### Scenario: A weekly report is checked before it reaches the council
- GIVEN a schedule with `holdOutput` that delivers a weekly report by email
- WHEN the run finishes
- THEN no mail is sent, the reviewer's inbox shows the draft under "Drafts", and after correcting one figure and choosing "Edit and release" the mail goes out with the corrected text and the approval shows both versions

#### Scenario: Changed recipients are refused
- GIVEN a held mail draft
- WHEN a release request carries a different recipient
- THEN the answer is HTTP 422 and nothing is sent
- @e2e exclude request tampering, covered by PHPUnit
