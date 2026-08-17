---
kind: code
---

## Why

An agent's tool steps now appear in the chat, but only **after** the turn ends —
the whole run lands at once when the answer does. A turn that takes 60s still
shows a silent minute, which is the problem the steps were meant to solve.

The data is already live. `RunStepBus` is written by each MCP request **as the
tool runs**, from a separate PHP process. Measured: `pipelinq_client_search` at
30ms and `docudesk_readDocument` at 81ms were in the cache long before the turn
returned.

What is late is the **reader**, and for a structural reason:

```
ChatStreamController ── processMessage() ─────────────► (blocked ~60s)
                                                          │
   CLI ──HTTP──► McpRunController ──► RunStepBus.record()  │  live, other process
   CLI ──HTTP──► McpRunController ──► RunStepBus.record()  │
                                                          ▼
                              drain() ──► emit tool_call events
```

The streaming process is blocked inside `processMessage()` for the whole turn.
It cannot drain a bucket while it is waiting, so it drains once, at the end.

## What Changes

**Read the live bucket from the browser instead of from the blocked process.**

- Add `GET /api/chat/steps?conversation=<uuid>` — returns `RunStepBus::read()`
  (read, NOT drain), owner-scoped by the same guard `sendMessage` uses.
- While a turn is in flight, `useAiChatStream` polls it every ~1s and appends any
  step it has not seen, keyed on `toolId`.
- Stop polling on `final` / `error`. The existing end-of-turn drain stays as the
  authoritative list and reconciles anything the poll missed.

No change to the blocking dispatch, the engine, the runner, or the MCP transport.
The renderer already exists and is unchanged.

## Why not the two server-side alternatives

**Drain on the heartbeat tick.** The natural idea, and it cannot work here: the
heartbeat is emitted by the same process that is blocked in `processMessage()`.
There is no tick during the wait because there is no control flow during the
wait.

**Make the runner dispatch non-blocking and poll server-side.** This would give
true server-push, and it means restructuring `ProviderFactory::dispatchCliTurn()`
from one blocking `exAppRequest` into a submit-then-poll loop, plus a place to
hold the in-flight run. That is a change to the transport that currently carries
every governed turn, to move a progress indicator. Not worth it unless the
transport needs to become async for its own reasons.

⚠️ Polling is the cheaper answer **because the data is already published**. This
would be the wrong choice if the browser had to ask the server to go and look;
here it only has to read what is already there.

## Capabilities

### Modified Capabilities
- `agent-chat` — the step timeline becomes live rather than end-of-turn.

## Risks

- **Poll cost.** One small request per second per open, actively-streaming panel.
  Bounded: only while a turn is in flight, never on an idle panel.
- **Duplicate steps.** The poll and the final drain can both deliver the same
  step. `toolId` is already unique per step, so the client dedupes on it — and it
  must, or the last second of a turn shows every step twice.
- **A step for a turn the user has left.** The bucket is per conversation, so a
  poll started before a conversation switch could paint steps into the wrong
  thread. Bind each poll to the conversation uuid it started with and discard a
  late response whose uuid no longer matches.
