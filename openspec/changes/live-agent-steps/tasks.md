# Tasks

## 1. Publish the live bucket

- [ ] Add `GET /api/chat/steps?conversation=<uuid>` returning `RunStepBus::read()`
- [ ] Reuse `sendMessage`'s ownership guard — same conversation access check, no new policy
- [ ] READ, never drain: the end-of-turn drain stays the authoritative list

Acceptance criteria:
- ⚠️ A drain here would race the turn's own drain and steps would vanish from the final message. The endpoint must be side-effect free.
- Assert a non-owner gets the same refusal `sendMessage` gives for that conversation. A steps feed is a feed of what an agent is doing on someone's data.
- Cheap by construction: this is a cache read, no engine, no OpenRegister query.

## 2. Poll while a turn is in flight

- [ ] Poll every ~1s from `useAiChatStream`, starting when a send begins
- [ ] Append only steps whose `toolId` is unseen; stop on `final` or `error`
- [ ] Discard a response whose conversation uuid no longer matches the active one

Acceptance criteria:
- Dedupe on `toolId` or the last second of every turn shows each step twice — the poll and the final drain both deliver them.
- ⚠️ Never poll an idle panel. The cost is only justified while something is actually running.
- A conversation switch mid-turn must not paint steps into the new thread.

## 3. Prove it is live, not just present

- [ ] Measure the gap between a tool completing and its step appearing on screen
- [ ] Verify with a turn long enough to see it (a multi-tool composite, not a one-liner)

Acceptance criteria:
- The test is the TIMING, not the presence. Steps already appear at end-of-turn today, so "the steps are shown" passes without this change and proves nothing.
- Assert the first step appears while the answer is still streaming — measured from the runner log's per-tool timing against the browser's receipt.
- ⚠️ Use a turn with several tool calls. A single-tool turn finishes so fast that end-of-turn and live are indistinguishable, which would let a broken implementation pass.
