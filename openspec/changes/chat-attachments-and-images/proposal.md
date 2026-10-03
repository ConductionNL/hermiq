---
kind: code
---

# Proposal: chat-attachments-and-images

## Summary

You can attach a file to a chat message, either uploaded from your device into your own Files or picked from Files, and ask the agent about it. An image or a PDF goes to the model as an image or document part when the model reads that kind natively, and when it does not, hermiq reads the text out of the file and tells you it did. You can ask for an image, from the chat or through an agent tool, and the image lands in your Files marked as agent-authored. The attach button the AI companion already shows stops failing, because hermiq finally answers the route it calls.

## Why

Four rows of hermiq's capability matrix, decided in the OpenSpec pass of 2026-09-27.

| row | own rating | decision |
|---|---|---|
| `hermiq:ch-attach` | no | build: core area (chat); the companion attach control posts to `/api/chat/attachments`, which hermiq never registers (hermiq#950) |
| `hermiq:dm-image-chat` | no | build: changelog demand and five competitors rate yes |
| `hermiq:ch-image-gen` | no | build: core area (chat); four competitors rate yes |
| `hermiq:dm-native-pdf` | no | build: changelog demand and two competitors rate yes; the PDF case of the same attachment pipeline |

Demand rows:

- `dm-image-chat`: changelog https://github.com/nextcloud/assistant/pull/602
- `dm-native-pdf`: changelog https://github.com/n8n-io/n8n/releases/tag/n8n%402.28.0

Competitor cells rated yes, quoted from the matrix:

- `ch-attach`, Nextcloud Assistant: "attach button with 'Upload from device' and 'Select from nextcloud' file picker (assistant:src/components/ChattyLLM/InputArea.vue:160-173); attachments passed as file ids to the multimodal agent (assistant:lib/Service/ChatService.php:402-408, context_agent:ex_app/lib/agent.py:220-224)."
- `dm-image-chat`, Nextcloud Assistant: "attachments go to the multimodal agent as file parts (context_agent:ex_app/lib/nc_model.py:58-68, agent.py:222-224) and output_attachments are saved on the answer (assistant:lib/Listener/ChattyLLMTaskListener.php:135-140, context_agent:ex_app/lib/agent.py:296-297)."
- `dm-image-chat`, Hermes agent: "gateway/run_turn.py:2115 inbound user images are auto-analysed and passed with their local path; tools/vision_tools.py:787,858 vision_analyze tool; tools/image_generation_tool.py:417,554 image_generate tool".
- `dm-image-chat`, Dify: "api/core/app/app_config/features/file_upload/manager.py:8,21-27 image upload with vision detail setting; web/app/components/base/chat/chat/answer/index.tsx:273-283 answers render generated files and message_files".
- `dm-image-chat`, n8n: "packages/cli/src/modules/agents/agent-chat-attachment.service.ts:1-32 stores inbound image attachments, rendered by frontend features/agents/components/AgentChatMessageAttachments.vue:42-57,103".
- `dm-image-chat`, Open WebUI: "src/lib/components/workspace/Models/Capabilities.svelte:13 vision capability for image input; backend/open_webui/routers/images.py:562 /generations and :858 /edit".
- `ch-image-gen`, Nextcloud Assistant: "text-to-image smart picker (assistant:lib/Reference/Text2ImageReferenceProvider.php:30), Files 'New' menu image dialog (assistant:src/components/FilesNewMenu/GenerateImageDialog.vue), and agent tool generate_image (context_agent:ex_app/lib/all_tools/image_gen.py:13-14)."
- `ch-image-gen`, Hermes agent: "tools/image_generation_tool.py:1-5 image generation tool (FAL models chosen in `hermes tools`); agent/image_gen_registry.py pluggable providers with plugins/image_gen/".
- `ch-image-gen`, n8n: "packages/@n8n/nodes-langchain/nodes/vendors/OpenAi/v2/actions/image/generate.operation.ts image generation operation".
- `ch-image-gen`, Open WebUI: "backend/open_webui/routers/images.py:562 POST /images/generations, :858 /images/edit (OpenAI, Gemini, ComfyUI, Automatic1111); built-in tool backend/open_webui/tools/builtin.py:370 generate_image".
- `dm-native-pdf`, Dify: "api/tests/unit_tests/core/agent/test_fc_agent_runner.py:324 a PDF becomes DocumentPromptMessageContent; api/tests/unit_tests/core/workflow/nodes/llm/test_llm_utils.py:884 content is kept or filtered by the model's DOCUMENT feature".
- `dm-native-pdf`, n8n: "packages/@n8n/nodes-langchain/nodes/agents/Agent/agents/ToolsAgent/options.ts:43-48 'Automatically Passthrough Binary PDFs' on the AI Agent node for models with native PDF input, e.g. Gemini".

## What hermiq already has

- The chat send and stream routes, `POST /api/chat/send` and `POST /api/chat/stream` (`appinfo/routes.php:514`, `:545`). No `/api/chat/attachments` route exists anywhere in `appinfo/routes.php`.
- `ChatController::extractMessageRequestParams()` (`lib/Controller/ChatController.php:809`) reads `conversation`, `agentUuid`, `message`, `views`, `tools`, the RAG flags and `context`. It reads no attachments.
- `Engine::processMessage()` (`lib/Service/Engine/Engine.php:262`) takes the user message as one string, and `ResponseGenerationHandler` appends it as `LLPhantMessage::user($userMessage)` (`lib/Service/Engine/ResponseGenerationHandler.php:363`). The Anthropic mapping casts every turn to a string (`lib/Service/Llm/ProviderFactory.php:2049`).
- The `SessionTurn` schema (`agentsessionturn`, `lib/Settings/hermiq_register.json:1659`) carries `content`, `sources` and `context`, and no attachment field.
- The Chat page composer is a textarea and a send button (`src/views/Chat.vue:414-450`).
- `hermiq.readFile` reads the text of a file in the acting user's folder, capped at 20000 bytes (`lib/Mcp/HermiqToolProvider.php:186`, `:822-850`).
- `AgentArtefactMarker::markFile()` tags a file "Agent authored" and throws when it cannot (`lib/Service/NcNative/AgentArtefactMarker.php:122`).
- `FeatureProviderResolver::enforceForRun()` refuses a document that filinq has not redacted when the AI feature requires redaction (`lib/Service/AiFeature/FeatureProviderResolver.php:183`, `:262`), reached from `ProviderFactory::createChatDriver()` (`lib/Service/Llm/ProviderFactory.php:587`, `:639`).
- hermiq registers TaskProcessing providers for audio, speech, text and the context agent, and none for images (`lib/AppInfo/Application.php:348-353`).
- The companion's side of the contract lives in nextcloud-vue: `CnAiInput` uploads the picked file as multipart field `file` to `/apps/{chatAppId}/api/chat/attachments` and expects `{ path, name }` back, and the send and stream bodies then carry `attachments` (`src/composables/aiChatConfig.js:86-96`, `src/composables/useAiChatStream.js:297-349`, nextcloud-vue development at ca0c56e77).

## What this change builds

1. `POST /api/chat/attachments`: the upload the companion already calls. The file lands in the caller's own Files, and the answer carries its file id.
2. Pick from Files on the Chat page, next to upload from device.
3. An `attachments` list on a session turn, holding file references only, read as the person who sent the turn.
4. A declared input capability per provider and model (`image`, `pdf`), set by an administrator next to provider residency.
5. Image and PDF parts sent natively to a model that declares the capability, on the OpenAI, Anthropic, Ollama and Fireworks paths.
6. A fallback for a model without the capability: the file's text is used instead, and the answer says so.
7. Image generation through Nextcloud TaskProcessing text-to-image, as the tool `hermiq.generateImage` and as a "Create an image" action in the chat, the result saved in Files, marked as agent-authored and shown in the answer.

## Out of scope

- Rendering attachments and generated images inside the floating companion. The companion's message list is nextcloud-vue's (`CnAiMessageList`), and it needs its own change there. hermiq returns the data in the `final` frame so that change has something to render.
- An image model of hermiq's own. Images come from whatever TaskProcessing text-to-image provider the instance has, such as the OpenAI integration app or a local diffusion ExApp.
- Editing an existing image. Only generation from a description is in this change.
- Knowledge bases built from many files. That is `memory-knowledge-bases`.
