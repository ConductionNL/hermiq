## 1. Build what the new scenarios say

- [ ] 1.1 Let an operator mark a tool call as misuse on the agent's tool activity, with a reason, and show the "Misuse" label with who marked it and when; write the mark to the agent's audit trail (row `ov-tool-oversight`). Verify: a unit test that a mark without a reason is refused and a mark is recorded with the operator's uid.
- [ ] 1.2 Cover the contact lookup and send-mail scenarios with a unit test on `HermiqToolProvider` (rows `tl-contacts`, `tl-mail`).
- [ ] 1.3 Cover the kill-switch read scenario with a unit test that `show()` returns `engagedBy` and `engagedAt` (row `co-disable-dept`).
