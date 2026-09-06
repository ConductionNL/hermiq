# speech-services

Local speech-to-text and text-to-speech, exposed through Nextcloud's
TaskProcessing task types, with per-language support declared from measurement
rather than from vendor claims. Audio is treated as personal data throughout.

## ADDED Requirements

### Requirement: Speech runs locally in a dedicated runner, not in the LLM runtime
The system MUST perform transcription and synthesis in a local speech runner
process. The system MUST NOT route audio to the LLM runtime, which has no audio
input path, and MUST NOT send audio or transcripts outside the instance.

#### Scenario: Audio is transcribed
- **WHEN** audio is submitted for transcription
- **THEN** the transcription MUST be performed by the local speech runner
- **AND** no audio MUST be transmitted outside the instance

#### Scenario: The runner is unavailable
- **WHEN** the speech runner cannot be reached
- **THEN** the provider MUST report unavailability
- **AND** the system MUST NOT fall back to any destination outside the instance

### Requirement: Speech is exposed through Nextcloud TaskProcessing task types
The system MUST register its transcription capability as an `AudioToText`
TaskProcessing provider and its synthesis capability as a `TextToSpeech`
provider, so every TaskProcessing consumer can use them. The system MUST NOT
expose speech only through an app-private interface.

#### Scenario: Another app requests transcription
- **GIVEN** an application other than the provider's own uses the TaskProcessing API
- **WHEN** it schedules an audio-to-text task
- **THEN** the task MUST be served by the registered provider

### Requirement: Per-language support is declared from measurement, and pinned to a model version
The system MUST publish a language support declaration covering each of the 24
official EU languages, in which every entry carries a measured word error rate, the
named public benchmark and dataset version it was measured on, the exact model
version measured, and the date of measurement. The declaration MUST be invalidated
when the model version changes. A language absent from the declaration MUST be
treated as unsupported.

#### Scenario: A language's support level is queried
- **WHEN** the support level for a language is requested
- **THEN** the response MUST be derived from a recorded measurement
- **AND** it MUST identify the model version and benchmark the measurement used

#### Scenario: A language has never been measured
- **WHEN** transcription is requested in a language absent from the declaration
- **THEN** the system MUST treat that language as unsupported
- **AND** the system MUST NOT infer support from the model vendor's language list

#### Scenario: The speech model is changed
- **WHEN** the pinned model version changes
- **THEN** the existing declaration MUST NOT be presented as valid for the new model
- **AND** support levels MUST be re-measured before being declared again

### Requirement: Language coverage is declared per direction
The system MUST declare language support separately for transcription and for
synthesis, because the two use different models with materially different coverage.
The system MUST NOT present a single language list, or a single count, as covering
both directions.

#### Scenario: A consumer asks whether a language is supported
- **WHEN** support for a language is queried
- **THEN** the answer MUST state support for transcription and for synthesis separately

#### Scenario: A language is supported in one direction only
- **GIVEN** a language whose transcription is supported and whose synthesis is not
- **WHEN** its support is reported
- **THEN** it MUST be reported as supported for transcription and unsupported for
  synthesis
- **AND** it MUST NOT be reported as simply "supported" or simply "unsupported"

#### Scenario: Synthesis is requested in a language the synthesis engine does not cover
- **WHEN** synthesis is requested in an uncovered language
- **THEN** the system MUST refuse
- **AND** the system MUST NOT synthesise the text using another language's voice

### Requirement: A transcript below the declared quality threshold is declared, not returned silently
The system MUST NOT return a transcript in a below-threshold language as an
ordinary result. The caller MUST be able to tell, from the result itself, that the
transcript was produced in a language whose measured quality is below the declared
threshold.

#### Scenario: Transcription is requested in a below-threshold language
- **WHEN** audio is transcribed in a language whose measured quality is below the threshold
- **THEN** the result MUST carry an explicit indication of that
- **AND** the result MUST NOT be indistinguishable from a supported-language result

#### Scenario: A consumer ignores the indication
- **WHEN** a consumer renders a below-threshold result
- **THEN** the indication MUST be available to that consumer
- **AND** the system MUST NOT rely on transcript content to convey the limitation

### Requirement: The language used is selectable and always recorded
The system MUST accept an explicitly stated language for transcription and MUST use
automatic detection only when no language is stated. The language actually used
MUST be recorded on the result.

#### Scenario: A caller states the language
- **WHEN** transcription is requested with an explicit language
- **THEN** that language MUST be used
- **AND** it MUST be recorded on the result

#### Scenario: Speech mixes languages
- **GIVEN** audio in one language carrying terms from another
- **WHEN** it is transcribed
- **THEN** the language actually used MUST be recorded
- **AND** a consumer MUST be able to determine which language was assumed

### Requirement: Speech processing requires AI-feature authorisation and records its engine
Installing or configuring the runner MUST NOT by itself authorise speech
processing; it MUST additionally be enabled as a registered AI feature. Every
invocation record MUST identify the engine used.

#### Scenario: The AI feature is disabled
- **GIVEN** the speech runner is installed and configured
- **AND** the speech AI feature is not enabled
- **WHEN** transcription or synthesis is requested
- **THEN** the system MUST refuse
- **AND** no audio MUST be processed

#### Scenario: The AI feature is enabled
- **GIVEN** the speech AI feature is enabled
- **WHEN** transcription or synthesis is requested
- **THEN** the request MUST proceed
- **AND** the invocation record MUST identify the engine used

### Requirement: Neither audio nor transcript content enters the invocation record
Invocation records MUST carry duration, language, engine and outcome. They MUST NOT
carry audio bytes or transcript text.

#### Scenario: An operator reviews speech activity
- **WHEN** speech invocation records are reviewed
- **THEN** each record MUST show duration, language, engine and outcome
- **AND** no record MUST contain audio or the words that were spoken

### Requirement: A transcript written as a file is marked as agent-authored
When a transcript is written as a file, the system MUST mark that file as
agent-authored in the same operation that writes it, per ADR-088.

#### Scenario: A transcript is saved
- **WHEN** a transcript is written as a file
- **THEN** the file MUST carry the agent-authored tag from the moment it is visible
- **AND** a failure to apply the tag MUST be reported as a failed operation
