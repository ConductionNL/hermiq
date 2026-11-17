# Design: chat-translate-a-recording

Kind: code. Size M. A new service chaining `SpeechClient` and `ProviderFactory`, one route, one modal, a voice map in admin settings, one optional `SessionTurn` property, an `AiFeature` seed and a TaskProcessing provider behind a class check. The schema property is incidental, so the change is `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- `lib/Controller/SpeechController.php:76` 12 MB cap, `:124-177` `transcribe()` reading upload field `audio` and an optional `language`, `:193-234` `synthesise()`, `:253` `capabilities()`.
- `lib/Service/Speech/SpeechClient.php:61-75` config keys, `:82` loopback default URL, `:160-185` `transcribe()` returning `text`, `language`, `engine`, `:199-214` `synthesise()` with voice `af_heart`, `:223` `isAvailable()`.
- `lib/TaskProcessing/AudioToTextProvider.php:68-94` id `hermiq:audio2text`, name, task type; `:231` `process()` taking the `File` node Nextcloud passes (the `speech-services` spec, "TaskProcessing providers accept the input Nextcloud passes").
- `lib/AppInfo/Application.php:342-353` provider registration.
- `lib/Service/Llm/ProviderFactory.php:587` `createChatDriver()` with `aiFeature`; `:2417` `generateText()` without one.
- `lib/Service/NcNative/AgentArtefactMarker.php:122` `markFile()`.
- `lib/Repair/SeedAiFeatures.php:160-198` seeded features.
- `openspec/specs/speech-services/spec.md:25` "No audio leaves the instance", `:45` "A local-pinned agent never falls back to a cloud engine".
- Nextcloud server at 175c7e6be47 (read only): `lib/public/TaskProcessing/TaskTypes/AudioToAudioTranslate.php:20,26` (`@since 35.0.0`, `core:audio2audio:translate`, inputs `input`, `origin_language`, `target_language`, output `audio_output`); `TextToTextTranslate.php:20,26` (`@since 30.0.0`).

## D1. One chain, three steps, each recorded

`TranslationChain::translate(audio, targetLanguage, sourceLanguage?, userId)`:

1. Transcribe with `SpeechClient::transcribe()`, passing the source language when the person gave one. The detected language comes back from the sidecar.
2. Translate the transcript through `ProviderFactory` under the AI feature `speech-translation`, with a fixed instruction: translate from the detected language into the target language, keep names and numbers, add nothing. `generateText()` gains an optional `aiFeature` argument so the feature's binding, model policy and required residency apply before the call.
3. Speak the translation with `SpeechClient::synthesise()` and the voice mapped to the target language (D3).

The result records `{ sourceLanguage, detectedLanguage, targetLanguage, sttEngine, translationProvider, translationModel, ttsEngine, ttsVoice }`. That record is stored on the chat turn as `translation`, with the transcript and translated text in the turn content and the audio file id.

Rejected: letting the agent do it by prompt, "translate what I just said". That is what exists today and what the row calls partial: the languages are not recorded, the speech policy is per agent rather than per act, and nothing produces an audio file.

## D2. Where the text goes is visible and governed

Transcription and speech run on the instance's own sidecar, which has no route off the host. The translation is the only step that may leave the instance, and only when the organisation's binding for `speech-translation` names a hosted provider. The modal says where it will run before the person starts: "Translation runs on Qwen3 on this server." or "Translation runs on GPT-4o (OpenAI), outside the EU." An administrator who wants every step on the instance sets the feature's required residency to `on-premise`, and a run that would break it is refused before the transcript is sent (`a-provider-and-a-place-per-ai-feature`).

The feature is seeded like the others, with `lifecycle: disabled`, so a DPO acknowledges it before anyone can use it.

## D3. A voice per language, set by an administrator

The admin settings get a map from language code to a voice id of the configured speech model, stored as `IAppConfig` `speech_voices`, for example `{ "en": "af_heart", "fr": "ff_siwis" }`. The modal offers every language the translation model can target, and marks the ones without a voice "text only". For a target without a voice the chain stops after step 2 and the turn says "There is no voice for Nederlands on this server. The translation is shown as text."

Rejected: guessing a voice from its id prefix. Voice ids are a model's naming convention, not a contract.

## D4. The result is a file in Files, marked

The spoken translation is saved as a WAV file in the person's Files under `Hermiq/Translations/`, named after the source and the target language, and tagged "Agent authored" with `AgentArtefactMarker::markFile()`. If the tag fails, the file is deleted and the action reports failure (hydra ADR-088, decisions 5 and 6). A recording the person made in the modal is not kept unless they tick "Keep my recording in Files".

## D5. The chat action and the route

`POST /api/speech/translations`, `#[NoAdminRequired]`, rate limited like `transcribe()`. It takes either an upload in field `audio` (12 MB cap, as today) or a `fileId` read in the caller's own Files, plus `targetLanguage` and an optional `sourceLanguage`. It answers with the transcript, the translation, the audio file id and the `translation` record.

On `/chat` the composer menu gets "Translate a recording", which opens `src/modals/TranslateRecordingModal.vue`: record with the microphone or choose from Files, choose the target language with an `NcSelect` that has an `inputLabel`, and start. The result is added to the open session as a user turn "Translate recording.m4a into English" and an assistant turn with the transcript, the translation, a player and a line "Nederlands (detected) to English. Transcribed and spoken on this server, translated by Qwen3 on this server."

## D6. The TaskProcessing provider waits for the task type

`AudioToAudioTranslateProvider` implements `ISynchronousProvider` for `core:audio2audio:translate`, reading `input`, `origin_language` and `target_language`, and returning `audio_output`, using the same chain with the recursion guard that stops a hermiq provider from calling TaskProcessing again (`generateText(allowNextcloud: false)`). It is registered in `Application::register()` only when `class_exists(AudioToAudioTranslate::class)`, so it is present on Nextcloud 35 and later and absent without error on 32 to 34.

Rejected: registering a provider for `core:text2text:translate` in the same change. That task type exists on every supported version, but it is text translation, a different row; offering it would also place hermiq in the Assistant's text translate menu, which is a product decision of its own.

## Declarative versus imperative

`SessionTurn.translation` is declared in `lib/Settings/hermiq_register.json` with a register version bump. The chain is external integration work (the sidecar, a model, Files, a tag), which hydra ADR-031 leaves imperative. The `speech-translation` feature is a seeded object, not a schema change.

## Seed data

- `AiFeature`: `slug` `speech-translation`, `name` "Translate a recording", `riskCategory` `limited`, `lifecycle` `disabled`, `description` "Transcribes a recording on this server, translates the text with the model bound to this feature, and speaks the translation. The spoken result is saved in the requester's Files and tagged Agent authored."
- Example `speech_voices` in the admin documentation, not seeded, since voice ids depend on the installed speech model.

## Risks

- Whisper's language detection is wrong on a short clip. Mitigation: the modal offers a source language, and the result names the detected language so a wrong guess is visible.
- A long recording times out. Mitigation: the 12 MB cap of `transcribe()`, and the sidecar's 900 second timeout (`SpeechClient.php:120`); a longer recording is refused with its size in the message.
- The translation adds or drops content. Mitigation: the transcript and the translation are shown side by side, so a person can compare them.
