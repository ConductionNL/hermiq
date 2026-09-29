# Tasks: chat-speak-and-listen

Kind: code. Size S. Rows `hermiq:ch-voice-in`, `hermiq:ch-voice-out`, `hermiq:dl-transcribe`.

### Task 1: The speech API module
- **spec_ref**: `openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001`
- **files**: `src/api/speech.js`
- **acceptance_criteria**: GIVEN a blob WHEN transcribe() runs THEN it posts multipart field `audio` to `/apps/hermiq/api/speech/transcriptions` and returns `{text}`
- [ ] Implement
- [ ] Test (a unit test on the request shape)

### Task 2: Dictate on the chat page
- **spec_ref**: `openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-offers-dictation-req-chvoice-001`
- **files**: `src/components/DictateButton.vue`, `src/views/Chat.vue`
- **acceptance_criteria**: GIVEN an agent with dictation not off and the service available WHEN the person records and stops THEN the transcript is in the message box and nothing was sent
- [ ] Implement
- [ ] Test (component test; eslint; test:l10n)

### Task 3: Read aloud on answers
- **spec_ref**: `openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-reads-answers-aloud-req-chvoice-002`
- **files**: `src/components/ReadAloudButton.vue`, `src/views/Chat.vue`
- **acceptance_criteria**: GIVEN an answer WHEN "Read aloud" is pressed THEN `/api/speech/speech` is called with the plain text and the audio plays; pressing again stops it
- [ ] Implement
- [ ] Test (component test)

### Task 4: Follow the agent's speech policy
- **spec_ref**: `openspec/changes/chat-speak-and-listen/specs/speech-services/spec.md#requirement-the-chat-page-follows-the-agents-speech-policy-req-chvoice-003`
- **files**: `src/views/Chat.vue`
- **acceptance_criteria**: GIVEN an agent with voiceInputEngine off WHEN the page renders THEN no microphone; GIVEN capabilities unavailable THEN no microphone and no read aloud
- [ ] Implement
- [ ] Test (component test)
