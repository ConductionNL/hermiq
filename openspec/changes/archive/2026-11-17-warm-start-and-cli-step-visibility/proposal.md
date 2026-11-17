---
kind: code
---

# Proposal: warm-start-and-cli-step-visibility

## Summary

Two costs a `cli`-mode turn imposed on the user, neither of them inference.

**The first question paid for the process start.** Measured on this instance, a
trivial prompt cost 11.2s cold against 3.2s warm. Nothing about that 8s was the
model thinking; it was a process nobody had started. The chat already knows which
agent it is talking to the moment it opens, so the start can happen while the
user is still typing.

**A governed `cli` turn's tool calls were invisible.** The chat renders steps
already — `ChatStreamController` emits `tool_call`/`tool_result`, `useAiChatStream`
folds them in, `CnAiMessageList` renders them. That whole chain works for tools
the engine invokes **in process**. A `cli` turn does not invoke tools in process:
the CLI calls Hermiq's MCP endpoint, so every tool runs inside a separate HTTP
request with no reference to the turn's channel. Nothing was broken at either
end — the transport simply bypassed the channel the UI was listening to, and a
turn that made five tool calls looked like one silent minute.

## Capabilities

### New Capabilities
- `warm-start-and-cli-step-visibility`

## Design notes

**The warm-up answers 200 whatever happens.** It is an optimisation the next
turn does not depend on, so a failure must be invisible rather than something
the chat has to handle. That includes the absent conversation:
`findConversation()` throws rather than returning null, which is the live path
when a chat opens on a stale id.

**Steps live in a cache, not the database.** They exist for one turn and are read
once. A distributed cache is TTL-native and needs no migration, no cleanup job
and no schema. Steps are display material; the audit trail is elsewhere, and
giving these a schema would imply they were it.

**The correlation already existed.** The per-run bearer token binds
`(runId, agentId, userId, conversationId)`, so the MCP request already knows
which conversation it belongs to. Nothing new had to be invented to join the two
halves — only used.
