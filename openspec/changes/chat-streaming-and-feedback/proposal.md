---
kind: spec
depends_on: []
---

# The chat page streams answers and lets a person rate them

## Why

Two matrix rows are built on the chat page, but the spec they cite (`agent-engine-port`) says
neither, so the coverage matrix counts them as unspecified:

| row | capability | hermiq today |
|---|---|---|
| `ch-stream` | See the answer appear word by word while the agent is still writing. | built: `src/views/Chat.vue` `sendViaStream()` appends each `token` event to the open bubble; `ChatStreamController::stream()` serves `POST /api/chat/stream` as `text/event-stream` with `token`, `tool_call`, `tool_result`, `heartbeat`, `final` and `error` events; a transport failure falls back to `POST /send` |
| `ch-feedback` | Rate an answer with a thumbs up or down. | built: thumbs up and down under each saved answer, an optional comment box, `chat#sendFeedback` `POST /api/conversations/{uuid}/messages/{id}/feedback` storing one `Feedback` object per person and answer |

`run-analytics` REQ-FBSTAT-001/002 already cover the agent owner reading the ratings. Nothing
says that a person gives one.

## What changes

- `agent-engine-port` gains two requirements: the chat page streams the answer as it is
  written (REQ-CHSTRM-001) and a person rates an answer up or down with an optional comment
  (REQ-CHFB-001).
- `openspec/parity/capabilities.json` records `built.spec` and `built.requirement` for both rows.

## Non-goals

- No change to the stream envelope or the fallback ladder (ADR-034); this writes down what runs.
- Reading ratings stays in `run-analytics`.
