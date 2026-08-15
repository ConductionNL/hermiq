# Tasks — speech-services

## 1. Speech runner sidecar

- [ ] 1.1 Add a speech runner under `exapp/`, deployed like the existing `exapp/llm-runner`: Whisper large-v3-turbo for transcription and Kokoro for synthesis, both local. Ollama is NOT used — it has no audio input path. Keep the synthesis engine behind an interface so Piper can replace Kokoro without touching anything above the runner.
- [ ] 1.2 Pin the exact model identifiers and versions in the runner's configuration; the language matrix in task 3 is measured against these pins and is invalid for any other version.
- [ ] 1.3 Confirm by observed egress — not by reading configuration — that no audio or transcript leaves the instance.

## 2. TaskProcessing providers

- [ ] 2.1 Bump `nextcloud/ocp` in `composer.json` to a version carrying `OCP\TaskProcessing\TaskTypes\TextToSpeech` (`core:text2speech`); the currently vendored copy has `AudioToText` but not `TextToSpeech`.
- [ ] 2.2 Add an `AudioToText` TaskProcessing provider registered in `lib/AppInfo/Application.php`, following the existing `Text2TextProvider` registration pattern.
- [ ] 2.3 Add a `TextToSpeech` TaskProcessing provider, registered the same way.
- [ ] 2.4 Accept an explicit language on transcription; use auto-detection only as fallback; record the language actually used on every result.

## 3. The language matrix

- [ ] 3.1 Run a real benchmark (Common Voice / Fleurs) for each of the 24 official EU languages against the pinned Whisper model, recording measured WER, dataset name, dataset version and run date. Do NOT transcribe figures from a model card.
- [ ] 3.2 Publish the result as a versioned declaration in the app, keyed to the pinned model version, with a derived support level per language, using 15% measured WER as the below-threshold boundary. Declare coverage PER DIRECTION — transcription is measured across 24 EU languages, Kokoro synthesises 8 — and never present one figure as covering both.
- [ ] 3.3 Treat any language absent from the matrix as UNSUPPORTED — never as supported-by-vendor-claim.
- [ ] 3.4 Implement the below-threshold behaviour (see DEFERRED_QUESTIONS 1 and 2) so a low-quality language is declared to the caller rather than silently returned as an ordinary transcript.

## 4. Governance

- [ ] 4.1 Gate both providers on an explicitly enabled AI feature in Hermiq's AI-feature governance register; installing the runner MUST NOT by itself enable speech processing.
- [ ] 4.2 Record engine identity and the language used on every invocation record; assert no audio bytes and no transcript text reach it.
- [ ] 4.3 Apply the ADR-088 agent-authored tag to any transcript written as a file, in the same operation that writes it.

## 5. Verify

- [ ] 5.1 Negative control on the language gate: a language declared unsupported produces the declared refusal or low-confidence flag — proven by running it, not by reading the branch. Positive control: a supported language returns an ordinary result.
- [ ] 5.2 Negative control on the AI-feature gate: with the feature disabled both providers refuse even when otherwise configured; enabled, both succeed. Run both halves.
- [ ] 5.3 Assert both providers appear in Nextcloud's TaskProcessing provider list and are invocable from a TaskProcessing consumer other than Hermiq.
- [ ] 5.4 Assert a code-switched sample (Dutch carrying English technical terms) records the language actually used, so a wrong-language transcript is diagnosable.
- [ ] 5.5 Assert the invocation record contains engine and language and contains no audio and no transcript text.
- [ ] 5.6 Scoped `phpcs` clean; zero new PHPUnit failures vs a self-measured baseline; CHANGELOG entry recording the measured language matrix and its model pin.

## Acceptance criteria

- Speech-to-text and text-to-speech are available through Nextcloud TaskProcessing, so any consumer gets them without depending on Hermiq directly.
- No audio leaves the instance, confirmed by observed egress.
- Per-language support is a measured, dated, model-pinned declaration; an unmeasured language is unsupported, and a below-threshold language is declared to the caller rather than returned as an ordinary transcript.
- The language used is recorded on every result, so a mis-detection is diagnosable rather than invisible.
- Speech processing is refused unless the AI feature is enabled, proven by a disabled-then-enabled control pair.
- Invocation records carry engine and language and neither audio nor transcript text.
