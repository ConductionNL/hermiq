---
kind: code
---

# Proposal: use-nextcloud-ai-settings

## Why

Ruben decided on 10 October 2026 that hermiq uses Nextcloud's own AI settings. An
administrator enters the Claude key once, under Administration settings > Artificial
intelligence, through a provider app (integration_openai pointed at Anthropic's
OpenAI-compatible endpoint `https://api.anthropic.com/v1`). Hermiq should not hold its
own key for that path and should not call Anthropic itself.

Today hermiq does the opposite in three ways:

1. Its `nextcloud` chat provider is limited to background text. `ResponseGenerationHandler`
   refuses it for chat with "serves background work only", so an agent with tools cannot
   run on Nextcloud AI at all.
2. With no hermiq-specific provider saved, every LLM call fails with "Chat provider is not
   configured", even when the instance has a working AI provider.
3. Hermiq registers itself as a TaskProcessing provider for `core:text2text`,
   `core:text2text:summary` and `core:text2text:headline`. Once hermiq sends its own work
   to TaskProcessing, an admin who prefers hermiq for `core:text2text` would route hermiq's
   request back to hermiq: a loop. In Nextcloud AI mode those three providers cannot work
   anyway, because they forbid the `nextcloud` driver to avoid exactly that recursion.

## What changes

- A "Nextcloud AI" mode (`chatProvider: nextcloud`) becomes the default when no
  hermiq-specific provider is configured (`chatProvider` empty). The stored config is not
  rewritten; the default is resolved at read time by `LlmSettingsHandler::effectiveChatProvider()`.
- In that mode every hermiq LLM call goes through `OCP\TaskProcessing\IManager` as the
  acting user, via a new `NextcloudTaskClient`:
  - chat without tools: `core:text2text:chat`;
  - agent turns with tools: `core:text2text:chatwithtools`, with hermiq running the tool
    loop and every tool call still going through its governed `FunctionInfo` path;
  - plain generation (titles, summaries, skill jobs, translations): `core:text2text`.
- Sync and async: when the preferred provider is an `ISynchronousProvider`, the client uses
  `IManager::runTask()`, which runs the task inline. Otherwise it schedules the task and
  waits with a bounded timeout (120 s for chat, 600 s for background work), cancels the task
  on timeout and throws a clear error. `runTask()` itself is not used for an asynchronous
  provider because it polls without a bound.
- When no Nextcloud provider serves the needed task type, hermiq throws
  `ProviderUnavailableException` with the admin message "Configure an AI provider under
  Administration settings > Artificial intelligence." No silent fallback to another provider.
- If the preferred provider for a type hermiq consumes is hermiq itself, the client refuses
  instead of looping.
- Hermiq stops registering `Text2TextProvider`, `Text2TextSummaryProvider` and
  `Text2TextHeadlineProvider`; the classes are removed. `ContextAgentProvider`
  (`core:contextagent:interaction`) stays: hermiq consumes chat, chatwithtools and
  text2text, never contextagent:interaction, so it does not consume a type it provides.
  The audio providers are untouched (hermiq consumes no audio task type).
- The admin settings page explains that hermiq uses Nextcloud's AI settings, links there,
  and shows per task type whether a provider is ready. The duplicated "Organisation
  credentials" heading and its broken copy (mentions Doriath, has em-dashes) are removed;
  `CnCredentials` already renders its own heading and description.
- The chat health probe reports `unconfigured` when Nextcloud AI is the effective provider
  but no provider serves `core:text2text:chat`.
- The direct providers (openai, ollama, fireworks, anthropic) keep compiling and working
  when selected. They are no longer the default.

## Out of scope

- The organisation credential broker work (openregister #4548, hermiq #1146) is dropped
  for this round.
- Streaming tokens over Nextcloud AI: TaskProcessing has no streaming surface, so the SSE
  path receives the whole answer at once, as it already does for Anthropic.
- Embeddings stay on the directly configured embedding provider.

## Impact

- `lib/Service/Llm/NextcloudTaskClient.php` (new), `ProviderFactory.php`,
  `LlmSettingsHandler.php`, `lib/Service/Engine/ResponseGenerationHandler.php`,
  `lib/AppInfo/Application.php`, `lib/Controller/ChatHealthController.php`,
  `lib/Controller/Settings/LlmSettingsController.php`, `lib/TaskProcessing/*` (three
  providers removed), `src/views/AdminRoot.vue`, `src/modals/LlmProviderModal.vue`, `l10n/`.
