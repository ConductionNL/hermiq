# observability

## ADDED Requirements

### Requirement: Chat reports whether its model provider is ready (REQ-SPCOV-012)

The system SHALL answer `GET /api/chat/health` with whether a chat provider is configured
and can be reached, so the companion and the connections page can show if chat works. The
answer SHALL hold no credential or endpoint secret.

Rows: `op-health`. Board: `hermiq/HmKoppelingen`.

#### Scenario: A provider is configured
- **GIVEN** a configured chat provider that answers
- **WHEN** the companion requests `GET /api/chat/health`
- **THEN** the answer is 200 with status "ok"

#### Scenario: No provider is configured
- **GIVEN** no chat provider is configured
- **WHEN** the companion requests `GET /api/chat/health`
- **THEN** the answer says chat is not configured and holds no secret

#### Scenario: The connections page shows chat health with each dependency
- **GIVEN** a functional administrator at Gemeente Zuiddrecht, with Ollama answering and the speech service not reachable
- **WHEN** they open the connections page in Hermiq
- **THEN** the page shows whether chat works, above a list of the AI provider, the default model, OpenRegister, Talk, the speech service and web search, each with its own state and the time it was checked
- **AND** the speech service row says it does not answer, and no row shows a key or secret
