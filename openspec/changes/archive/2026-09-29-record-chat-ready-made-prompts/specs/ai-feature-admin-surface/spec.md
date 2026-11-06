# ai-feature-admin-surface Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- record-chat-ready-made-prompts

## Purpose

The record chat offers the prompt library. Row `hermiq:ch-prompt-library`.

## ADDED Requirements

### Requirement: The record chat offers the ready-made prompts for its record type (REQ-RCPROMPT-001)

The chat about a record MUST offer the enabled prompts the library holds for that record type and the unscoped ones, in the order the library returns them. Picking a prompt MUST put the prompt's text in the message box unchanged and MUST NOT send it. When the library has no prompt for the type, or cannot be read, the chat MUST offer none and MUST keep working.

#### Scenario: A person picks a ready-made prompt on a record

@e2e exclude The record chat is mounted by the agent leaf inside a consuming app's record page, which the hermiq e2e instance does not seed; asserted in tests/record-chat-prompts.spec.js "a person picks a ready-made prompt on a record: the scoped read, order kept, text unchanged" and "CnAgentChatTab offers the prompts for its record type and picking fills the draft only".
- GIVEN an administrator added the prompt "Summarise this case" scoped to the record type "case"
- WHEN a user opens the agent chat on a case and picks "Summarise this case"
- THEN the message box holds the prompt's text and no message has been sent

#### Scenario: A prompt for another record type is not offered

@e2e exclude Scope filtering is the server's (AssistantPromptLibrary::forScope, unchanged); the client side is asserted in tests/record-chat-prompts.spec.js "a prompt for another record type is not offered: the scope goes to the server, which filters".
- GIVEN a prompt scoped to the record type "permit"
- WHEN a user opens the agent chat on a case
- THEN that prompt is not offered
