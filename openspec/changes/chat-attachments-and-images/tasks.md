# Tasks: chat-attachments-and-images

Kind: code. Size L. Rows `hermiq:ch-attach`, `dm-image-chat`, `ch-image-gen`, `dm-native-pdf`.

## Implementation tasks

### Task 1: The attachments route stores the upload in Files
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-the-companions-attach-control-has-a-route-to-call-req-catt-001`
- **files**: `appinfo/routes.php`, `lib/Controller/ChatAttachmentController.php`, `lib/Service/Chat/AttachmentStore.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a logged-in person WHEN they post a PDF as field `file` THEN the answer is 200 with `path`, `name`, `fileId`, `mimeType`, `size`, and the file is in their Files under `Hermiq/Attachments`
  - GIVEN a file over the cap or of a refused type WHEN it is posted THEN the answer is 400 with `error`, and nothing is written
- [x] Implement (size cap: app config `hermiq` / `chat.attachmentMaxMb`, default 20)
- [ ] Test (PHPUnit on the controller and store: done, AttachmentStoreTest and ChatAttachmentControllerTest; Newman upload with an allowed and a refused file: not written yet, it needs a multipart fixture the CI Newman job can reach)

### Task 2: Upload from device and choose from Files on the Chat page
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-person-can-attach-a-file-they-already-have-in-files-req-catt-002`
- **files**: `src/views/Chat.vue`, `src/api/chat.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a session open on `/chat` WHEN the person chooses a file from Files and sends THEN the turn carries its file id and no copy is made
- [x] Implement (attach menu on the composer: "Upload from device" through the route of task 1, "Choose from Files" through the Nextcloud file picker, sent by file id; the thread shows a turn's attachments, which the session messages endpoint now returns)
- [ ] Test (Playwright under `tests/e2e/spec-coverage/chat-attachments.spec.ts`: written, not yet run; it needs the live instance)

### Task 3: Attachments on the turn, resolved as the speaker
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003`
- **files**: `lib/Settings/hermiq_register.json`, `lib/Controller/ChatController.php`, `lib/Controller/ChatStreamController.php`, `lib/Service/Engine/Engine.php`, `lib/Service/Engine/MessageHistoryHandler.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN a shared session WHEN a participant names a file id only another person can read THEN the turn is refused and the model is not called
  - GIVEN a feature that requires redaction WHEN an unredacted file is attached THEN the run is refused before the provider call
- [x] Implement (TurnAttachmentResolver reads each file as the turn's speaker; ProviderFactory::createChatDriver runs enforceForRun once per attachment; register 0.49.0, SessionTurn 0.4.0)
- [ ] Test (PHPUnit on resolution and the per-attachment enforcement: done, TurnAttachmentResolverTest, EngineAttachmentsTest, ProviderFactoryAttachmentsTest, ResponseGenerationHandlerTest, MessageHistoryHandlerTest, ChatControllerTest; Newman with a foreign file id: not written yet, it needs a second user and a file id the CI Newman job can reach)

### Task 4: Declared input capabilities per model
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004`
- **files**: `lib/Service/Llm/ModelCapabilityRegistry.php`, `lib/Controller/Settings/LlmSettingsController.php`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN an administrator in the LLM provider settings WHEN they tick "Reads images" for a model THEN `hermiq.modelCapabilities` holds it, and an undeclared model reports no capability
- [x] Implement (ModelCapabilityRegistry keyed `provider/model`, split on the first slash; GET/PATCH `/api/settings/llm` carry `modelCapabilities`, validated before and written after the llm save; "Reads images" and "Reads PDFs" in the provider modal for the typed model)
- [ ] Test (PHPUnit on the registry and the endpoint: done, ModelCapabilityRegistryTest and LlmSettingsModelCapabilitiesTest; Playwright `tests/e2e/spec-coverage/chat-attachments-model-capabilities.spec.ts`: written, not yet run, it needs the live instance)

### Task 5: Native image and document parts per driver
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-that-reads-images-or-pdfs-natively-gets-them-natively-req-catt-004`
- **files**: `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Service/Llm/ProviderFactory.php`
- **acceptance_criteria**:
  - GIVEN a declared vision model on each of anthropic, openai, ollama and fireworks WHEN a turn carries an image THEN the request body carries it in that provider's image part shape
  - GIVEN a declared PDF model on anthropic or openai WHEN a turn carries a PDF THEN the body carries a document part
- [x] Implement (AttachmentPartBuilder decides per attachment: a type that maps to `image` or `pdf`, a driver that can carry it (PDFs on anthropic and openai only; the Anthropic CLI transport carries text), and a model declared for it; the bytes are read as the speaker. AttachmentMessage gives the OpenAI parts, Ollama's `images` and Anthropic's blocks; ResponseGenerationHandler builds the user turn with it and Engine hands on the speaker)
- [x] Test (PHPUnit per driver on the request body: NativeAttachmentPartsBodyTest drives LLPhant's real OpenAIChat and OllamaChat and ProviderFactory's Anthropic and Fireworks mappings; AttachmentPartBuilderTest, ResponseGenerationNativePartsTest, EngineAttachmentsTest)

### Task 6: The text fallback and the notice
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005`
- **files**: `lib/Service/Chat/AttachmentTextReader.php`, `lib/Service/Engine/ResponseGenerationHandler.php`, `lib/Controller/ChatStreamController.php`, `src/views/Chat.vue`
- **acceptance_criteria**:
  - GIVEN a model without `pdf` WHEN a PDF is attached THEN its extracted text is used and `final.attachmentNotices` names the file
  - GIVEN OpenRegister without the text facade WHEN a PDF is attached THEN the file is left out and the notice says hermiq could not read it
- [ ] Implement (built except the facade adapter: AttachmentTextReader reads text files as the speaker, capped at readFile's 20000 characters; a PDF or office file goes through the `AttachmentTextSource` seam, which nothing implements yet because OpenRegister has no public text-extraction facade and design D6 names no class, so today such a file is left out with "hermiq could not read the text of ..."; an image gets "This model cannot see images. ... was not sent."; the notices ride on `final.attachmentNotices` via AttachmentPartBuilder::compose(), ResponseGenerationHandler::$attachmentNotices and the Engine result, and the Chat page shows them above the answer. Tick once an adapter over OpenRegister's facade is registered for `AttachmentTextSource`)
- [ ] Test (PHPUnit with the facade present and absent: done, AttachmentTextReaderTest, ResponseGenerationTextFallbackTest, EngineAttachmentsTest, ChatStreamControllerTest; Playwright for the visible notice in `tests/e2e/spec-coverage/chat-attachments.spec.ts`: written with a stubbed `final` frame, not yet run, it needs the live instance)

### Task 7: Image creation service and the governed tool
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-is-saved-in-files-and-marked-as-agent-authored-req-cimg-002`
- **files**: `lib/Service/Chat/ImageGenerationService.php`, `lib/Mcp/HermiqToolProvider.php`, `lib/Repair/SeedAiFeatures.php`
- **acceptance_criteria**:
  - GIVEN a text-to-image provider WHEN `hermiq.generateImage` runs for a granted agent THEN a PNG is saved in the person's Files, tagged "Agent authored", and the trace step holds its file id
  - GIVEN a failing tag mapper WHEN the tool runs THEN the file is deleted and the tool returns an error
  - GIVEN no text-to-image provider WHEN the tool catalog is built THEN the tool is absent
- [x] Implement (ImageGenerationService runs TextToImage through IManager::runTask for the person, writes the PNG under `Hermiq/Generated images`, marks it with AgentArtefactMarker and deletes it when marking fails; it refuses unless the `image-generation` AiFeature is enabled. `hermiq.generateImage` (ImageToolDescriptors, scope `create`, reach user) is listed only while a provider is available and routes to the service as the session user; its result holds the file id, path and agent id, never the image. SeedAiFeatures seeds `image-generation` disabled)
- [x] Test (PHPUnit with a fake TaskProcessing manager and a failing tag mapper: ImageGenerationServiceTest over the real AgentArtefactMarker; HermiqToolProviderTest for absent without a provider and routed as the session user; SeedAiFeaturesTest; ToolLoopTest::testTheImageToolIsOfferedOnlyWithItsGrant pins REQ-CIMG-003's grant filter: an agent without the grant is never offered the tool, one with it is)

### Task 8: The chat action and images in the answer
- **spec_ref**: `openspec/changes/chat-attachments-and-images/specs/image-generation/spec.md#requirement-a-created-image-shows-in-the-answer-req-cimg-004`
- **files**: `appinfo/routes.php`, `lib/Controller/ChatImageController.php`, `src/modals/GenerateImageModal.vue`, `src/views/Chat.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature enabled and a provider installed WHEN a person chooses "Create an image" and submits a description THEN an assistant turn with the image thumbnail appears
- [ ] Implement
- [ ] Test (Playwright under `tests/e2e/spec-coverage/image-generation.spec.ts` with a stub text-to-image provider)

## Verification
- [ ] `openspec validate chat-attachments-and-images --type change --strict` passes
- [ ] PHPUnit, Newman and the two Playwright files run, exit codes read
- [ ] A live check on the dev instance: attach a PDF from the companion and see the upload return 200 instead of 404
