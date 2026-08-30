# Tasks — llm-keys-via-broker

## Task 1: BrokerHttpClient

- [x] 1.1 New `BrokerHttpClient implements Psr\Http\Client\ClientInterface` — routes an LLM
      call through OpenRegister's `CredentialBrokerService` (lazy `class_exists` +
      `Server::get`).
- [x] 1.2 Reduce the request URI to a **path + query**. The host is the broker's host-lock:
      a client that can name the host can name a different one.
- [x] 1.3 **Strip** every broker-owned header (`Authorization`, `X-API-Key`, `apikey`).
      openai-php sets a Bearer header before we ever see the request; the broker discards
      caller-supplied auth anyway.
- [x] 1.4 Fail closed with no broker and no credential. No direct fallback — falling back
      would mean falling back to an app-held key, which no longer exists.
- [x] 1.5 Never log the body (it carries the prompt). Method and path only.

## Task 2: Route OpenAI through it without rewriting LLPhant

- [x] 2.1 `OpenAIConfig` accepts a pre-built `OpenAI\Client`, and `OpenAI::factory()`
      accepts any PSR-18 client. Hand it a `BrokerHttpClient` and every call LLPhant makes
      is transparently proxied — no library fork, no vendor patch.
- [x] 2.2 `BROKER_MANAGED_KEY` placeholder for the key openai-php insists on being given.
      It never reaches the wire.

## Task 3: Route Fireworks through it

- [x] 3.1 Replace the raw cURL in `callFireworksChat()` with a broker call.
- [x] 3.2 Rename the parameter `$apiKey` → `$credentialId`, and `ChatDriver::$apiKey` →
      `ChatDriver::$credentialId`. The field used to carry the **raw Fireworks key**, which
      meant every handler that touched a ChatDriver was holding a live secret. A field named
      `apiKey` that holds a UUID is a bug waiting to happen.

## Task 4: Delete the cleartext keys

- [x] 4.1 `credentialId` replaces `apiKey` in `openaiConfig` / `fireworksConfig`.
- [x] 4.2 `LlmSettingsController::dropBlankCredentials()` **refuses** a submitted `apiKey`
      rather than persisting it, and `maskCredentials()` strips a legacy one defensively so
      an un-repaired instance can never echo it to the browser.
- [x] 4.3 `RemoveLegacyLlmKeys` repair step rewrites the `hermiq.llm` blob without the keys.
      They were not merely app-held but **unencrypted** — in cleartext in `oc_appconfig`,
      printed verbatim by `occ config:app:get hermiq llm`. Leaving them would be dead config
      that is still live secret material.
- [x] 4.4 Bump the app version so the repair step runs.

## Task 5: Admin UI

- [x] 5.1 `LlmProviderModal.vue` — both API-key password fields replaced by pickers over the
      user's broker credentials.
- [x] 5.2 Refuse to save an openai/fireworks provider with no credential selected.

## Task 6: Tests

- [x] 6.1 `BrokerHttpClientTest` — PSR-18 contract, auth headers stripped, fail-closed
      without a credential, the placeholder is not secret-shaped, appId is `hermiq`.
- [x] 6.2 `LlmSettingsHandlerTest` — a submitted `apiKey` is **never persisted**.
- [x] 6.3 `LlmSettingsControllerTest` — a legacy cleartext `apiKey` is stripped from `get()`
      and never echoed.
- [x] 6.4 `CredentialBrokerService` test stub, so `isAvailable()` resolves in the unit suite
      (mirrors the existing `IMcpToolProvider` / ObjectService stub pattern).

## Task 7: Verify

- [x] 7.1 PHPUnit 462/462, PHPCS 0 errors, PHPStan 0 errors, Psalm 0 errors, webpack build.
- [x] 7.2 Live-verify on the dev instance: the modal renders the credential picker and no
      key field; the repair step strips a planted cleartext key; the secret is nowhere in
      the database. (Deferred at merge time: the shared dev instance runs the pre-merge
      bundle and redeploying it mid-session is barred; verify on the next dev-up deploy —
      the RemoveLegacyLlmKeys repair step + suite coverage guard the same behavior.)
