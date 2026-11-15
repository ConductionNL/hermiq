## 1. Build

- [ ] 1.1 A `talk.message.send` tool descriptor in `lib/Mcp/`, medium risk, posting through `TalkBridge::postToRoom`.
- [ ] 1.2 A membership check: the tool refuses a conversation the agent's Talk identity is not a member of, and degrades softly when Talk is not installed.
- [ ] 1.3 The tool in the tool catalogue (HmMcpTools) and in the agent's grant editor (HmAgentBewerken).
- [ ] 1.4 Unit tests in `tests/Unit/Mcp/` for a granted post, an ungranted call, a room the agent is not in, and Talk missing.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
