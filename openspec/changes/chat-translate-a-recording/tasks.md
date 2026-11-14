# Tasks: chat-translate-a-recording

Kind: code. Size M. Row `hermiq:dm-speech-translate`.

## Implementation tasks

### Task 1: The translation chain and its record
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-languages-and-engines-used-are-recorded-req-strn-002`
- **files**: `lib/Service/Speech/TranslationChain.php`, `lib/Service/Speech/SpeechClient.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN an Arabic recording and target `nl` WHEN the chain runs THEN it returns transcript, translation, audio and a record naming both languages and all three engines
  - GIVEN no voice for the target WHEN the chain runs THEN it stops after the translation and says so
- [ ] Implement
- [ ] Test (PHPUnit with a fake sidecar and a fake provider)

### Task 2: The feature, its residency and the pre-start notice
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-the-translation-step-follows-the-features-provider-rules-req-strn-003`
- **files**: `lib/Repair/SeedAiFeatures.php`, `lib/Service/Llm/ProviderFactory.php`, `lib/Controller/SpeechController.php`
- **acceptance_criteria**:
  - GIVEN `speech-translation` requires `on-premise` and binds a hosted provider WHEN a translation starts THEN it is refused before any text is sent
  - GIVEN the feature is not acknowledged WHEN a translation starts THEN it is refused with the feature named
- [ ] Implement
- [ ] Test (PHPUnit on `generateText()` with an AI feature, and on the refusal order)

### Task 3: The route, the saved file and its mark
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001`
- **files**: `appinfo/routes.php`, `lib/Controller/SpeechController.php`, `lib/Settings/hermiq_register.json`
- **acceptance_criteria**:
  - GIVEN a `fileId` the caller can read WHEN posted with a target THEN a WAV tagged "Agent authored" is saved under `Hermiq/Translations`
  - GIVEN a `fileId` the caller cannot read WHEN posted THEN the answer is 404
- [ ] Implement
- [ ] Test (PHPUnit; Newman with an upload and with a Files id)

### Task 4: The voice map in admin settings
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001`
- **files**: `lib/Controller/Settings/SpeechVoiceSettingsController.php`, `appinfo/routes.php`, `src/views/AdminRoot.vue`, `src/modals/SpeechVoiceMapModal.vue`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they map `fr` to a voice THEN `speech_voices` holds it, and a non-admin gets 403 on the route
- [ ] Implement
- [ ] Test (PHPUnit on the controller auth; Playwright on the modal)

### Task 5: The chat action and the result turn
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-a-person-can-translate-a-recording-and-hear-the-result-req-strn-001`
- **files**: `src/modals/TranslateRecordingModal.vue`, `src/views/Chat.vue`, `src/api/speech.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a session on `/chat` WHEN a person translates a file from Files THEN two turns appear with transcript, translation, player and the languages line
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/speech-translate.spec.ts` with the sidecar stubbed)

### Task 6: The TaskProcessing provider behind a class check
- **spec_ref**: `openspec/changes/chat-translate-a-recording/specs/speech-services/spec.md#requirement-other-apps-can-use-the-chain-through-taskprocessing-where-nextcloud-defines-it-req-strn-004`
- **files**: `lib/TaskProcessing/AudioToAudioTranslateProvider.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the task type class is absent WHEN the app registers THEN no provider is registered and nothing throws
  - GIVEN it is present WHEN a task runs with a `File` input THEN `audio_output` holds the spoken translation
- [ ] Implement
- [ ] Test (PHPUnit with the class present and absent)

## Verification
- [ ] `openspec validate chat-translate-a-recording --type change --strict` passes
- [ ] PHPUnit, Newman and the Playwright file run, exit codes read
- [ ] A live check on the dev instance with the speech sidecar: translate a short English clip into French and play it
