## Tasks

### 1. One place assembles the current turn

- [x] `MessageHistoryHandler::buildMessageHistory()` accepts `exceptMessageId` and leaves that stored turn out
- [x] `Engine::processMessage()` passes the uuid of the user turn it just stored
- [x] `AssistantService::converse()` does the same
- [x] `ResponseGenerationHandler::generateResponse()` documents that `$messageHistory` holds earlier turns only

Acceptance criteria
- Every direct provider request carries the latest user message once, as the last turn
- Earlier turns of a multi-turn conversation reach the provider once each, in order

### 2. Tests that fail on the old code

- [x] `tests/Unit/Service/Engine/UserTurnSentOnceTest.php`: two turns through the real Engine, MessageHistoryHandler, ResponseGenerationHandler and provider clients, with only the wire replaced, for fireworks, anthropic, ollama and openai
- [x] Shown failing on the old code (all four requests carried the latest question twice)
