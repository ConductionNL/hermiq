## ADDED Requirements

### Requirement: The model receives the user's turn once

A chat turn SHALL send the user's latest message to the model exactly once, as the
last turn of the request, on every provider. The conversation history handed to
the response handler SHALL hold the turns before the current one only; the
response handler SHALL be the single place that appends the current turn.

#### Scenario: The latest question is sent once
- **GIVEN** a conversation on the openai, ollama, fireworks or anthropic provider
- **WHEN** a person asks a question
- **THEN** the provider request carries that question once
- **AND** it is the last message of the request

#### Scenario: A multi-turn conversation keeps its earlier turns
- **GIVEN** a conversation where the person asked a question and the agent answered
- **WHEN** the person asks a second question
- **THEN** the provider request carries the first question, then the answer, then the second question
- **AND** each of them appears once

#### Scenario: Storing the turn does not change what is sent
- **GIVEN** the engine stores the person's turn before it builds the history
- **WHEN** the history is built for the model
- **THEN** the stored copy of the current turn is left out of the history
- **AND** the stored turn itself is unchanged
