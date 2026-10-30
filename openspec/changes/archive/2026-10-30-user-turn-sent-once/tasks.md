## Tasks

### 1. One place assembles the current turn

- [x] `MessageHistoryHandler::buildTurnHistory()` returns the history without the stored current turn, plus that turn's text (speaker-labelled in a shared session)
- [x] `Engine::processMessage()` uses it and sends `turnText` as the current turn
- [x] `AssistantService::converse()` does the same
- [x] `ResponseGenerationHandler::generateResponse()` documents that `$messageHistory` holds earlier turns only

Acceptance criteria
- Every direct provider request carries the latest user message once, as the last turn
- Earlier turns of a multi-turn conversation reach the provider once each, in order
- In a shared session the current turn still names its speaker

### 2. Tests that fail on the old code

- [x] `tests/Unit/Service/Engine/UserTurnSentOnceTest.php`: two turns through the real Engine, MessageHistoryHandler, ResponseGenerationHandler and provider clients, with only the wire replaced, for fireworks, anthropic, ollama and openai
- [x] Shown failing on the old code (all four requests carried the latest question twice)
- [x] `MessageHistoryHandlerTest::testTurnHistoryLeavesTheCurrentTurnOutAndKeepsItsSpeaker`
- [x] Engine and AssistantService test doubles moved from `buildMessageHistory` to `buildTurnHistory`
