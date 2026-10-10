# agent-memory Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- memory-correct-and-forget

## Purpose

Let the owner of an agent correct or remove what the agent remembers. Row `hermiq:me-view-edit`.

## ADDED Requirements

### Requirement: An owner can correct a remembered fact (REQ-MEMEDIT-001)

The system MUST let the owner of an agent replace the text of one memory entry with `PUT /api/agents/{agentId}/memory/entries/{entryId}`. The old entry MUST be kept with `deletedAt` set, and the new text MUST be stored as a new entry. An empty text MUST be refused with HTTP 400.

#### Scenario: An owner corrects a wrong fact
- GIVEN the owner of the agent "Permit helper" on its page, whose memory holds "The permit desk closes at 16:00"
- WHEN they choose "Correct" on that fact, change it to "The permit desk closes at 17:00" and save
- THEN the memory list shows "The permit desk closes at 17:00" and no longer shows the old time

### Requirement: An owner can make an agent forget a fact (REQ-MEMEDIT-002)

The system MUST let the owner of an agent soft-delete one memory entry with `DELETE /api/agents/{agentId}/memory/entries/{entryId}`. A forgotten entry MUST NOT appear in the memory list or in recall. A user who does not own the agent MUST get HTTP 404 and the memory MUST NOT change.

#### Scenario: An owner removes a fact
- GIVEN the owner of "Permit helper" on its memory list
- WHEN they choose "Forget" on a fact and confirm
- THEN the fact is gone from the list and the agent no longer recalls it

#### Scenario: Someone else tries to remove a fact
- GIVEN a user who does not own "Permit helper"
- WHEN they call `DELETE /api/agents/{agentId}/memory/entries/{entryId}`
- THEN the answer is HTTP 404 and the entry is unchanged
- @e2e exclude authorization contract on the endpoint, covered by PHPUnit
