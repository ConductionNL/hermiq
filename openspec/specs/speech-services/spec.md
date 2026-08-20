# Speech Services Specification

**Status**: in-progress (sidecar reachable and both directions verified end to end 2026-08-20; per-agent engine policy in flight)

**Feature tier**: V1

**OpenSpec changes:**
- `speech-services` — the sidecar itself: Speaches serving faster-whisper (STT) and Kokoro (TTS) behind one OpenAI-compatible API on a jailed network, exposed to Nextcloud as `core:audio2text` / `core:text2speech` TaskProcessing providers — **DONE for the sidecar, was NEVER TRUE end to end until 2026-08-20**: the providers were registered and advertised while the sidecar was unreachable from the Nextcloud container, and `AudioToTextProvider::process()` rejected the `OCP\Files\File` node Nextcloud actually passes. Zero tasks of either type had ever succeeded.
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
- GIVEN an agent whose `voiceInputEngine` is `local`
- WHEN the user dictates a message
- THEN the audio MUST be sent only to the instance's speech service
- AND no browser speech recognition API may be started for that dictation

#### Scenario: The sidecar is jailed
- GIVEN the speech sidecar is running
- WHEN its network configuration is inspected
- THEN it MUST have no default route
- AND an outbound connection from it to a public host MUST fail

### Requirement: A local-pinned agent never falls back to a cloud engine
The system MUST NOT substitute a browser/cloud speech engine when the agent's policy names the local
engine, even when the local engine is unavailable.

#### Scenario: The speech service is down and the agent is local-only
- GIVEN an agent whose `voiceInputEngine` is `local`
- AND the speech service is unreachable
- WHEN the user presses the microphone
- THEN dictation MUST fail with a stated reason
- AND the browser speech recognition API MUST NOT be used as a fallback

### Requirement: Availability is measured, not assumed
The system MUST determine local-speech availability by reaching the speech service, not by reading
its configuration.

#### Scenario: Configured but unreachable
- GIVEN `speech_base_url` names a host that does not resolve
- WHEN a client asks whether local speech is available
- THEN the answer MUST be that it is unavailable

### Requirement: TaskProcessing providers accept the input Nextcloud passes
The `core:audio2text` provider MUST accept the audio input shapes Nextcloud's TaskProcessing manager
supplies, including an `OCP\Files\File` node.

#### Scenario: A scheduled audio2text task
- GIVEN a scheduled `core:audio2text` task whose input is a file in the user's storage
- WHEN the task is processed
- THEN the task MUST reach `STATUS_SUCCESSFUL`
- AND its output MUST contain the transcript

### Requirement: Speech policy is per agent
Each agent MUST carry its own speech configuration: which engine transcribes, which engine speaks,
how long a silence may last before the microphone is released, and whether spoken conversation is
offered at all.

#### Scenario: Two agents, two policies
- GIVEN agent A configured for the browser engine and agent B configured for the local engine
- WHEN the user switches the composer from A to B
- THEN the composer MUST use each agent's own engine for that agent's dictation

#### Scenario: Speech switched off entirely
- GIVEN an agent whose `voiceInputEngine` is `off`
- WHEN the composer renders for that agent
- THEN no microphone control may be offered

### Requirement: Dictation does not send by itself
Dictation MUST place the transcript in the composer and leave sending to the user. Automatic sending
on a silence is permitted only in conversation mode, which the user enters by an explicit control.

#### Scenario: The speaker pauses to think
- GIVEN dictation is running
- WHEN the speaker stops talking for longer than the configured silence
- THEN the microphone MUST be released
- AND the transcript MUST remain in the composer unsent

### Requirement: The microphone control states what is happening
The microphone control MUST indicate its actual state — recording or idle — rather than the action a
click would perform, and MUST distinguish the two by more than colour alone.

#### Scenario: Recording
- GIVEN dictation is running
- WHEN the user looks at the microphone control
- THEN it MUST read as active
- AND it MUST NOT display a muted/struck-through microphone glyph
