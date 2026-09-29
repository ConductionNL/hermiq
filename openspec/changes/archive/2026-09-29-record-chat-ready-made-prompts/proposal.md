---
kind: code
depends_on: []
---

# Proposal: record-chat-ready-made-prompts

## Summary

When a person talks to an agent about a record, the chat tab offers the ready-made prompts an administrator put in the prompt library for that record type. Picking one puts its text in the message box, where the person reads it, can change it, and sends it.

## Why

Row `hermiq:ch-prompt-library` is `specified` under `the-declared-tool-surface-and-the-prompt-library`, whose tasks are all ticked. The row stayed open because the library has no consumer in hermiq: the matrix evidence reads "src/components/settings/AssistantPromptLibrary.vue is the only caller of src/api/assistantPrompts.js ... Searched Chat.vue and CnAgentChatTab.vue for a prompt picker and found none." That change's task "Point the case assistant surface at the library" pointed a surface outside hermiq at it. This change builds the missing half in hermiq's own record chat. Decision: build, core area (chat), already decided in the OpenSpec pass of 2026-09-27.

## What hermiq already has

- The `AssistantPrompt` schema, the admin library (`src/components/settings/AssistantPromptLibrary.vue`) and the kill switch.
- `GET /api/assistant-prompts?scope=<record type>` (`AssistantPromptController::index()`, any signed-in user) returning the enabled prompts for that type plus the unscoped ones, in the administrator's order (`AssistantPromptLibrary::forScope()`).
- `CnAgentChatTab.vue`, the chat about one record, which knows the record type (`objectType`, else `schema`).

## What this change builds

1. The chat tab reads the prompts for its record type once per record and shows them as buttons above the message box, in the order received.
2. Picking a prompt puts its text in the message box, replacing an empty draft or following what was typed. Nothing is sent until the person sends it.
3. No prompts, or a failed read, shows nothing: the chat works as before.

## Out of scope

- Prompts on hermiq's `/chat` page, which has no record type.
- Filling placeholders in a prompt: that is `agents-instruction-variables`.
