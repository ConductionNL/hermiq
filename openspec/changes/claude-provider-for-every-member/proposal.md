---
kind: code
---

# Proposal: claude-provider-for-every-member

## Summary

Before hermiq goes to the App Store, an admin must be able to store ONE Anthropic API key and have it
serve everyone it is meant for: every member of the organisation chatting in hermiq, and every
Nextcloud Assistant task that hermiq answers. Today it serves only the person who stored it, and
never a background task.

Three defects, found by reading the code and confirmed on a local instance (pipelinq review,
round 5):

1. **Background calls carry no user.** Nextcloud runs Assistant tasks (`core:text2text`,
   `core:text2text:summary`, `core:text2text:headline`, `core:contextagent:interaction`) from cron
   in `SynchronousBackgroundJob`, with no session. Hermiq's providers receive the task's `$userId`,
   but the Anthropic path asks the broker with `actingUserId = currentUid()`, which is null there.
   The broker then refuses a personal credential ("unauthenticated") and an organisation credential
   alike.
2. **Only the key's owner can pick it, and only they can use a personal one.** The AI provider
   dialog lists `GET /apps/openregister/api/credentials`, which returns the caller's personal
   credentials only. An organisation credential could be set only by a hand-written PATCH. A
   personal key set as the instance key gives every other user "caller is not the credential
   owner". The Anthropic driver also skipped the personal then organisation lookup that OpenAI and
   Fireworks already do.
3. **Assistant chat lands on defect 1.** Assistant 3.5.0 routes every chat message to
   `core:contextagent:interaction` when that task type has a provider. Hermiq provides it, so with
   hermiq installed Assistant chat runs hermiq's agent engine from cron and fails on defect 1.

## What changes

- `ProviderFactory::actingFor(userId, work)` runs one unit of work on behalf of a named user.
  Inside it, `currentUid()` answers that user when there is NO session; a session always wins.
  Every broker call already forwards `currentUid()` as `actingUserId`, so the task's user reaches
  the broker on every provider path (Anthropic http, OpenAI, Fireworks, the credential lookup).
- `ProviderFactory::generateText()` runs inside `actingFor($userId)`, so the three text2text
  providers act for the task's user. `ContextAgentInteractionService::interact()` runs the engine
  turn inside `actingFor($userId)`, so Assistant chat does too.
- The Anthropic driver (API key over http) resolves a personal then organisation credential through
  `CredentialScopeResolver`, exactly as OpenAI and Fireworks do, before falling back to the
  configured instance credential. The OAuth and CLI modes keep the configured credential: those
  carry a personal subscription that must never be shared.
- The AI provider dialog lists the caller's personal credentials AND their active organisation's
  credentials (`?scope=organisation`), marking the second kind "(organisation)", so an admin can
  pick an organisation key in the UI.

The trust decision stays in OpenRegister: the broker admits a sessionless call for an organisation
credential only when the named user is an enabled member of the credential's organisation, and a
personal credential only for its owner (openregister change `broker-acts-for-an-organisation-member`).
Hermiq only says who the task belongs to, and the only source of that value is Nextcloud's
TaskProcessing manager handing a provider the task's stored user.

## Out of scope

- An instance-wide credential scope (credentials stay personal or organisation).
- Changing Assistant's routing to `core:contextagent:interaction`.
- Offering Anthropic in the per-agent credential pin picker.

## Impact

- `lib/Service/Llm/ProviderFactory.php`
- `lib/Service/ContextAgentInteractionService.php`
- `src/modals/LlmProviderModal.vue`, new `src/utils/llmCredentials.js`, `l10n/en.json`, `l10n/nl.json`
- Tests: `tests/Unit/Service/Llm/ProviderFactoryActingForTest.php`,
  `tests/Unit/Service/ContextAgent/ContextAgentInteractionServiceTest.php`,
  `tests/llm-credentials.spec.js`
