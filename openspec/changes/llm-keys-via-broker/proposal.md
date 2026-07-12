# LLM keys via the credential broker

## Why

Hermiq held the OpenAI and Fireworks API keys — and, unlike every other app in the fleet,
**not even encrypted**. Both keys sat in cleartext inside the `hermiq.llm` JSON blob in
`oc_appconfig`: readable by anything that could read the database, printed verbatim by
`occ config:app:get hermiq llm`, and carried into every support dump that touches app
config. They were then handed to LLPhant, which pasted them into an `Authorization` header.

Pipelinq at least encrypted its PSP keys at rest. Hermiq did not encrypt at all. This is
the worst instance of the fleet-wide pattern the credential broker exists to end.

The blocker was that the broker's provider catalogue listed only github/gitlab/doffin, so
an OpenAI credential could not even be created. That shipped in **openregister #348**.

## What Changes

- **`BrokerHttpClient`** is a PSR-18 client that proxies a call through the broker: it
  reduces the URI to a **path** (the host is the broker's host-lock), **strips** the
  `Authorization` header the library set, and fails closed with no broker and no
  credential. There is deliberately no direct fallback — falling back would mean falling
  back to an app-held key.

- **OpenAI is brokered without rewriting LLPhant.** `OpenAIConfig` accepts a pre-built
  `OpenAI\Client`, and `OpenAI::factory()` accepts any PSR-18 client. Hand it a
  `BrokerHttpClient` and every call the library makes is transparently proxied — no fork,
  no vendor patch. openai-php requires *some* key, so it gets a clearly-labelled
  placeholder that the client strips before the call.

- **Fireworks** swaps its raw cURL for a broker call. Its `$apiKey` parameter — and
  `ChatDriver::$apiKey` — are renamed to `$credentialId`. That field used to carry the
  **raw Fireworks key**, which meant every handler that touched a `ChatDriver` was holding
  a live secret; a field named `apiKey` that holds a UUID is a bug waiting to happen.

- **The keys are deleted.** `credentialId` replaces `apiKey` in config; a submitted
  `apiKey` is refused rather than persisted; `get()` strips a legacy one defensively so an
  un-repaired instance can never echo it to the browser; and `RemoveLegacyLlmKeys` rewrites
  the blob without them.

- **The admin UI** picks a credential instead of taking a key.

### What deliberately does NOT move

**Ollama** is self-hosted and takes no key — nothing to broker. A **custom Fireworks
`baseUrl`** pointing somewhere other than `api.fireworks.ai` cannot be host-locked from an
immutable catalogue, so it is not brokerable; that needs per-install, admin-approved
provider registration, which is a broker design change.

## Impact

- Affected specs: `agent-engine-port`
- Affected code: `lib/Service/Llm/{BrokerHttpClient,ProviderFactory,ChatDriver,LlmSettingsHandler}.php`,
  `lib/Controller/Settings/LlmSettingsController.php`, `lib/Repair/RemoveLegacyLlmKeys.php`,
  `lib/Service/Engine/{ResponseGenerationHandler,ConversationManagementHandler}.php`,
  `src/modals/LlmProviderModal.vue`
- **Breaking**: OpenAI and Fireworks each need a broker credential selected before they can
  serve a request. Until then the driver throws `ProviderUnavailableException` (503) with an
  explanatory message. Ollama and the Nextcloud Assistant provider are unaffected.
- Requires OpenRegister with the broker and the `openai` / `fireworks` entries from #348.
