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
