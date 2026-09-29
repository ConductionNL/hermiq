# speech-services Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-speak-and-listen

## Purpose

Speak to an agent and hear its answers on hermiq's own chat page. Rows `hermiq:ch-voice-in`, `hermiq:ch-voice-out` and `hermiq:dl-transcribe`.

## ADDED Requirements

### Requirement: The chat page offers dictation (REQ-CHVOICE-001)

The chat page MUST show a microphone button beside the send button when the selected agent allows dictation and the on-instance speech service is available. Recording MUST show "Listening", waiting for the transcript MUST show "Transcribing", and the transcript MUST be placed in the message box without being sent.

#### Scenario: A person dictates a question
- GIVEN a user on the chat page with the agent "Permit helper", whose dictation is set to local
- WHEN they press the microphone, say "Which permits expire this month", and press it again
- THEN the message box holds the words they said and no message has been sent

#### Scenario: A recording that cannot be transcribed
- GIVEN the speech service fails while transcribing
- WHEN the person stops recording
- THEN the page shows "The speech service is unavailable." and the message box keeps what was typed before

### Requirement: The chat page reads answers aloud (REQ-CHVOICE-002)

Every answer from the agent MUST carry a "Read aloud" action when the agent allows spoken replies and the speech service is available. Pressing it MUST play the answer's text as speech; pressing it again, or starting another answer, MUST stop the playing one.

#### Scenario: A person listens to an answer
- GIVEN an answer from "Permit helper" on the chat page
- WHEN the user presses "Read aloud"
- THEN the answer is spoken and the button changes to "Stop reading"

### Requirement: The chat page follows the agent's speech policy (REQ-CHVOICE-003)

The chat page MUST NOT show the microphone when the agent's `voiceInputEngine` is `off`, MUST NOT show "Read aloud" when its `voiceOutputEngine` is `off`, and MUST show neither when `GET /api/speech/capabilities` answers `available: false`. The chat page MUST NOT send audio to a browser speech engine.

#### Scenario: An agent with dictation switched off
- GIVEN an agent whose dictation is off
- WHEN a user opens a chat with it
- THEN the composer shows no microphone button

#### Scenario: The speech service is down
- GIVEN the speech service does not answer
- WHEN a user opens the chat page
- THEN neither the microphone nor "Read aloud" is shown
