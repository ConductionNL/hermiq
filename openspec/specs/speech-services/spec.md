# Speech Services Specification

**Status**: in-progress (sidecar reachable and both directions verified end to end 2026-08-20; per-agent engine policy in flight)

**Feature tier**: V1

**OpenSpec changes:**
- `speech-services` — the sidecar itself: Speaches serving faster-whisper (STT) and Kokoro (TTS) behind one OpenAI-compatible API on a jailed network, exposed to Nextcloud as `core:audio2text` / `core:text2speech` TaskProcessing providers — **DONE for the sidecar, was NEVER TRUE end to end until 2026-08-20**: the providers were registered and advertised while the sidecar was unreachable from the Nextcloud container, and `AudioToTextProvider::process()` rejected the `OCP\Files\File` node Nextcloud actually passes. Zero tasks of either type had ever succeeded.
- `chat-speak-and-listen` (archived 2026-09-29): dictation and "Read aloud" on hermiq's own chat page, following the agent's speech policy.
- `voice-composer` — ADDED delta: a composer-facing synchronous transcription/synthesis surface, per-agent engine policy (browser vs on-instance), and the dictate/converse split in `@conduction/nextcloud-vue` (kind: code) — **in-progress**

## Purpose

Speech is a privacy decision before it is a feature. The browser's Web Speech API is fast, live and
excellent at Dutch — and in Chrome it works by streaming the microphone to Google's servers, with no
indication to the user that this is happening. An on-instance whisper/Kokoro pair is slower and
strictly private. Both are legitimate; which one is correct depends on what is being said, which is a
property of the agent rather than of the browser.

This capability owns that choice, and owns making the honest version of it available: a private
engine that provably works, an explicit per-agent policy, and a UI that never quietly substitutes one
engine for the other.

## Requirements

### Requirement: No audio leaves the instance
When an agent's speech policy selects the on-instance engine, audio and transcripts MUST reach only
the instance's own speech sidecar, and the sidecar MUST have no route off the host.

#### Scenario: A confidential agent dictates

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN an agent whose `voiceInputEngine` is `local`
- WHEN the user dictates a message
- THEN the audio MUST be sent only to the instance's speech service
- AND no browser speech recognition API may be started for that dictation

#### Scenario: The sidecar is jailed

@e2e exclude Container network posture, not a UI surface; asserted by reading /proc/net/route inside the sidecar and by an outbound call that must fail (docs/exapp-runner.md step 5).
- GIVEN the speech sidecar is running
- WHEN its network configuration is inspected
- THEN it MUST have no default route
- AND an outbound connection from it to a public host MUST fail

### Requirement: A local-pinned agent never falls back to a cloud engine
The system MUST NOT substitute a browser/cloud speech engine when the agent's policy names the local
engine, even when the local engine is unavailable.

#### Scenario: The speech service is down and the agent is local-only

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN an agent whose `voiceInputEngine` is `local`
- AND the speech service is unreachable
- WHEN the user presses the microphone
- THEN dictation MUST fail with a stated reason
- AND the browser speech recognition API MUST NOT be used as a fallback

### Requirement: Availability is measured, not assumed
The system MUST determine local-speech availability by reaching the speech service, not by reading
its configuration.

#### Scenario: Configured but unreachable

@e2e exclude Backend reachability probe; asserted by SpeechControllerTest::testCapabilitiesReportsUnreachable and by the composer test that consumes a false answer.
- GIVEN `speech_base_url` names a host that does not resolve
- WHEN a client asks whether local speech is available
- THEN the answer MUST be that it is unavailable

### Requirement: TaskProcessing providers accept the input Nextcloud passes
The `core:audio2text` provider MUST accept the audio input shapes Nextcloud's TaskProcessing manager
supplies, including an `OCP\Files\File` node.

#### Scenario: A scheduled audio2text task

@e2e exclude TaskProcessing queue behaviour has no UI; asserted by SpeechProvidersTest plus a live OCS schedule + occ taskprocessing:worker round trip.
- GIVEN a scheduled `core:audio2text` task whose input is a file in the user's storage
- WHEN the task is processed
- THEN the task MUST reach `STATUS_SUCCESSFUL`
- AND its output MUST contain the transcript

### Requirement: Speech policy is per agent
Each agent MUST carry its own speech configuration: which engine transcribes, which engine speaks,
how long a silence may last before the microphone is released, and whether spoken conversation is
offered at all.

#### Scenario: Two agents, two policies

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN agent A configured for the browser engine and agent B configured for the local engine
- WHEN the user switches the composer from A to B
- THEN the composer MUST use each agent's own engine for that agent's dictation

#### Scenario: Speech switched off entirely

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN an agent whose `voiceInputEngine` is `off`
- WHEN the composer renders for that agent
- THEN no microphone control may be offered

### Requirement: Dictation does not send by itself
Dictation MUST place the transcript in the composer and leave sending to the user. Automatic sending
on a silence is permitted only in conversation mode, which the user enters by an explicit control.

#### Scenario: The speaker pauses to think

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN dictation is running
- WHEN the speaker stops talking for longer than the configured silence
- THEN the microphone MUST be released
- AND the transcript MUST remain in the composer unsent

### Requirement: The microphone control states what is happening
The microphone control MUST indicate its actual state — recording or idle — rather than the action a
click would perform, and MUST distinguish the two by more than colour alone.

#### Scenario: Recording

@e2e tests/e2e/spec-coverage/speech-services.spec.ts
- GIVEN dictation is running
- WHEN the user looks at the microphone control
- THEN it MUST read as active
- AND it MUST NOT display a muted/struck-through microphone glyph

### Requirement: The chat page offers dictation (REQ-CHVOICE-001)

The chat page MUST show a microphone button beside the send button when the selected agent allows dictation and the on-instance speech service is available. Recording MUST show "Listening", waiting for the transcript MUST show "Transcribing", and the transcript MUST be placed in the message box without being sent.

#### Scenario: A person dictates a question

@e2e tests/e2e/spec-coverage/chat-speak-and-listen.spec.ts
- GIVEN a user on the chat page with the agent "Permit helper", whose dictation is set to local
- WHEN they press the microphone, say "Which permits expire this month", and press it again
- THEN the message box holds the words they said and no message has been sent

#### Scenario: A recording that cannot be transcribed

@e2e tests/e2e/spec-coverage/chat-speak-and-listen.spec.ts
- GIVEN the speech service fails while transcribing
- WHEN the person stops recording
- THEN the page shows "The speech service is unavailable." and the message box keeps what was typed before

### Requirement: The chat page reads answers aloud (REQ-CHVOICE-002)

Every answer from the agent MUST carry a "Read aloud" action when the agent allows spoken replies and the speech service is available. Pressing it MUST play the answer's text as speech; pressing it again, or starting another answer, MUST stop the playing one.

#### Scenario: A person listens to an answer

@e2e exclude An answer needs a completed LLM turn, which CI does not have; the player is asserted in tests/chat-speech.spec.js "a person listens to an answer; pressing again, or another answer, stops it (REQ-CHVOICE-002)".
- GIVEN an answer from "Permit helper" on the chat page
- WHEN the user presses "Read aloud"
- THEN the answer is spoken and the button changes to "Stop reading"

### Requirement: The chat page follows the agent's speech policy (REQ-CHVOICE-003)

The chat page MUST NOT show the microphone when the agent's `voiceInputEngine` is `off`, MUST NOT show "Read aloud" when its `voiceOutputEngine` is `off`, and MUST show neither when `GET /api/speech/capabilities` answers `available: false`. The chat page MUST NOT send audio to a browser speech engine.

#### Scenario: An agent with dictation switched off

@e2e tests/e2e/spec-coverage/chat-speak-and-listen.spec.ts
- GIVEN an agent whose dictation is off
- WHEN a user opens a chat with it
- THEN the composer shows no microphone button

#### Scenario: The speech service is down

@e2e tests/e2e/spec-coverage/chat-speak-and-listen.spec.ts
- GIVEN the speech service does not answer
- WHEN a user opens the chat page
- THEN neither the microphone nor "Read aloud" is shown
