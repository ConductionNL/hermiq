---
kind: code
depends_on: []
---

# Proposal: chat-speak-and-listen

## Summary

On hermiq's own `/chat` page a person can press a microphone button, speak, and see the words appear in the message box, ready to read and send. Every answer from the agent has a "Read aloud" button that speaks it. Both follow the agent's speech policy: an agent whose dictation or spoken replies are switched off shows no button, and the page only offers the on-instance engine when the speech service answers.

## Why

Three rows of hermiq's capability matrix that are `building`: the endpoints exist, the chat page does not use them. Decided in the build-all pass of 2026-09-28 under the rule "two or more competitors rate yes, or the core area".

| row | own rating | decision |
|---|---|---|
| `hermiq:ch-voice-in` | partial, building | build: core area (chat) and six competitors rate yes |
| `hermiq:ch-voice-out` | partial, building | build: core area (chat) and five competitors rate yes |
| `hermiq:dl-transcribe` | no, building | build: five competitors rate yes |

Competitor cells rated yes, quoted from the matrix evidence:

- `ch-voice-in`, Nextcloud Assistant v4.0.0: "microphone recording in the chat input (assistant:src/components/ChattyLLM/InputArea.vue:57-71, fields/AudioRecorderWrapper.vue:137-151)". Open WebUI v0.11.4: "src/lib/components/chat/MessageInput/VoiceRecording.svelte dictation". n8n 2.40.7: "ChatPrompt.vue:4,53 useSpeechRecognition turns speech into the prompt".
- `ch-voice-out`, Open WebUI v0.11.4: "src/lib/components/chat/Messages/ResponseMessage.svelte:1122 'Read Aloud'". n8n 2.40.7: "ChatMessageActions.vue:17-19,64,72 'Read aloud' action on AI messages". Dify 1.17.1: "api/controllers/web/audio.py:119 POST /text-to-audio".
- `dl-transcribe`, Hermes Agent v2026.9.24: "gateway/run_voice.py transcribes inbound voice messages on messaging platforms". Dify 1.17.1: "api/controllers/web/audio.py:62 audio-to-text for voice messages in chat". Open WebUI v0.11.4: "routers/audio.py:1251 POST /audio/transcriptions".

The matrix note on the built half: "speaking to an agent works through the companion panel from nextcloud-vue 2.55.1, backed by hermiq's transcription endpoint. hermiq's own chat page still has no microphone."

## What hermiq already has

- `SpeechController::transcribe()`, `synthesise()` and `capabilities()` (`lib/Controller/SpeechController.php:126`, `:195`, `:255`; routes `appinfo/routes.php:540-542`), rate limited, 12 MB cap, the transcript never logged.
- The per-agent speech policy on the Agent schema: `voiceInputEngine` and `voiceOutputEngine` (`auto`, `browser`, `local`, `off`), edited in `src/modals/AgentFormModal.vue:250-304`.
- The speech-services spec requirements "Dictation does not send by itself" and "The microphone control states what is happening".
- `src/views/Chat.vue:415-450`: a hand-rolled composer (`<textarea>` plus a send button) with no microphone, and assistant messages with a feedback row (`:299`) but no read-aloud action. No file under `src/` calls `/api/speech/*`.

## What this change builds

1. `src/api/speech.js`: `speechCapabilities()`, `transcribe(blob, language)` and `synthesise(text)` against the three existing routes.
2. A `DictateButton` component beside the send button: records with `MediaRecorder`, shows "Listening" while recording and "Transcribing" while waiting, puts the transcript into the message box and never sends it.
3. A "Read aloud" action on each assistant message that plays the synthesised audio and can be stopped.
4. Policy: the buttons show only when the selected agent's `voiceInputEngine` or `voiceOutputEngine` is not `off` and `capabilities()` reports the on-instance service available. A `browser` or `auto` agent uses the on-instance engine on this page; the browser engine stays the companion's.

## Out of scope

- A live two-way voice conversation mode.
- Translating a recording: that is `chat-translate-a-recording`.
- Changes to the companion in nextcloud-vue.
