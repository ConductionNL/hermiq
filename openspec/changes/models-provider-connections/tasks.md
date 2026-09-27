# Tasks: models-provider-connections

Kind: code. Size L. Rows `hermiq:mo-openai-compat`, `hermiq:dm-responses-api`, `hermiq:dm-subscription-login`.

## Implementation tasks

### Task 1: The openai-compatible provider and the OpenAI base URL
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-an-admin-can-connect-an-openai-compatible-endpoint-by-name-req-pconn-001`
- **files**: `lib/Service/Llm/LlmSettingsHandler.php`, `lib/Service/Llm/ProviderFactory.php` (instantiateChatDriver, a createOpenAiCompatibleDriver), `lib/Settings/hermiq_register.json` (ModelPolicy provider enum), `lib/Service/AiFeature/ProviderResidencyRegistry.php`
- **acceptance_criteria**:
  - GIVEN openai-compatible with a keyless base URL WHEN an agent runs THEN the base URL answers and the model policy and residency apply to it
  - GIVEN an OpenAI provider with no base URL WHEN it runs THEN calls go to api.openai.com as before
- [ ] Implement
- [ ] Test (PHPUnit on the driver and the settings merge)

### Task 2: Transport by credential, and an honest Fireworks form
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-chosen-host-is-reached-without-hermiq-holding-a-key-req-pconn-002`
- **files**: `lib/Service/Llm/ProviderFactory.php`, `src/credentials.json`, `src/modals/LlmProviderModal.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a credential set WHEN a call is made THEN it goes through BrokerHttpClient and no key is read by hermiq
  - GIVEN no OpenAI-compatible credential type in OpenRegister WHEN the admin opens the form THEN the credential field is disabled with the stated text
  - GIVEN the Fireworks form WHEN a credential is chosen THEN the host is read-only and the field says API path
- [ ] Implement
- [ ] Test (PHPUnit on transport choice; Playwright under tests/e2e/spec-coverage/ on the modal)

### Task 3: Responses API mode
- **spec_ref**: `openspec/changes/models-provider-connections/specs/llm-provider-connections/spec.md#requirement-a-provider-can-run-in-responses-api-mode-with-governed-tools-req-pconn-004`
- **files**: `lib/Service/Llm/ProviderFactory.php` (callOpenAiResponses), `lib/Service/Engine/ResponseGenerationHandler.php`, `src/modals/LlmProviderModal.vue`
- **acceptance_criteria**:
  - GIVEN apiMode responses WHEN a turn runs THEN the request goes to /responses with store false, and each function call runs through the governed executor
  - GIVEN a function call for an ungranted tool WHEN it arrives THEN it is refused like on the other paths
- [ ] Implement
- [ ] Test (PHPUnit on mapping both ways and the tool loop; one live OpenAI check)

### Task 4: The runner's sign-in route
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-user-can-sign-in-with-a-claude-subscription-req-signin-001`
- **files**: `exapp/llm-runner/src/server.js`, `exapp/llm-runner/src/login.js`, `exapp/llm-runner/src/providers.js`, `exapp/llm-runner/test/`
- **acceptance_criteria**:
  - GIVEN POST /login for anthropic WHEN the CLI prints its URL THEN the runner returns a login id and the URL
  - GIVEN a code on /login/{id}/code WHEN the CLI completes THEN the token is returned once and the home directory is gone
  - GIVEN a login that is not completed WHEN ten minutes pass THEN the sign-in hosts leave the egress allowlist
- [ ] Implement
- [ ] Test (runner tests with a stub CLI)

### Task 5: Sign-in in hermiq, token straight to the broker
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-signed-in-token-is-kept-only-in-the-broker-req-signin-002`
- **files**: `lib/Controller/SubscriptionLoginController.php`, `lib/Service/Credential/SubscriptionLoginService.php`, `appinfo/routes.php`, `src/components/settings/SubscriptionSignIn.vue`, `src/modals/SubscriptionSignInModal.vue`
- **acceptance_criteria**:
  - GIVEN a completed sign-in WHEN hermiq answers the browser THEN only the credential id and label are returned and nothing is logged
  - GIVEN no runner WHEN the settings open THEN the button is replaced by the stated text
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed runner and broker and a capturing logger; Playwright under tests/e2e/spec-coverage/ for the no-runner state)

### Task 6: Personal scope, and the ChatGPT sign-in
- **spec_ref**: `openspec/changes/models-provider-connections/specs/agent-credentials/spec.md#requirement-a-chatgpt-subscription-can-sign-in-where-the-codex-cli-allows-it-req-signin-004`
- **files**: `lib/Service/Credential/SubscriptionLoginService.php`, `exapp/llm-runner/src/providers.js` (auth file for codex), `src/credentials.json`
- **acceptance_criteria**:
  - GIVEN any sign-in WHEN the credential is created THEN its scope is personal and the admin modal offers no sign-in
  - GIVEN a pinned Codex CLI with device sign-in WHEN a user signs in THEN a personal openai-cli credential is stored and a cli turn uses it through a 0600 auth file in the throwaway home
- [ ] Implement
- [ ] Test (PHPUnit; runner tests with a stub Codex CLI)

## Verification
- [ ] `openspec validate models-provider-connections --type change --strict` passes
- [ ] PHPUnit, runner tests and Playwright run once before push, exit codes read
- [ ] One live walk-through of the Claude sign-in on a test subscription, recorded in the PR
