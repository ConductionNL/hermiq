---
kind: code
---

# Proposal: chat-translate-a-recording

## Summary

On `/chat` you can choose "Translate a recording", record something or pick an audio file from Files, choose the language you want, and hear the translation. hermiq transcribes the recording on the instance's own speech service, translates the text with the model the organisation allows for this feature, and speaks the result with a voice for that language. The chat shows the transcript, the translation and a player, and records which languages and which engines were used. On Nextcloud 35 and later hermiq also offers the same chain to other apps as a TaskProcessing provider for audio translation.

## Why

One row of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:dm-speech-translate` | partial, built | build: changelog demand and two competitors rate yes for the missing half, a translate action on a recording |

Demand row:

- `dm-speech-translate`: changelog https://github.com/nextcloud/assistant/pull/601

Competitor cells rated yes, quoted from the matrix:

- Nextcloud Assistant: "the Assistant registers an audio-to-audio translate provider (assistant:lib/TaskProcessing/AudioToAudioTranslateProvider.php:32-43) chaining transcription, translation and speech."
- n8n: "packages/@n8n/nodes-langchain/nodes/vendors/OpenAi/v2/actions/audio/index.ts:22-33 'Transcribe a Recording' and 'Translate a Recording' (translate.operation.ts:74 POST /audio/translations), plus 'Generate Audio' (generate.operation.ts) to hear the result".

The matrix note on the built half: "A person can speak, ask the agent to translate and hear the reply; there is no action that translates a recording."

## What hermiq already has

- `SpeechController` offers synchronous transcription and synthesis to the composer, `#[NoAdminRequired]` and rate limited (`lib/Controller/SpeechController.php:124-126`, `:193-195`, `:253-255`; routes at `appinfo/routes.php:540-542`), with a 12 MB audio cap (`:76`).
- `SpeechClient::transcribe()` returns the text, the language actually used and the engine (`lib/Service/Speech/SpeechClient.php:160-185`); `synthesise()` takes a voice id, default `af_heart` (`:199-214`). The sidecar serves faster-whisper and Kokoro on the loopback address only (`:1-30`).
- TaskProcessing providers `hermiq:audio2text` and text-to-speech for other apps (`lib/TaskProcessing/AudioToTextProvider.php:68-94`, `:231`, `lib/AppInfo/Application.php:348-349`).
- The per-agent speech policy: dictation and spoken replies, each `auto`, `browser`, `local` or `off` (`src/modals/AgentFormModal.vue:250-304`; the `speech-services` spec, "No audio leaves the instance" and "A local-pinned agent never falls back to a cloud engine").
- `ProviderFactory::generateText()` generates text with the organisation's model policy (`lib/Service/Llm/ProviderFactory.php:2417`); it takes no AI feature today, so residency cannot be required for it.
- Nextcloud core defines `core:audio2audio:translate` (`OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate`, `@since 35.0.0`) and `core:text2text:translate` (`@since 30.0.0`), read in the Nextcloud server tree at 175c7e6be47. hermiq supports Nextcloud 32 to 34 (`appinfo/info.xml:46`).

## What this change builds

1. `TranslationChain`: transcribe on the speech sidecar, translate the text through the LLM provider layer under a new AI feature `speech-translation`, speak the translation with the voice mapped to the target language, and save the audio in the person's Files, marked as agent-authored.
2. `POST /api/speech/translations`: the chain for one recording, given as an upload or as a Files id, with a target language and an optional source language.
3. "Translate a recording" in the Chat page composer menu, with a modal to record or pick a file and pick the language, and a result turn that shows the transcript, the translation, a player, and the languages and engines used.
4. An administrator's map from language to voice. A target language without a voice gets the written translation and says it cannot be spoken.
5. A TaskProcessing provider for `core:audio2audio:translate`, registered only when that task type exists, which is Nextcloud 35 and later.

## Out of scope

- Live interpreting of a conversation. This change translates a finished recording.
- A translation engine of hermiq's own. The text is translated by a model the organisation already allows.
- Subtitles for a video (`core:audio2text:subtitles`). A different output, and a different change.
- Raising hermiq's supported Nextcloud range. The provider waits for 35; `raise-nc-minversion-taskprocessing` owns the range.
