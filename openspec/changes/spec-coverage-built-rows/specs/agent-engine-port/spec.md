# agent-engine-port

## ADDED Requirements

### Requirement: A long conversation is summarised so it can continue (REQ-SPCOV-009)

When a conversation's history passes the token threshold, the system SHALL replace its
older turns with a stored running summary before the next turn, and SHALL keep the most
recent turns in full. The person SHALL be able to keep chatting without starting a new
session.

Rows: `me-compression`. Board: `hermiq/HmChat`.

#### Scenario: A conversation passes the threshold
- **GIVEN** a session whose history has passed the token threshold
- **WHEN** the person sends the next message
- **THEN** the model receives a summary of the older turns and the most recent turns in full
- **AND** the answer arrives in the same session
