# speech-services Specification

**Status**: proposed
**Scope**: hermiq
**OpenSpec changes**:
- chat-translate-a-recording

## Purpose

A person translates a recording into another language and hears the result, with the languages and engines recorded. Row `hermiq:dm-speech-translate`.

## ADDED Requirements

### Requirement: A person can translate a recording and hear the result (REQ-STRN-001)

Hermiq MUST offer a translate action on a recording that transcribes it on the instance's speech service, translates the text into the chosen language, speaks the translation with a voice for that language, and saves the spoken result in the person's Files tagged "Agent authored". When no voice exists for the target language, hermiq MUST return the written translation and MUST say that it cannot be spoken.

#### Scenario: A front office employee translates a voicemail
- GIVEN a front office employee at Gemeente Utrecht with the voicemail `terugbelverzoek.m4a` in their Files, in Arabic
- WHEN they choose "Translate a recording" on `/chat`, pick the file, choose "Nederlands" and start
- THEN the chat shows the Arabic transcript, the Dutch translation and a player, and a WAV file tagged "Agent authored" is in their Files under `Hermiq/Translations`
- e2e: `tests/e2e/spec-coverage/speech-translate.spec.ts`

#### Scenario: A language without a voice gets text
- GIVEN no voice is mapped for Dutch on the instance
- WHEN a person translates a recording into Dutch
- THEN the result shows the written translation and "There is no voice for Nederlands on this server. The translation is shown as text.", and no audio file is written
- e2e: `tests/e2e/spec-coverage/speech-translate.spec.ts`

### Requirement: The languages and engines used are recorded (REQ-STRN-002)

Hermiq MUST record for each translation the source language given, the language detected, the target language, the transcription engine, the translation provider and model, and the speech engine and voice. The record MUST be stored on the chat turn and shown under the result.

#### Scenario: A reviewer can see what was used
- GIVEN a completed translation in a session
- WHEN a colleague opens the session later
- THEN the result carries the line "Arabisch (detected) to Nederlands. Transcribed and spoken on this server, translated by Qwen3 on this server."
- e2e: `tests/e2e/spec-coverage/speech-translate.spec.ts`

### Requirement: The translation step follows the feature's provider rules (REQ-STRN-003)

Hermiq MUST run the translation step under the AI feature `speech-translation`, so its provider binding, the organisation's model policy and its required residency apply before any text is sent. Hermiq MUST tell the person where the translation will run before they start. Transcription and speech MUST stay on the instance's speech service.

#### Scenario: An on-premise requirement stops a hosted translation
- GIVEN `speech-translation` requires residency `on-premise` and its binding names a hosted provider
- WHEN a person starts a translation
- THEN the run is refused before the transcript is sent, with a message naming the feature and the required residency
- @e2e exclude depends on a residency and binding set up per test; covered by PHPUnit on the chain

### Requirement: Other apps can use the chain through TaskProcessing where Nextcloud defines it (REQ-STRN-004)

Hermiq MUST register a TaskProcessing provider for `core:audio2audio:translate` when that task type exists on the instance, and MUST NOT fail to boot when it does not.

#### Scenario: Nextcloud 34 boots without the provider
- GIVEN a Nextcloud 34 instance, where `core:audio2audio:translate` does not exist
- WHEN hermiq is enabled
- THEN hermiq boots, and the Assistant's task list offers no audio translation from hermiq
- @e2e exclude needs two Nextcloud versions; covered by PHPUnit on the registration guard
