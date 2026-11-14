# Design: record-chat-ready-made-prompts

Read at hermiq development a437f295.

- `src/utils/readyMadePrompts.js`: `loadReadyMadePrompts(scope, fetchImpl)` calls `GET /apps/hermiq/api/assistant-prompts?scope=...` with the request token and returns `[{id, label, prompt}]` in the order received, dropping entries with no text; any failure returns `[]`. `withPrompt(draft, text)` returns the new draft. Plain functions so tests/record-chat-prompts.spec.js runs them in node.
- `CnAgentChatTab.vue` uses `fetch`, not axios, like its other reads (it is mounted inside other apps through the agent leaf). It loads the prompts in `reset()` after the bounded context, keyed to `objectType || schema`, and renders one tertiary `NcButton` per prompt in a labelled group ("Ready-made prompts").
- D1. The text placed is the prompt object's text, unchanged: the spec's "the text that will be sent is the text on screen".
- D2. Order is kept as the server sends it (the server already orders it); no client sort.
- D3. Scope is the record type; the server drops prompts scoped to another type. The client does not filter again.
- User-facing strings through `t('hermiq', ...)` in en and nl.
