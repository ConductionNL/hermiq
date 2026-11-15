---
kind: code
---

## Why

The model received the user's latest message twice on every direct provider
(openai, ollama, fireworks, anthropic).

`Engine::processMessage()` stores the user's turn, then asks
`MessageHistoryHandler::buildMessageHistory()` for the conversation history. That
history is read from storage, so it already ends with the turn just stored. Then
`ResponseGenerationHandler::generateResponse()` appends the same turn again (with
its attachments), and the provider request carries it twice:

```
system, ...earlier turns, user: "Which of them is due this week?", user: "Which of them is due this week?"
```

`AssistantService::converse()` follows the same store-then-build order and had the
same defect. The cost is real: every turn paid for its question twice in tokens,
the model was nudged to treat it as emphasis, and a turn with attachments sent the
text once plain and once with its parts.

PR #1150 (Nextcloud AI path) noticed this and dropped the extra copy inside its
own client. That fixes one provider out of five, at the wrong layer.

## What changes

- `MessageHistoryHandler::buildTurnHistory(conversationId, currentTurn, turnText)`
  returns the history before the stored current turn (that turn left out) and
  `turnText`: the turn's text, labelled with its author when the session has
  several speakers, exactly as the history labelled it before.
- `Engine::processMessage()` and `AssistantService::converse()` call it with the
  user turn they just stored, and hand `turnText` to the response handler as the
  current turn. The history they hand on holds earlier turns only.
- `ResponseGenerationHandler::generateResponse()` documents the contract it
  already followed: `$messageHistory` is the turns before this one, and this method
  is the single place the current turn joins the messages.
- `buildMessageHistory()` is unchanged for any caller that has not stored a turn.

Why `turnText` and not just "leave it out": in a shared session the stored copy
was the only one carrying the speaker's name ("Bram: ..."); the appended copy had
none. Dropping the stored copy alone would have lost who is asking. The current
turn now carries the label itself, once.

The fetch window is unchanged (ten stored turns), so the request carries the same
number of messages as before minus the duplicate.

## Interaction with #1150

`NextcloudChatMapper::splitHistory()` in #1150 pops the last user turn as `input`
and then pops the turn before it when that one is a user turn with the same text.
With this change the turn before `input` is normally the previous assistant answer,
so its check never fires and the only copy is kept. Its de-dup is therefore safe in
either merge order, but redundant: it can still drop a genuine earlier turn when a
person resends the same question after a failed answer. #1150 should remove that
block once this lands.

## Out of scope

- Providers' own request mapping. Each provider sends what the engine gives it.
- Stored data. Nothing stored changes; only what is read back for the prompt.
