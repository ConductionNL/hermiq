# Design: chat-attachments-and-images

Kind: code. Size L. The chat routes and controllers, the engine's message build, the four chat drivers in `ProviderFactory`, `SessionTurn`, the Chat page composer, one new tool and one new TaskProcessing consumer. The `SessionTurn.attachments` property is the only declarative edit; it is incidental to the code, so the change stays `kind: code` under hydra ADR-032.

## Context at development db6b74dc

- Routes: chat send `appinfo/routes.php:514`, stream `:545`, sessions `:566-588`. No attachments route.
- `lib/Controller/ChatController.php:266` `sendMessage()`, `:809` `extractMessageRequestParams()`.
- `lib/Controller/ChatStreamController.php:228-241` reads `agentUuid`, `conversationUuid` and `context` from the body; `:329-338` calls `Engine::processMessage()`; `:378-392` builds the `final` payload.
- `lib/Service/Engine/Engine.php:262` `processMessage()` signature: one `string $userMessage`, plus author id and display name for shared sessions.
- `lib/Service/Engine/ResponseGenerationHandler.php:362-363` adds the system prompt and `LLPhantMessage::user($userMessage)`; `:371-392` the Fireworks and Anthropic branches call the provider over HTTP through `ProviderFactory`.
- `lib/Service/Llm/ProviderFactory.php:587` `createChatDriver()`, `:639` feature enforcement with one `documentReference`, `:2032-2057` `mapHistoryToAnthropicMessages()` casting content to string, `:2339-2344` the `nextcloud` driver running a `TextToText` task.
- `lib/Service/Llm/LlmSettingsHandler.php:51` `ALLOWED_CHAT_PROVIDERS = ['openai', 'ollama', 'fireworks', 'nextcloud', 'anthropic']`.
- `lib/Service/AiFeature/ProviderResidencyRegistry.php:10` stores residency as one `IAppConfig` JSON map keyed by provider.
- `lib/Service/Engine/MessageHistoryHandler.php:98` `buildMessageHistory()` (ten most recent turns), `:292` `storeMessage()`.
- `lib/Settings/hermiq_register.json:1659-1760` `SessionTurn` (`agentsessionturn`, version 0.2.0).
- `lib/Mcp/HermiqToolProvider.php:186` `hermiq.readFile`, `:272` a `create`-scoped tool (`sendMail`) as the pattern for a write tool, `:822-850` `readFile()` reads through `IRootFolder::getUserFolder($uid)`.
- `lib/Service/NcNative/AgentArtefactMarker.php:75` tag name "Agent authored", `:122` `markFile()`.
- `lib/AppInfo/Application.php:348-353` TaskProcessing providers registered; `appinfo/info.xml:46` Nextcloud 32 to 34.
- `src/views/Chat.vue:414-450` composer; `:260-264` message text render.
- nextcloud-vue development at ca0c56e77 (read only): `src/composables/aiChatConfig.js:86-96` the attachments endpoint contract (`{ path, name }`, 400 with `{ error }`), `src/components/CnAiCompanion/CnAiInput.vue:171` a file input with no `accept` filter, `src/composables/useAiChatStream.js:348-349` `body.attachments`.

## D1. The attachments route answers the contract the companion already speaks

`POST /api/chat/attachments`, `#[NoAdminRequired]`, multipart field `file`. The file is written into the caller's own Files under `Hermiq/Attachments/<yyyy-mm>/`, with Nextcloud's normal name-conflict suffix. The response is `{ path, name, fileId, mimeType, size }`. `path` and `name` are what the companion already reads; `fileId` and `mimeType` are additive, so the current widget keeps working and a newer one can use them. A rejection is a 400 with `{ error }` in the caller's language, which `CnAiInput` already shows inline.

Allowed types: text (`text/*`, JSON, CSV, Markdown), images (`image/png`, `image/jpeg`, `image/webp`, `image/gif`) and `application/pdf`. The size cap is an admin setting, default 20 MB. Office documents are accepted for the text fallback only.

Rejected: the contract the companion's docblock describes, text-decode and store in the backend, 20000 bytes, UTF-8 only (`aiChatConfig.js:86-93`). It cannot carry an image or a PDF, and a copy stored by hermiq sits outside Files: the user cannot see it, share rules do not apply to it, and deleting the original leaves it behind.

## D2. A file picked from Files needs no upload

The Chat page gets an attach menu with two items, "Upload from device" and "Choose from Files". The second opens the Nextcloud file picker (`@nextcloud/dialogs`) and sends the chosen file ids straight on the turn. The companion keeps its upload-only control until nextcloud-vue adds a picker; that is its change, not this one.

## D3. A turn holds references, read as the speaker

`SessionTurn` gains `attachments`: an array of `{ fileId, name, mimeType, size, origin }`, where `origin` is `upload`, `files` or `generated`. No bytes and no extracted text are stored on the turn.

At send time the engine resolves each `fileId` through `IRootFolder::getUserFolder(<speaker>)->getById()`. The speaker is the person who sent the turn: the session owner in a private chat, the turn's author in a shared session (`authorId`). It is never the agent's acting user. A file id the speaker cannot read gets the same answer as a missing one, "This file is not available to you", so the route cannot be used to probe which files exist.

Each attachment is also checked by `FeatureProviderResolver::enforceForRun()` as a document reference, one call per file, so a feature that requires redaction refuses an unredacted attachment before any byte reaches a model.

On later turns the history carries a line per earlier attachment ("Attached earlier: offerte-2026.pdf, file 48213"), not the file again. The agent can read it again with `hermiq.readFile` when it needs to. Sending every earlier image on every turn would multiply the cost of a long chat for no gain.

Rejected: reading the file as the agent's acting user. It would let a person attach, by id, a file only the acting user can read.

Rejected: storing the file bytes on the turn. The object store would hold a second copy of the file with other access rules, and removing the file from Files would not remove it from the chat.

## D4. The model's input capabilities are declared, not guessed

An administrator declares, per provider and model, which inputs the model reads natively: `image`, `pdf`, or neither. The map is one `IAppConfig` JSON value, `hermiq.modelCapabilities`, keyed by `provider/model`, the same shape `ProviderResidencyRegistry` uses for residency. It is edited in the LLM provider settings (`src/modals/LlmProviderModal.vue`). An undeclared model has no native capability.

Rejected: inferring capability from a model name (`gpt-4o`, `llava`, `claude-*`). Names are reused, aliases are local, and a guess that is wrong either sends a model an image it drops without a word, or holds back an image a model could read. The residency decision in `a-provider-and-a-place-per-ai-feature` made the same call for the same reason.

## D5. Native parts per driver

The engine turns the user turn into a message with content parts: the text, then one part per attachment the model reads natively.

- `anthropic`: `image` blocks and `document` blocks (base64), built in `mapHistoryToAnthropicMessages()`, which stops casting a turn with parts to a string.
- `openai`: image parts as data URLs and PDF as a file input part.
- `ollama`: the `images` array on the message for a vision model. Ollama takes no PDF part, so a PDF always falls back.
- `fireworks`: image parts on a vision model, over the existing direct HTTP call.
- `nextcloud`: the `TextToText` task has no file input, so every attachment falls back.

Where LLPhant's message type cannot carry parts, that driver sends the turn over the direct HTTP path, the way the Fireworks and Anthropic branches already do (`ResponseGenerationHandler.php:371-392`).

## D6. The fallback says what it did

For an attachment the model cannot read natively:

- a text file is read as text, capped like `readFile`;
- a PDF or an office file is turned into text by OpenRegister's file text extraction, which already has a PDF extractor (`OCA\OpenRegister\Service\TextExtractionService::extractFile()`, openregister development c53dd0685c). That class is internal to OpenRegister, so hermiq asks for a small public facade `extractText(int $fileId): ?string` in the same way the open `vector-rag` change asks for its search facade, and resolves it lazily with a `class_exists()` guard;
- an image has no text fallback. The turn goes ahead without it.

In every case the answer carries a notice the person can read, for example "This model does not read PDFs directly. hermiq used the text of offerte-2026.pdf instead." or "This model cannot see images. photo-dak.jpg was not sent." The notice travels on the `final` frame as `attachmentNotices`, and the Chat page shows it above the answer. No new SSE event type is added (hydra ADR-034 Decision 6).

When the facade is absent, a PDF gets the notice "hermiq could not read the text of this file" and is not sent.

## D7. Image generation consumes Nextcloud TaskProcessing

`ImageGenerationService` runs a `TextToImage` task (`OCP\TaskProcessing\TaskTypes\TextToImage`) for the speaker through `IManager`, when a provider for that task type is available. It writes the result as a PNG into the speaker's Files under `Hermiq/Generated images/`, then calls `AgentArtefactMarker::markFile()`. When marking fails, the file is deleted and the call reports failure (hydra ADR-088, decisions 5 and 6).

Two entry points share the service:

- the tool `hermiq.generateImage` in `HermiqToolProvider`, scope `create`, reach user, default-deny like every other grant (`agent-tool-governance`). Its trace step records the file id and the agent, never the image (ADR-088 decisions 3 and 4);
- the chat action "Create an image", which opens `src/modals/GenerateImageModal.vue` for a description and posts to `POST /api/chat/images`. The result is stored as an assistant turn whose `attachments` holds the generated file with `origin: generated`.

The feature is registered as an `AiFeature` with slug `image-generation`, seeded with `lifecycle: disabled` until the organisation's DPO acknowledges it, like the four features seeded today (`lib/Repair/SeedAiFeatures.php:160-198`). When no text-to-image provider is available, the action is not shown and the tool is not offered.

Rejected: calling an image API such as OpenAI's `images/generations` from hermiq directly. It would be a second image stack beside Nextcloud's, with its own credentials, and the OpenAI integration app already provides a text-to-image provider to TaskProcessing.

## D8. Images in the answer

The Chat page renders an attachment with an image type as a thumbnail through Nextcloud's preview endpoint, linked to the file in Files. The `final` frame of the stream carries `attachments` on the assistant turn, so the companion can render them once nextcloud-vue supports it.

## Declarative versus imperative

`SessionTurn.attachments` is declared in `lib/Settings/hermiq_register.json` with a register version bump, because the import is gated on `info.version`. Everything else is imperative by nature: file access as a person, provider request shapes, a TaskProcessing call and a file tag are external integration work that hydra ADR-031 leaves imperative. No lifecycle, aggregation, notification or relation behaviour is added.

## Seed data

- An `AiFeature` object in the shape `SeedAiFeatures::seedFeatures()` uses (`lib/Repair/SeedAiFeatures.php:160-198`): `slug` `image-generation`, `name` "Create images in chat", `riskCategory` `limited`, `lifecycle` `disabled`, `description` "Creates an image from a description through the instance's text-to-image provider. Every image is saved in the requester's Files and tagged Agent authored."
- An example `hermiq.modelCapabilities` value in the admin documentation, not seeded: `{"anthropic/claude-sonnet-4-5": ["image", "pdf"], "ollama/llava:13b": ["image"], "ollama/qwen3:8b": []}`.
- One example session turn in the demo data: a Gemeente Tilburg case handler's message "Wat staat er in deze offerte over de oplevertermijn?" with `attachments: [{ "fileId": 48213, "name": "offerte-dakrenovatie-2026.pdf", "mimeType": "application/pdf", "size": 184233, "origin": "files" }]`.

## Risks

- LLPhant cannot carry content parts on a message. Mitigation: D5 routes those turns over the direct HTTP path the Anthropic and Fireworks branches already use; a PHPUnit test per driver asserts the request body shape.
- Large images blow the context window or the provider's size limit. Mitigation: images are downscaled to a maximum edge of 2048 pixels before sending, and the provider's own limit returns a readable error.
- The text fallback is slow on a large PDF. Mitigation: the extracted text is capped with the same budget as `readFile`, and the notice says the text was cut.
- An attachment leaks into a model outside the required residency. Mitigation: attachments go through the same `createChatDriver()` path as the turn, so residency and model policy refuse before the call.
