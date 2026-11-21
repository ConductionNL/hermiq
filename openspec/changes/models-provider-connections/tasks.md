# Tasks: models-provider-connections

Kind: code. Size L. Rows `hermiq:mo-openai-compat`, `hermiq:dm-responses-api`, `hermiq:dm-subscription-login`.

## Implementation tasks

### Task 1: The openai-compatible provider and the OpenAI base URL
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-an-admin-can-connect-an-openai-compatible-endpoint-by-name-req-pconn-001`
- **files**: `lib/Service/Llm/LlmSettingsHandler.php`, `lib/Service/Llm/ProviderFactory.php` (instantiateChatDriver, a createOpenAiCompatibleDriver), `lib/Settings/hermiq_register.json` (ModelPolicy provider enum), `lib/Service/AiFeature/ProviderResidencyRegistry.php`
- **acceptance_criteria**:
  - GIVEN openai-compatible with a keyless base URL WHEN an agent runs THEN the base URL answers and the model policy and residency apply to it
  - GIVEN an OpenAI provider with no base URL WHEN it runs THEN calls go to api.openai.com as before
- [x] Implement
      > 2026-11-13: `openai-compatible` in `ALLOWED_CHAT_PROVIDERS`, `openaiCompatibleConfig` (label, baseUrl, chatModel, credentialId, ownNetwork) and `openaiConfig.baseUrl`/`ownNetwork` in the settings shape; `ProviderFactory::createOpenAiCompatibleDriver()`; a keyless base URL is called directly after the egress check (private address and plain HTTP only with `ownNetwork`), a credential keeps the broker path; the ModelPolicy and AiFeature provider enums accept it (register 0.53.0); residency and data-use declarations take it through the shared provider list; the connection report names it by its label.
- [x] Test (PHPUnit on the driver and the settings merge)
      > `ProviderFactoryOpenAiCompatibleTest` (9), `LlmConnectionReportNamingTest` (+2), `LlmSettingsHandlerTest` shape and merge.

### Task 2: Transport by credential, and an honest Fireworks form
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-chosen-host-is-reached-without-hermiq-holding-a-key-req-pconn-002`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `src/credentials.json`, `src/modals/LlmProviderModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a credential set WHEN a call is made THEN it goes through BrokerHttpClient and no key is read by hermiq
  - GIVEN no OpenAI-compatible credential type in OpenRegister WHEN the admin opens the form THEN the credential field is disabled with the stated text
  - GIVEN the Fireworks form WHEN a credential is chosen THEN the host is read-only and the field says API path
- [x] Implement
      > 2026-11-14: the provider modal lists "OpenAI-compatible endpoint" with name, base URL, model, an optional credential and "This is a server on our own network"; the credential field stays disabled with "A key needs OpenRegister's OpenAI-compatible credential type." until a credential of that provider is listed. OpenAI gains an optional base URL. The Fireworks form shows the host read-only and edits only the API path. `src/credentials.json` declares `openai-compatible` and `openai-cli`.
- [ ] Test (PHPUnit on transport choice; Playwright under tests/e2e/spec-coverage/ on the modal)
      > PHPUnit on transport choice is in `ProviderFactoryOpenAiCompatibleTest` (Task 1); the modal in `tests/vitest/llm-provider-modal*.spec.js`. Playwright needs a running Nextcloud and is left open.

### Task 3: Responses API mode
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004`
- **files**: `lib/Service/Llm/ProviderFactory.php` (callOpenAiResponses), `lib/Service/Engine/ResponseGenerationHandler.php`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN apiMode responses WHEN a turn runs THEN the request goes to /responses with store false, and each function call runs through the governed executor
  - GIVEN a function call for an ungranted tool WHEN it arrives THEN it is refused like on the other paths
- [x] Implement
      > 2026-11-14: `apiMode` (`chat_completions` default, `responses`) on `openaiConfig` and `openaiCompatibleConfig`, carried on the ChatDriver. `OpenAiResponsesLoop` maps the system blocks to `instructions`, the history to `input` (attachments as input images and files), the tools to function tools, sends `store: false`, runs every `function_call` through the governed executor the Anthropic path uses and returns its result as `function_call_output`, up to the agent's tool call cap; hosted tools and other output are refused with a clear error. `ProviderFactory::callOpenAiResponses()` posts to `{base}/responses` through the broker with a credential and straight to the base URL without one; the chat turn, background generation and conversation titles all follow the mode. The modal has an "API mode" choice for both providers.
- [ ] Test (PHPUnit on mapping both ways and the tool loop; one live OpenAI check)
      > PHPUnit done: `OpenAiResponsesLoopTest` (9) and `ProviderFactoryResponsesTest` (5). The live OpenAI check needs an account and is left open.

### Task 4: The runner's sign-in route
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001`
- **files**: `exapp/llm-runner/src/server.js`, `exapp/llm-runner/src/login.js`, `exapp/llm-runner/src/providers.js`, `exapp/llm-runner/test/`
- **acceptance_criteria**:
  - GIVEN POST /login for anthropic WHEN the CLI prints its URL THEN the runner returns a login id and the URL
  - GIVEN a code on /login/{id}/code WHEN the CLI completes THEN the token is returned once and the home directory is gone
  - GIVEN a login that is not completed WHEN ten minutes pass THEN the sign-in hosts leave the egress allowlist
- [x] Implement
      > 2026-11-14: `exapp/llm-runner/src/login.js` runs the provider's sign-in command in a fresh throwaway home with the sign-in's own run token as its proxy identity; `GET /login` lists the providers that offer a sign-in (Codex only when its pinned CLI's `login --help` names `--device-auth`), `POST /login` answers a login id, the URL and for a device sign-in the code, `POST /login/{id}/code` passes the code on STDIN, waits for the CLI and returns the token once, then removes the home. An open sign-in is killed after ten minutes; the run token hermiq mints for it expires at the same moment, so the sign-in hosts leave the egress policy with it. `providers.js` declares each provider's sign-in (command, mode, token pattern or auth file, hosts).
- [x] Test (runner tests with a stub CLI)
      > `test/login.test.js` (11), wired into `test/test.sh` as (m).

### Task 5: Sign-in in hermiq, token straight to the broker
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002`
- **files**: `lib/Controller/SubscriptionLoginController.php`, `lib/Service/Credential/SubscriptionLoginService.php`, `appinfo/routes.php`, `src/components/settings/SubscriptionSignIn.vue`, `src/modals/SubscriptionSignInModal.vue`
- **acceptance_criteria**:
  - GIVEN a completed sign-in WHEN hermiq answers the browser THEN only the credential id and label are returned and nothing is logged
  - GIVEN no runner WHEN the settings open THEN the button is replaced by the stated text
- [x] Implement
      > 2026-11-14: `SubscriptionLoginService` mints a SIGN-IN run token (ten minutes, `egressHosts` = the vendor's sign-in hosts) and starts the runner's sign-in; the finish passes the code on, receives the token once and mints it through OpenRegister's `CredentialBrokerService::mintCredential()` as the session user's personal credential for hermiq only, then spends the sign-in token. The browser gets `{id, label}`; the token is in no object, config value, log line or answer. Only the user who started a sign-in can finish it. `EgressAuthorizeController` admits a sign-in token to its own hosts on 443 and nothing else; `McpRunController` refuses it. `SubscriptionLoginController` (`GET/POST /api/subscription-login`, `POST /api/subscription-login/{id}/code`, `#[NoAdminRequired]`, session user only). In the app's own settings dialog, beside Credentials, `SubscriptionSignIn.vue` shows a button per offered sign-in, or "Subscription sign-in needs the Hermiq LLM runner app."; `SubscriptionSignInModal.vue` walks it. The admin provider modal offers no sign-in.
- [ ] Test (PHPUnit with a stubbed runner and broker and a capturing logger; Playwright under tests/e2e/spec-coverage/ for the no-runner state)
      > PHPUnit done: `SubscriptionLoginServiceTest` (10), `SubscriptionLoginControllerTest` (3), sign-in cases in `RunTokenServiceTest`, `EgressAuthorizeControllerTest` and `McpRunControllerTest`; vitest `subscription-sign-in.spec.js` (6) covers the no-runner state. Playwright needs a running Nextcloud and is left open.

### Task 6: Personal scope, and the ChatGPT sign-in
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-chatgpt-subscription-can-sign-in-where-the-codex-cli-allows-it-req-signin-004`
- **files**: `lib/Service/Credential/SubscriptionLoginService.php`, `exapp/llm-runner/src/providers.js` (auth file for codex), `src/credentials.json`
- **acceptance_criteria**:
  - GIVEN any sign-in WHEN the credential is created THEN its scope is personal and the admin modal offers no sign-in
  - GIVEN a pinned Codex CLI with device sign-in WHEN a user signs in THEN a personal openai-cli credential is stored and a cli turn uses it through a 0600 auth file in the throwaway home
- [x] Implement
      > 2026-11-14: every sign-in credential is minted with scope `personal` (fixed in the service, never taken from input); the admin modal offers no sign-in. The ChatGPT sign-in runs `codex login --device-auth`, offered only when the runner's pinned Codex CLI names that flag in its help; it stores a personal `openai-cli` credential (declared in `src/credentials.json`). The runner's OpenAI adapter writes a `CODEX_AUTH_JSON` credential into the turn's throwaway home as `.codex/auth.json`, mode 0600, never into the environment. Not in this task's files and left for a follow-up: hermiq has no `cli` execution mode on its OpenAI provider yet, so nothing dispatches an OpenAI turn to the runner with that credential.
- [x] Test (PHPUnit; runner tests with a stub Codex CLI)
      > `SubscriptionLoginServiceTest` (ChatGPT case, scope assertions); runner `test/login.test.js` (device sign-in with a stub Codex CLI, the 0600 auth file).

## Verification
- [ ] `openspec validate models-provider-connections --type change --strict` passes
- [ ] PHPUnit, runner tests and Playwright run once before push, exit codes read
- [ ] One live walk-through of the Claude sign-in on a test subscription, recorded in the PR
