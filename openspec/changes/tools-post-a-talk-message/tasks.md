## 1. Build

- [x] 1.1 A `talk.message.send` tool descriptor in `lib/Mcp/`, medium risk, posting through `TalkBridge::postToRoom`.
- [x] 1.2 A membership check: the tool refuses a conversation the agent's Talk identity is not a member of, and degrades softly when Talk is not installed.
- [x] 1.3 The tool in the tool catalogue (HmMcpTools) and in the agent's grant editor (HmAgentBewerken).
- [x] 1.4 Unit tests in `tests/Unit/Mcp/` for a granted post, an ungranted call, a room the agent is not in, and Talk missing.

## 2. Hand-over

- [x] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.

Built: `lib/Mcp/NcTalkToolDescriptors.php` (`hermiq.sendTalkMessage`, this app's id for `talk.message.send`; reach `instance`, a write that needs a grant), `lib/Service/NcNative/TalkMessageTool.php` behind `TalkMessageToolInterface`, routed by `HermiqToolProvider`, with the run's agent id injected by `FacadeToolInvoker`. Membership is the agent's own bot being enabled in the conversation (`TalkBridge::isAgentBotIn()`); without Talk the tool answers `talk_unavailable`. The tool catalogue and the grant editor list it from `getTools()`, so no screen code changed. Tests: `tests/Unit/Mcp/TalkMessageToolTest.php` and `HermiqToolProviderTest`. Every scenario carries a reason-bearing `@e2e exclude`.
