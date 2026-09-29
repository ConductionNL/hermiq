# Design: chat-speak-and-listen

Read at hermiq development c91e637a.

## Where it lives

- `src/views/Chat.vue` composer (`:415-450`) and the assistant message block (`:299`, the feedback row). The page already loads the session's agent into `currentAgent` (`loadAgentFor()`, `:1078`), which carries `voiceInputEngine` and `voiceOutputEngine` from the Agent object.
- New `src/components/DictateButton.vue` and `src/components/ReadAloudButton.vue`, so Chat.vue only places them. No modal is involved (ADR-004 modal isolation does not apply).
- New `src/api/speech.js`, stateless functions like `src/api/memory.js`.
- No PHP change. The three endpoints exist and carry their own auth and rate limits.

## Decisions

- D1. On-instance only on this page. `capabilities()` reaches the sidecar; when it answers `available: false` the buttons are not rendered, and a tooltip-free absence is preferred over a disabled control that cannot explain itself. A `local` agent never falls back to the browser recogniser, which the speech-services spec already requires; this page simply never uses the browser engine.
- D2. The engine policy is read from the agent: `off` hides the control; any other value shows it.
- D3. Recording format: `MediaRecorder` with the browser's default container (webm/opus in Chrome and Firefox), uploaded as multipart field `audio` exactly as `transcribe()` reads it (`getUploadedFile(key: 'audio')`). The sidecar's faster-whisper reads webm.
- D4. The transcript is appended to `currentMessage`; the person sends it. This is the requirement "Dictation does not send by itself".
- D5. Read aloud posts the message text (markdown stripped) to `/api/speech/speech`, receives WAV, plays it through one shared `Audio` element so starting a second message stops the first. Text over the controller's `MAX_SPEAK_CHARS` (4000, `lib/Controller/SpeechController.php:86`) is refused by the server; the button then shows the error "This answer is too long to read aloud."
- D6. User-facing strings go through `t('hermiq', ...)` and into every locale the repo ships (`l10n/`), checked by `npm run test:l10n`.

## Risks

- Microphone permission denied: the button shows "Microphone access was refused" and stays usable.
- Browsers without `MediaRecorder`: the dictate button is not rendered.
