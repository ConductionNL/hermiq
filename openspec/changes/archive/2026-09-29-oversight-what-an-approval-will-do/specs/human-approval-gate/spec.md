# human-approval-gate Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- oversight-what-an-approval-will-do

## Purpose

Show a reviewer what a held action will do before they decide. Row `hermiq:ov-approval-context`.

## ADDED Requirements

### Requirement: A reviewer sees what a held action will do (REQ-APPREV-001)

The approvals inbox MUST offer "Details" on every pending approval. For a held tool call the details MUST name the tool, say whether it reads, changes, sends or deletes, name its reach, and show its arguments as stored after redaction. For a held run of a schedule, flow or webhook the details MUST list the agent's granted tools with the same labels. The details MUST show why the action was held. Approve and deny MUST be available from the details.

#### Scenario: A reviewer checks a held deletion
- GIVEN a reviewer with a pending approval for the agent "Archive helper" to call a tool that deletes a file
- WHEN they open "Details" on it in the approvals inbox
- THEN they see the tool's name, "Deletes", the reach "Your own files", and the file path it would delete

#### Scenario: A reviewer checks a held scheduled run
- GIVEN a pending approval for the weekly run of "Supplier digest"
- WHEN the reviewer opens "Details"
- THEN they see the prompt, and the tools the agent may use with what each does and how far it reaches

### Requirement: The Talk request names the tool and its reach (REQ-APPREV-002)

When a held tool call is posted to Nextcloud Talk for approval, the message MUST name the tool and its reach.

#### Scenario: A reviewer decides in Talk
- GIVEN a held tool call posted to the reviewers' Talk room
- WHEN the reviewer reads the message
- THEN it names the tool and says how far it reaches before they react
- @e2e exclude Talk delivery, covered by PHPUnit on the message text
