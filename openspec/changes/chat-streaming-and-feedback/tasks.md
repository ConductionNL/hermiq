## 1. Traceability

- [ ] 1.1 Point the `@spec` tags on `ChatStreamController::stream()` and on
      `Chat.vue` `sendViaStream()` at REQ-CHSTRM-001, and on `ChatController::sendFeedback()`,
      `Chat.vue` `sendFeedback()` and `saveFeedbackComment()` at REQ-CHFB-001 (gate 16).
      Verify: the gate 46 anchor check resolves every new tag.
- [ ] 1.2 Add an e2e test `tests/e2e/chat-stream-and-feedback.spec.ts` that sends a message,
      sees the answer bubble grow before the turn ends, rates the answer with a thumbs down
      and a comment, reloads and sees the thumbs down still marked (gate 19).
